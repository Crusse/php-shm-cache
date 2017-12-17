<?php

/**
 * A shared memory cache for storing data that is persisted across multiple PHP
 * script runs.
 * 
 * Features:
 * 
 * - FIFO queue: oldest element is evicted first when the cache is full
 * - Uses a hash table (with linear probing) for accessing items quickly
 * - Stores the hash table and items' values in UNIX shared memory
 * 
 * FIFO queue area:
 * [itemCount][ringBufferPointer]
 *
 *     The item count is the amount of currently stored items. It's used to
 *     keep the hash table load factor below MAX_LOAD_FACTOR.
 *
 *     The ring buffer pointer points to the oldest cache item's value slot.
 *     When the cache is full (MAX_ITEMS or MEM_BLOCK_SIZE has been reached),
 *     the oldest items are removed one by one when adding a new item, until
 *     there is enough space for the new item.
 * 
 * Keys area (MAX_ITEMS hash table slots):
 * [itemMetaOffset,itemMetaOffset,...]
 * 
 * Values area:
 * [[key,valSize,value],...]
 *
 *     'key' is the original key string. If 'key' is NULL, that value slot is
 *     free. The ring buffer pointer points to the oldest value slot.
 *
 *     The value slots are always in the order in which they were added to the
 *     cache, so that we can remove the oldest items when the cache gets full.
 *
 */
class ShmCache {

  // Total amount of space to allocate for the shared memory block. This will
  // contain both the keys area and the values area, so the amount allocatable
  // for values will be slightly smaller.
  const MEM_BLOCK_SIZE = 130000000;
  const SAFE_AREA_SIZE = 64;
  // Don't let value allocations become smaller than this, to reduce fragmentation
  const MIN_VALUE_SIZE = 32;
  const MAX_VALUE_SIZE = 1048576; // 1 MB
  // Hash table size should be a prime (_not_ a power of 2) to disperse keys evenly
  const MAX_ITEMS = 20011;
  // Use a low load factor (i.e. make there be many more slots in the hash
  // table than the maximum amount of items we'll be storing). 0.5 or less.
  const MAX_LOAD_FACTOR = 0.5;
  // If a key string is longer than this, the key is truncated silently
  const MAX_KEY_LENGTH = 250;

  private $block;
  private $semaphore;

  static private $hasLock = false;

  function __construct() {

    self::createDefines();

    $this->semaphore = $this->getSemaphore();

    if ( !$this->lock() )
      throw new \Exception( 'Could not get a lock' );

    $this->openMemBlock();

    $this->releaseLock();
  }

  function __destruct() {

    if ( $this->block )
      shmop_close( $this->block );
    if ( self::$hasLock )
      $this->releaseLock();
  }

  static private function createDefines() {

    if ( defined( 'SHM_CACHE_LONG_SIZE' ) )
      return;

    // We define these constants here, as PHP<5.6 doesn't accept arithmetic
    // expressions in class constants

    define( 'SHM_CACHE_LONG_SIZE', strlen( pack( 'l', 1 ) ) ); // i.e. sizeof(long) in C
    define( 'SHM_CACHE_CHAR_SIZE', strlen( pack( 'c', 1 ) ) ); // i.e. sizeof(char) in C

    // FIFO area
    $itemCountSize = SHM_CACHE_LONG_SIZE;
    $ringBufferPtrSize = SHM_CACHE_LONG_SIZE;
    define( 'SHM_CACHE_FIFO_AREA_SIZE', $itemCountSize + $ringBufferPtrSize );

    // Keys area (i.e. cache item hash table)
    define( 'SHM_CACHE_KEYS_SLOTS', ceil( self::MAX_ITEMS / self::MAX_LOAD_FACTOR ) );
    define( 'SHM_CACHE_KEYS_START', SHM_CACHE_FIFO_AREA_SIZE + self::SAFE_AREA_SIZE );
    // The hash table values are "pointers", i.e. offsets to the values area
    define( 'SHM_CACHE_KEYS_SIZE', SHM_CACHE_KEYS_SLOTS * SHM_CACHE_LONG_SIZE );

    // Values area
    define( 'SHM_CACHE_VALUES_START', SHM_CACHE_KEYS_START + SHM_CACHE_KEYS_SIZE + self::SAFE_AREA_SIZE );
    define( 'SHM_CACHE_VALUES_SIZE', self::MEM_BLOCK_SIZE - SHM_CACHE_VALUES_START );
    $keySize = self::MAX_KEY_LENGTH;
    $allocatedSize = SHM_CACHE_LONG_SIZE;
    $valueSize = SHM_CACHE_LONG_SIZE;
    define( 'SHM_CACHE_ITEM_META_SIZE', $keySize + $allocatedSize + $valueSize );
  }

