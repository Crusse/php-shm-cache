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
 * Keys area:
 * [itemMetaOffset,itemMetaOffset,...]
 *
 *     The keys area is a hash table of SHM_CACHE_KEYS_SLOTS items. Our hash
 *     table implementation uses linear probing, so this table is ever filled
 *     up to MAX_LOAD_FACTOR.
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
  const MAX_ITEMS = 50;
  const FULL_CACHE_REMOVED_ITEMS = 10;
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
    define( 'SHM_CACHE_FIFO_AREA_START', 0 );
    define( 'SHM_CACHE_FIFO_AREA_SIZE', $itemCountSize + $ringBufferPtrSize );

    // Keys area (i.e. cache item hash table)
    define( 'SHM_CACHE_KEYS_SLOTS', ceil( self::MAX_ITEMS / self::MAX_LOAD_FACTOR ) );
    define( 'SHM_CACHE_KEYS_START', SHM_CACHE_FIFO_AREA_START + SHM_CACHE_FIFO_AREA_SIZE + self::SAFE_AREA_SIZE );
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

    if ( !$this->lock() )
      goto error;

    $key = (string) $key;
    $value = serialize( $value );

    $existingItem = $this->getItemMetaByKey( $key );
    $newValueSize = strlen( $value );

    if ( $existingItem ) {
      // There's enough space for the new value in the existing item's value
      // memory area spot
      if ( $newValueSize <= $existingItem[ 'valallocsize' ] ) {
        $replacedItem = $existingItem;
      }
      // The new value is too large to fit into the existing item's spot, and
      // would overwrite 1 or more items to the right of it. We'll instead
      // remove the existing item, and handle this as a new value, so that this
      // item will replace 1 or more of the _oldest_ items (that are pointed to
      // by the ring buffer pointer).
      else {
        $this->removeItem( $existingItem );
        $existingItem = null;
      }
    }

    if ( !$existingItem ) {
      $oldestKeyPointer = $this->getRingBufferPointer();
      if ( !$oldestKeyPointer )
        goto error;
      $replacedItem = $this->getItemMetaByOffset( $oldestKeyPointer );
      if ( !$replacedItem )
        goto error;
    }

    if ( $newValueSize > self::MAX_VALUE_SIZE ) {
      trigger_error( 'Given cache item "'. $key .'" is too large to be stored in the cache' );
      goto error;
    }

    $itemOffset = $replacedItem[ 'offset' ];
    $nextOffset = $replacedItem[ 'nextoffset' ];
    $allocatedSize = $replacedItem[ 'valallocsize' ];

    // When the maximum amount of cached items is reached, we start removing
    // the oldest items. We don't remove just one, but multiple at once, so
    // that any calls to set() afterwards will not immediately have to remove
    // an item again.
    $extraItemsToRemove = 0;
    if ( !$existingItem && $this->getItemCount() >= self::MAX_ITEMS - 1 )
      $extraItemsToRemove = self::FULL_CACHE_REMOVED_ITEMS;

    // The new value doesn't fit into an existing cache item. Make space for the
    // new value by merging next oldest cache items one by one into the current
    // cache item, until we have enough space.
    while ( $allocatedSize < $newValueSize || $extraItemsToRemove-- > 0 ) {

      // Loop around if we reached the end of the values area
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
      $nextOffset = $splitSlotOffset;
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

    if ( !$existingItem ) {

      $this->setItemCount( $this->getItemCount() + 1 );

      $newBufferPtr = ( $nextOffset )
        ? $nextOffset
        : SHM_CACHE_VALUES_START;

      if ( !$this->setRingBufferPointer( $newBufferPtr ) )
        goto error;
    }

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
      $ret = unserialize( shmop_read( $this->block, $item[ 'offset' ] + SHM_CACHE_ITEM_META_SIZE, $item[ 'valsize' ] ) );

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

    // Read as a 32-bit unsigned long
    $index = unpack( 'L', $hash )[ 1 ];
    // Modulo by the amount of hash table slots to get an array index
    $index %= SHM_CACHE_KEYS_SLOTS;

    return $index;
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

    echo 'Remove '. $item[ 'key' ] . PHP_EOL;

    // Item is already free'd
    if ( $item[ 'free' ] )
      return false;

    $hashTableOffset = $this->getHashTableOffset( $item[ 'key' ] );

    // Key doesn't exist in the hash table. This item was probably already
    // removed, or never existed.
    if ( !$hashTableOffset )
      return false;

    // Clear the hash table key
    if ( !$this->writeItemKey( $hashTableOffset, 0 ) ) {
      trigger_error( 'Could not free the item "'. $item[ 'key' ] .'" key' );
      return false;
    }

    // Clear the value's string key (i.e. the key passed to set())
    if ( shmop_write( $this->block, pack( 'x250' ), $item[ 'offset' ] ) === false ) {
      trigger_error( 'Could not free the item "'. $item[ 'key' ] .'" value' );
      return false;
    }

    // Get the last key in this contiguous chain of keys, and move it to fill
    // the spot left by removing this key. All the hash table's items for
    // a specific $item['key'] must form a contiguous run of memory.

    $lastHashTableOffset = 0;
    $lastItemMetaOffset = 0;
    $nextHashTableOffset = $hashTableOffset;

    for ( $i = 0; $i < SHM_CACHE_KEYS_SLOTS; ++$i ) {
      $nextHashTableOffset = ( $nextHashTableOffset + SHM_CACHE_LONG_SIZE ) % SHM_CACHE_KEYS_SLOTS;
      $data = shmop_read( $this->block, $nextHashTableOffset, SHM_CACHE_LONG_SIZE );
      $itemMetaOffset = unpack( 'l', $data )[ 1 ];
      // Found a null hash table item
      if ( $itemMetaOffset === 0 )
        break;
      $lastItemMetaOffset = $itemMetaOffset;
      $lastHashTableOffset = $nextOffset;
    }

    if ( !$lastHashTableOffset || !$lastItemMetaOffset ) {
      trigger_error( 'Could not find the last item in the hash table cluster for item "'. $item[ 'key' ] .'"' );
      return false;
    }

    if ( !$this->writeItemKey( $lastHashTableOffset, 0 ) )
      return false;
    if ( !$this->writeItemKey( $hashTableOffset, $lastItemMetaOffset ) )
      return false;

    return $this->setItemCount( $this->getItemCount() - 1 );
  }

  private function getItemCount() {

    $data = shmop_read( $this->block, SHM_CACHE_FIFO_AREA_START, SHM_CACHE_LONG_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not read the item count' );
      return null;
    }

    return unpack( 'l', $data )[ 1 ];
  }

  private function setItemCount( $count ) {

    if ( $count < 0 ) {
      trigger_error( 'Item count must be 0 or larger' );
      return;
    }

    if ( shmop_write( $this->block, pack( 'l', $count ), SHM_CACHE_FIFO_AREA_START ) === false ) {
      trigger_error( 'Could not write the item count' );
      return false;
    }

    return true;
  }

  private function getRingBufferPointer() {

    $oldestKeyPointer = unpack( 'l', shmop_read( $this->block,
      SHM_CACHE_FIFO_AREA_START + SHM_CACHE_LONG_SIZE, SHM_CACHE_LONG_SIZE ) )[ 1 ];

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

  private function setRingBufferPointer( $itemMetaOffset ) {

    if ( shmop_write( $this->block, pack( 'l', $itemMetaOffset ), SHM_CACHE_FIFO_AREA_START + SHM_CACHE_LONG_SIZE ) === false ) {
      trigger_error( 'Could not write the ring buffer pointer' );
      return false;
    }

    return true;
  }

  private function writeItemMeta( $offset, $key, $valueAllocatedSize, $valueSize ) {

    $key = substr( $key, 0, self::MAX_KEY_LENGTH );
    $data = pack( 'A250', $key ) . pack( 'l', $valueAllocatedSize ) . pack( 'l', $valueSize );

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

    $index = $this->getHashIndex( $key );
    $offset = $this->getItemMetaOffsetByHashIndex( $index );

    if ( !$offset ) {
      $ret = null;
    }
    else {

      $item = $this->getItemMetaByOffset( $offset );
      $ret = null;

      while ( $item ) {
        if ( $item[ 'key' ] === $key ) {
          $ret = $item;
          break;
        }
        $index = ( $index + 1 ) % SHM_CACHE_KEYS_SLOTS;
        $offset = $this->getItemMetaOffsetByHashIndex( $index );
        if ( !$offset )
          break;
        $item = $this->getItemMetaByOffset( $offset );
      }
    }

    return $ret;
  }

  private function getItemMetaByOffset( $offset ) {

    $data = shmop_read( $this->block, $offset, SHM_CACHE_ITEM_META_SIZE );

    if ( !$data ) {
      trigger_error( 'Could not read item metadata at offset '. $offset );
      return null;
    }

    $unpacked = unpack( 'A'. self::MAX_KEY_LENGTH .'key/lvalallocsize/lvalsize', $data );
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
    $unpacked[ 'free' ] = ( $unpacked[ 'key' ] === "" );

    return $unpacked;
  }

  private function addItemKey( $key, $itemMetaOffset ) {

    $index = $this->getHashIndex( $key );

    do {

      $hashTableOffset = SHM_CACHE_KEYS_START + $index * SHM_CACHE_LONG_SIZE;
      $data = shmop_read( $this->block, $hashTableOffset, SHM_CACHE_LONG_SIZE );

      if ( $data === false ) {
        trigger_error( 'Could not read hash table value' );
        return false;
      }

      $index = ( $index + 1 ) % SHM_CACHE_KEYS_SLOTS;
    }
    while ( unpack( 'l', $data )[ 1 ] !== 0 );

    return $this->writeItemKey( $hashTableOffset, $itemMetaOffset );
  }

  private function updateItemKey( $key, $itemMetaOffset ) {

    echo 'Update'. PHP_EOL;
    $hashTableOffset = $this->getHashTableOffset( $key );

    if ( !$hashTableOffset ) {
      trigger_error( 'Could not get key offset for key "'. $key .'"' );
      return false;
    }

    return $this->writeItemKey( $hashTableOffset, $itemMetaOffset );
  }

  private function writeItemKey( $hashTableOffset, $itemMetaOffset ) {

    if ( shmop_write( $this->block, pack( 'l', $itemMetaOffset ), $hashTableOffset ) === false ) {
      trigger_error( 'Could not write item key' );
      return false;
    }

    return true;
  }

  private function getHashTableOffset( $key ) {

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
  private function initializeMemBlock() {

    // Clear all bytes in the shared memory block
    $memoryWriteChunk = 1024 * 1024 * 4;
    for ( $i = 0; $i < self::MEM_BLOCK_SIZE; $i += $memoryWriteChunk ) {

      // Last chunk might have to be smaller
      if ( $i + $memoryWriteChunk > self::MEM_BLOCK_SIZE )
        $memoryWriteChunk = self::MEM_BLOCK_SIZE - $i;

      if ( shmop_write( $this->block, pack( 'x'. $memoryWriteChunk ), $i ) === false )
        throw new \Exception( 'Could not write NUL bytes to the memory block' );
    }

    // The ring buffer pointer always points to the oldest cache item. In this
    // case it's the first key of the new memory block, which itself points to
    // free space of the values area.
    $this->setRingBufferPointer( SHM_CACHE_VALUES_START );
    $this->setItemCount( 0 );

    // Initialize first cache item
    if ( !$this->writeItemMeta( SHM_CACHE_VALUES_START, '', SHM_CACHE_VALUES_SIZE, 0 ) )
      trigger_error( 'Could not create initial cache item' );
  }

  function dumpStats() {

    echo 'Available space for values: '. floor( SHM_CACHE_VALUES_SIZE / 1024 / 1024 ) .' MB'. PHP_EOL;
    echo 'Ring buffer pointer: '. $this->getRingBufferPointer() . PHP_EOL;
    echo 'Items in cache: '. $this->getItemCount() . PHP_EOL;

    for ( $i = SHM_CACHE_VALUES_START; $i < SHM_CACHE_VALUES_START + SHM_CACHE_VALUES_SIZE; $i += SHM_CACHE_ITEM_META_SIZE ) {

      $item = $this->getItemMetaByOffset( $i );

      echo '  Item (offset: '. $item[ 'offset' ] .', nextoffset: '. $item[ 'nextoffset' ] .
        ', valallocsize: '. $item[ 'valallocsize' ] .', valsize: '. $item[ 'valsize' ] .')'. PHP_EOL;

      if ( $item[ 'free' ] )
        echo '    [Free space]';
      else
        echo '    "'. substr( shmop_read( $this->block, $item[ 'offset' ] + SHM_CACHE_ITEM_META_SIZE,
          $item[ 'valsize' ] ), 0, 60 ) .'"';

      echo PHP_EOL;

      $i += $item[ 'valallocsize' ];
    }

    echo PHP_EOL;
  }
}


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$cache = new ShmCache();
$cache->dumpStats();

if ( $argc > 1 && $argv[ 1 ] === 'clear' ) {
  if ( $cache->deleteAll() )
    echo 'Deleted all'. PHP_EOL;
  else
    echo 'ERROR: Failed to delete all'. PHP_EOL;
}

for ( $i = 0; $i < 2000; ++$i ) {

  echo 'Set foobar'. $i . PHP_EOL;

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
//$cache->dumpStats();