  function set( $key, $value ) {

    $key = (string) $key;
    $value = (string) $value;

    if ( !$this->lock() )
      goto error;

    $existingItem = $this->getItemMetaByKey( $key );

    if ( $existingItem ) {
      $replacedItem = $existingItem;
    }
    else {
      $oldestKeyPointer = $this->getRingBufferPointer();
      if ( !$oldestKeyPointer )
        goto error;
      $replacedItem = $this->getItemMetaByOffset( $oldestKeyPointer );
      if ( !$replacedItem )
        goto error;
    }

    $newValueSize = strlen( $value );

    if ( $newValueSize > self::MAX_VALUE_SIZE ) {
      trigger_error( 'Given cache item "'. $key .'" is too large to be stored in the cache' );
      goto error;
    }

    $itemOffset = $replacedItem[ 'offset' ];
    $nextOffset = $replacedItem[ 'nextoffset' ];
    $allocatedSize = $replacedItem[ 'valallocsize' ];

    // The new value doesn't fit into an existing cache item. Make space for the
    // new value by merging next oldest cache items one by one into the current
    // cache item, until we have enough space.
    while ( $allocatedSize < $newValueSize ) {

      // Loop around if we reached the end of the cache keys memory area
      if ( !$nextOffset ) {
        // We'll free the old item at the end of the values area, as we don't
        // wrap items around. Each item is a contiguous run of memory.
        $this->removeItem( $replacedItem );
        $firstItem = $this->getItemMetaByOffset( SHM_CACHE_VALUES_START );
        if ( !$firstItem )
          goto error;
        $itemOffset = SHM_CACHE_KEYS_START;
        $nextOffset = $firstItem[ 'nextoffset' ];
        $allocatedSize = $firstItem[ 'valallocsize' ];
        continue;
      }

      $nextItem = $this->getItemMetaByOffset( $nextOffset );
      if ( !$nextItem )
        goto error;

      // Merge the next item's space into this item
      $allocatedSize += SHM_CACHE_ITEM_META_SIZE + $nextItem[ 'valallocsize' ];
      $nextOffset = $nextItem[ 'nextoffset' ];

      $this->removeItem( $nextItem );
    }

    // Split the cache item into two, if there is enough space left over
    if ( $allocatedSize - $newValueSize >= SHM_CACHE_ITEM_META_SIZE + self::MIN_VALUE_SIZE ) {

      $splitSlotSize = $allocatedSize - $newValueSize;
      $splitSlotOffset = $itemOffset + SHM_CACHE_ITEM_META_SIZE + $newValueSize;
      $splitItemValAllocSize = $splitSlotSize - SHM_CACHE_ITEM_META_SIZE;

      if ( !$this->writeItemMeta( $splitSlotOffset, '', $splitItemValAllocSize, 0 ) )
        goto error;

      $allocatedSize -= $splitSlotSize;
    }

    if ( !$this->writeItemMeta( $itemOffset, $key, $allocatedSize, $newValueSize ) )
      goto error;

    if ( !$this->writeItemValue( $itemOffset + SHM_CACHE_ITEM_META_SIZE, $value ) )
      goto error;

    if ( $existingItem ) {
      if ( !$this->updateItemKey( $key, $itemOffset ) )
        goto error;
    }
    else {
      if ( !$this->addItemKey( $key, $itemOffset ) )
        goto error;
    }

    $newBufferPtr = ( $nextOffset )
      ? $nextOffset
      : SHM_CACHE_VALUES_START;

    if ( !$this->setRingBufferPointer( $newBufferPtr ) )
      goto error;

    $this->setItemCount( $this->getItemCount() + 1 );

    $this->releaseLock();
    return true;


    error:
    $this->releaseLock();
    return false;
  }

  function get( $key ) {

    $key = (string) $key;

    if ( !$this->lock() )
      return false;

    $item = $this->getItemMetaByKey( $key );

    if ( !$item )
      $ret = false;
    else
      $ret = shmop_read( $this->block, $item[ 'offset' ], $item[ 'valsize' ] );

    $this->releaseLock();

    return $ret;
  }

  function exists( $key ) {

    
  }

  function delete( $key ) {

    $key = (string) $key;

    if ( !$this->lock() )
      return false;

    $item = $this->getItemMetaByKey( $key );

    if ( !$item )
      $ret = false;
    else
      $ret = $this->removeItem( $item );

    $this->releaseLock();

    return $ret;
  }

  function deleteAll() {

    if ( !$this->lock() )
      return false;

    try {
      $this->destroyMemBlock();
      $this->openMemBlock();
      $ret = true;
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
      $ret = false;
    }

    $this->releaseLock();

    return $ret;
  }

  /**
   * Returns an index in the key hash table.
   *
   * The hash function must result in as uniform as possible a distribution
   * of bits over all the possible $key values. CRC32 looks pretty good:
   * http://michiel.buddingh.eu/distribution-of-hash-values
   *
   * @return int
   */
  private function getHashIndex( $key ) {

    $hash = hash( 'crc32', $key, true );
    // Get rid of the sign bit
    $hash &= 0x7fffffff;
    // Modulo by the amount of hash table slots to get an array index
    $hash %= SHM_CACHE_KEYS_SLOTS;

    return $hash;
  }

  private function writeItemValue( $offset, $value ) {

    if ( shmop_write( $this->block, $value, $offset ) === false ) {
      trigger_error( 'Could not write item value' );
      return false;
    }

    return true;
  }

  /**
   * Remove the item hash table entry and clears its value (i.e. frees its
   * memory).
   */
  private function removeItem( $item ) {

    // Item is already free'd
    if ( $item[ 'free' ] )
      return false;

    $keyOffset = $this->getItemKeyOffset[ $item[ 'key' ] ];

    // Key doesn't exist in the hash table. This item was probably already
    // removed, or never existed.
    if ( !$keyOffset )
      return false;

    // Clear the hash table key
    if ( !$this->writeItemKey( $keyOffset, 0 ) ) {
      trigger_error( 'Could not free the item "'. $item[ 'key' ] .'" key' );
      return false;
    }

    // Clear the value's string key (i.e. the key passed to set())
    if ( shmop_write( $this->block, pack( 'x250' ), $item[ 'offset' ] ) === false ) {
      trigger_error( 'Could not free the item "'. $item[ 'key' ] .'" value' );
      return false;
    }

    return $this->setItemCount( $this->getItemCount() - 1 );
  }

  private function getItemCount() {

    $data = shmop_read( $this->block, 0, SHM_CACHE_LONG_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not read the item count' );
      return null;
    }

    return unpack( 'l', $data )[ 1 ];
  }

  private function setItemCount( $count ) {

    if ( shmop_write( $this->block, pack( 'l', $count ), 0 ) === false ) {
      trigger_error( 'Could not write the item count' );
      return false;
    }

    return true;
  }

  private function getRingBufferPointer() {

    $oldestKeyPointer = unpack( 'l', shmop_read( $this->block, SHM_CACHE_LONG_SIZE, SHM_CACHE_LONG_SIZE ) )[ 1 ];

    if ( !$oldestKeyPointer ) {
      trigger_error( 'Could not find the ring buffer pointer' );
      return null;
    }

    if ( $oldestKeyPointer < SHM_CACHE_VALUES_START || $oldestKeyPointer >= SHM_CACHE_VALUES_START + SHM_CACHE_VALUES_SIZE ) {
      trigger_error( 'The ring buffer pointer is out of bounds' );
      return null;
    }

    return $oldestKeyPointer;
  }

  private function setRingBufferPointer( $keyOffset ) {

    if ( shmop_write( $this->block, pack( 'l', $keyOffset ), SHM_CACHE_LONG_SIZE ) === false ) {
      trigger_error( 'Could not write the ring buffer pointer' );
      return false;
    }

    return true;
  }

  private function writeItemMeta( $offset, $key, $valueAllocatedSize, $valueSize ) {

    $data = pack( 'a250', $key ) . pack( 'l', $valueAllocatedSize ) . pack( 'l', $valueSize );

    if ( shmop_write( $this->block, $data, $offset ) === false ) {
      trigger_error( 'Could not write cache item metadata' );
      return false;
    }

    return true;
  }

  private function getItemMetaOffsetByHashIndex( $index ) {

    $data = shmop_read( $this->block, SHM_CACHE_KEYS_START + $index * SHM_CACHE_LONG_SIZE, SHM_CACHE_LONG_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not read item metadata offset from hash table index "'. $index .'"' );
      return false;
    }

    return unpack( 'l', $data )[ 1 ];
  }

  private function getItemMetaByKey( $key ) {

    $start = microtime( true );

    $index = $this->getHashIndex( $key );
    $offset = $this->getItemMetaOffsetByHashIndex( $index );
    $item = $this->getItemMetaByOffset( $offset );
    $ret = null;

    while ( $item ) {
      if ( $item[ 'key' ] === $key ) {
        $ret = $item;
        break;
      }
      $index = ( $index + 1 ) % SHM_CACHE_KEYS_SLOTS;
      $offset = $this->getItemMetaOffsetByHashIndex( $index );
      $item = $this->getItemMetaByOffset( $offset );
    }

    echo 'getItemMetaByKey '. ( microtime( true ) - $start ) .' s'. PHP_EOL;

    return $ret;
  }

  private function getItemMetaByOffset( $offset ) {

    $data = shmop_read( $this->block, $offset, SHM_CACHE_ITEM_META_SIZE );

    if ( !$data ) {
      trigger_error( 'Could not read item metadata at offset '. $offset );
      return null;
    }

    $unpacked = unpack( 'a'. self::MAX_KEY_LENGTH .'key/lvalallocsize/lvalsize', $data );
    $unpacked[ 'offset' ] = $offset;
    // If it's the last item in the values area, the next item's offset is 0,
    // which means "none"
    $nextItemOffset = $offset + SHM_CACHE_ITEM_META_SIZE + $unpacked[ 'valallocsize' ];
    if ( $nextItemOffset > SHM_CACHE_VALUES_START + SHM_CACHE_VALUES_SIZE -
      SHM_CACHE_ITEM_META_SIZE - self::MIN_VALUE_SIZE )
    {
      $nextItemOffset = 0;
    }
    $unpacked[ 'nextoffset' ] = $nextItemOffset;
    $unpacked[ 'free' ] = ( $unpacked[ 'key' ][ 0 ] === "\0" );

    return $unpacked;
  }

  private function addItemKey( $key, $itemMetaOffset ) {

    $index = $this->getHashIndex( $key );

    do {

      $keyOffset = SHM_CACHE_KEYS_START + $index * SHM_CACHE_LONG_SIZE;
      $data = shmop_read( $this->block, $keyOffset, SHM_CACHE_LONG_SIZE );

      if ( $data === false ) {
        trigger_error( 'Could not read hash table value' );
        return false;
      }

      $index = ( $index + 1 ) % SHM_CACHE_KEYS_SLOTS;
    }
    while ( unpack( 'l', $data ) !== 0 );

    return $this->writeItemKey( $keyOffset, $itemMetaOffset );
  }

  private function updateItemKey( $key, $itemMetaOffset ) {

    $keyOffset = $this->getItemKeyOffset( $key );

    if ( !$keyOffset ) {
      trigger_error( 'Could not get key offset for key "'. $key .'"' );
      return false;
    }

    return $this->writeItemKey( $keyOffset, $itemMetaOffset );
  }

  private function writeItemKey( $keyOffset, $itemMetaOffset ) {

    if ( shmop_write( $this->block, pack( 'l', $itemMetaOffset ), $keyOffset ) === false ) {
      trigger_error( 'Could not write item key' );
      return false;
    }

    return true;
  }

  private function getItemKeyOffset( $key ) {

    $index = $this->getHashIndex( $key );
    $offset = SHM_CACHE_KEYS_START + $index * SHM_CACHE_LONG_SIZE;
    $item = $this->getItemMetaByOffset( $offset );

    while ( $item ) {
      if ( $item[ 'key' ] === $key )
        return $offset;
      $index = ( $index + 1 ) % SHM_CACHE_KEYS_SLOTS;
      $offset = SHM_CACHE_KEYS_START + $index * SHM_CACHE_LONG_SIZE;
      $item = $this->getItemMetaByOffset( $offset );
    }

    return 0;
  }

  private function lock() {

    if ( self::$hasLock ) {
      trigger_error( 'Tried to acquire lock even though it is already acquired' );
      return true;
    }

    // TODO: use a timeout that supports PHP<5.6:
    // https://bugs.php.net/bug.php?id=39168
    $start = time();

    if ( !sem_acquire( $this->semaphore ) ) {
      trigger_error( 'Could not acquire semaphore lock' );
      $this->releaseLock();
      return false;
    }

    self::$hasLock = true;

    return true;
  }

  private function releaseLock() {

    $ret = false;

    if ( sem_release( $this->semaphore ) ) {
      $ret = true;
      self::$hasLock = false;
    }
    else {
      trigger_error( 'Could not release semaphore lock' );
    }

    return $ret;
  }

  private function getSemaphore() {

    $tmpFile = sys_get_temp_dir() .'/php-shm-cache-87b1dcf602a-semaphore.lock';
    touch( $tmpFile );
    chmod( $tmpFile, 0666 );

    return sem_get( fileinode( $tmpFile ), 1, 0666, 1 );
  }

  private function openMemBlock() {

    $tmpFile = sys_get_temp_dir() .'/php-shm-cache-87b1dcf602a.lock';
    touch( $tmpFile );
    chmod( $tmpFile, 0666 );
    $blockKey = fileinode( $tmpFile );

    if ( !$blockKey )
      throw new \InvalidArgumentException( 'Invalid shared memory block key' );

    $mode = 0666;
    $block = @shmop_open( $blockKey, "w", $mode, self::MEM_BLOCK_SIZE );
    $isNewBlock = false;

    if ( !$block ) {
      $block = shmop_open( $blockKey, "n", $mode, self::MEM_BLOCK_SIZE );
      $isNewBlock = true;
    }

    if ( !$block )
      throw new \Exception( 'Could not create a shared memory block' );

    $this->block = $block;
    $this->initializeMemBlock();
  }

  private function destroyMemBlock() {

    shmop_delete( $this->block );
    $this->block = null;
  }

  /**
   * Initialize the memory block with a single, large cache key to represent
   * free space.
   */
  private function initializeMemBlock( $block ) {

    // Clear all bytes in the shared memory block
    $memoryWriteChunk = 1024 * 1024 * 8;
    for ( $i = 0; $i < self::MEM_BLOCK_SIZE; $i += $memoryWriteChunk ) {

      // Last chunk might have to be smaller
      if ( $i + $memoryWriteChunk > self::MEM_BLOCK_SIZE )
        $memoryWriteChunk = self::MEM_BLOCK_SIZE - $i;

      if ( shmop_write( $block, pack( 'x'. $memoryWriteChunk ), $i ) === false )
        throw new \Exception( 'Could not write NUL bytes to the memory block' );
    }

    // The ring buffer pointer always points to the oldest cache item. In this
    // case it's the first key of the new memory block, which itself points to
    // free space of the values area.
    $this->setRingBufferPointer( SHM_CACHE_KEYS_START );

    // Initialize first cache item
    $isFree = true;
    $keyMd5 = pack( 'x16' );
    $nextKeyOffset = 0;
    $valueSize = SHM_CACHE_VALUES_SIZE;
    $valueOffset = SHM_CACHE_VALUES_START;

    if ( !$this->createCacheItem( $block, SHM_CACHE_KEYS_START, $keyMd5, $isFree, $nextKeyOffset, $valueSize, $valueOffset ) )
      trigger_error( 'Could not create initial cache item' );
  }

  function dumpKeyAreaDebug() {

    echo 'Ring buffer pointer: '. unpack( 'l', shmop_read( $this->block, 0, SHM_CACHE_KEYS_START ) )[ 1 ] . PHP_EOL;
    echo 'Items in cache:'. PHP_EOL;

    for ( $i = SHM_CACHE_KEYS_START; $i < SHM_CACHE_KEYS_START + SHM_CACHE_KEYS_SIZE; $i += SHM_CACHE_ITEM_META_SIZE ) {

      $item = $this->getItemMetaByOffset( $i );

      if ( $item && $item[ 'valsize' ] ) {
        echo '  Item (keyoffset: '. $item[ 'keyoffset' ] .', nextkeyoffset: '. $item[ 'nextkeyoffset' ] .', offset: '. $item[ 'offset' ] .', valsize: '. $item[ 'valsize' ] .')'. PHP_EOL;
        if ( $item[ 'free' ] )
          echo '    [Free space]';
        else
          echo '    "'. substr( shmop_read( $this->block, $item[ 'offset' ], $item[ 'valsize' ] ), 0, 60 ) .'"';
        echo PHP_EOL;
      }
    }

    echo PHP_EOL;
  }
}


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$cache = new ShmCache();

if ( $argc > 1 && $argv[ 1 ] === 'clear' ) {
  if ( $cache->deleteAll() )
    echo 'Deleted all'. PHP_EOL;
  else
    echo 'ERROR: Failed to delete all'. PHP_EOL;
}

for ( $i = 0; $i < 2000; ++$i ) {

  echo 'Set '. $i . PHP_EOL;

  $start = microtime( true );
  if ( !$cache->set( 'foobar'. $i, rand() .' '. str_repeat( 'x', 10 ) ) ) {
    echo 'ERROR: Failed setting ShmCache value '. $i . PHP_EOL;
    break;
  }
  echo 'ShmCache took '. ( microtime( true ) - $start ) .' s'. PHP_EOL;
}

for ( $i = 0; $i < 2000; ++$i ) {

  echo 'Get '. $i . PHP_EOL;

  $start = microtime( true );
  if ( !$cache->get( 'foobar'. $i ) ) {
    echo 'ERROR: Failed getting ShmCache value '. $i . PHP_EOL;
    break;
  }
  echo 'ShmCache took '. ( microtime( true ) - $start ) .' s'. PHP_EOL;
}

$value = $cache->get( 'foobar0' );
echo 'Old value: '. var_export( $value, true ) . PHP_EOL;
$num = ( $value ) ? intval( $value ) : 0;

//if ( !$cache->set( 'foobar0', ( $num + 1 ) .' foo' ) )
//  echo 'Failed setting value'. PHP_EOL;

echo '---------------------------------------'. PHP_EOL;
echo 'Debug:'. PHP_EOL;
//$cache->dumpKeyAreaDebug();

