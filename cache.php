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
 * [[key,valAllocSize,valSize,value],...]
 *
 *     'key' is the original key string. If 'key' is NULL, that value slot is
 *     free. The ring buffer pointer points to the oldest value slot.
 *
 *     SHM_CACHE_ITEM_META_SIZE = sizeof(key) + sizeof(valAllocSize) + sizeof(valSize)
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
  const MIN_VALUE_SIZE = 64;
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
        $this->mergeItemWithNextFreeValueSlots( $existingItem[ 'offset' ] );
        $existingItem = null;
      }
    }

    if ( !$existingItem ) {
      $oldestKeyPointer = $this->getRingBufferPointer();
      if ( $oldestKeyPointer <= 0 )
        goto error;
      $replacedItem = $this->getItemMetaByOffset( $oldestKeyPointer );
      if ( !$replacedItem )
        goto error;
    }

    if ( $newValueSize > self::MAX_VALUE_SIZE ) {
      trigger_error( 'Given cache item "'. $key .'" is too large to be stored into the cache' );
      goto error;
    }

    // When the maximum amount of cached items is reached, and we're adding
    // a new item (rather than just writing into an existing item's memory
    // space) we start removing the oldest items. We don't remove just one,
    // but multiple at once, so that any calls to set() afterwards will not
    // immediately have to remove an item again.
    if ( !$existingItem && $this->getItemCount() >= self::MAX_ITEMS ) {

      $itemsToRemove = self::FULL_CACHE_REMOVED_ITEMS;
      $removedOffset = $replacedItem[ 'offset' ];

      while ( $itemsToRemove > 0 ) {
        $removedItem = $this->getItemMetaByOffset( $removedOffset );
        if ( !$removedItem )
          goto error;
        if ( !$removedItem[ 'free' ] ) {
          if ( !$this->removeItem( $removedItem ) )
            goto error;
          --$itemsToRemove;
        }
        $removedOffset = $removedItem[ 'nextoffset' ];
        // Loop around if we reached the last item in the values memory area
        if ( !$removedOffset )
          $removedOffset = SHM_CACHE_VALUES_START;
        // If we reach the offset the we start at, we've seen all the elements
        if ( $removedOffset === $replacedItem[ 'offset' ] )
          break;
      }

      $this->mergeItemWithNextFreeValueSlots( $replacedItem[ 'offset' ] );
      // Looped around to the start of the values memory area
      if ( $removedOffset <= $replacedItem[ 'offset' ] )
        $this->mergeItemWithNextFreeValueSlots( SHM_CACHE_VALUES_START );
    }

    $itemOffset = $replacedItem[ 'offset' ];
    $nextOffset = $replacedItem[ 'nextoffset' ];
    $allocatedSize = $replacedItem[ 'valallocsize' ];

    // The new value doesn't fit into an existing cache item. Make space for the
    // new value by merging next oldest cache items one by one into the current
    // cache item, until we have enough space.
    while ( $allocatedSize < $newValueSize ) {

      // Loop around if we reached the end of the values area
      if ( !$nextOffset ) {
        // We'll free the old item at the end of the values area, as we don't
        // wrap items around. Each item is a contiguous run of memory.
        $this->removeItem( $replacedItem );
        $this->mergeItemWithNextFreeValueSlots( $replacedItem[ 'offset' ] );
        $firstItem = $this->getItemMetaByOffset( SHM_CACHE_VALUES_START );
        if ( !$firstItem )
          goto error;
        $itemOffset = SHM_CACHE_VALUES_START;
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

    // TODO
  }

  function delete( $key ) {

    $key = (string) $key;

    if ( !$this->lock() )
      return false;

    $item = $this->getItemMetaByKey( $key );

    if ( !$item ) {
      $ret = false;
    }
    else {
      $ret = $this->removeItem( $item );
      $this->mergeItemWithNextFreeValueSlots( $item[ 'offset' ] );
    }

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
   * Returns an index in the key hash table, to the first element of the
   * hash table item cluster (i.e. bucket) for the given key.
   *
   * The hash function must result in as uniform as possible a distribution
   * of bits over all the possible $key values. CRC32 looks pretty good:
   * http://michiel.buddingh.eu/distribution-of-hash-values
   *
   * @return int
   */
  private function getHashTableBaseIndex( $key ) {

    $hash = hash( 'crc32', $key, true );

    // Read as a 32-bit unsigned long
    $index = unpack( 'L', $hash )[ 1 ];
    // Modulo by the amount of hash table slots to get an array index
    $index %= SHM_CACHE_KEYS_SLOTS;

    return $index;
  }

  /**
   * Returns an index in the key hash table, to the element whose key matches
   * the given key exactly.
   */
  private function getHashTableIndex( $key ) {

    $index = $this->getHashTableBaseIndex( $key );
    $metaOffset = $this->getItemMetaOffsetByHashTableIndex( $index );

    for ( $i = 0; $metaOffset && $i < SHM_CACHE_KEYS_SLOTS; ++$i ) {

      $item = $this->getItemMetaByOffset( $metaOffset );
      if ( $item[ 'key' ] === $key )
        return $index;

      $index = ( $index + 1 ) % SHM_CACHE_KEYS_SLOTS;
      $metaOffset = $this->getItemMetaOffsetByHashTableIndex( $index );
    }

    return -1;
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
    if ( $item[ 'free' ] ) {
      trigger_error( 'Tried to remove an already free\'d item "'. $item[ 'key' ] .'"' );
      return false;
    }

    $hashTableIndex = $this->getHashTableIndex( $item[ 'key' ] );

    // Key doesn't exist in the hash table. This item was probably already
    // removed, or never existed.
    if ( $hashTableIndex < 0 ) {
      trigger_error( 'Item "'. $item[ 'key' ] .'" has no element in the hash table' );
      return false;
    }

    // Clear the hash table key
    if ( !$this->writeItemKey( $hashTableIndex, 0 ) ) {
      trigger_error( 'Could not free the item "'. $item[ 'key' ] .'" key' );
      return false;
    }

    // Clear the value's string key (i.e. the key passed to set())
    if ( shmop_write( $this->block, pack( 'x'. self::MAX_KEY_LENGTH ), $item[ 'offset' ] ) === false ) {
      trigger_error( 'Could not free the item "'. $item[ 'key' ] .'" value' );
      return false;
    }

    // After we've removed the item, we have an empty slot in the hash table.
    // This would prevent our logic from finding any items that hash to the
    // same base index as $item['key'], so we need to fill the gap by moving
    // all following contiguous table items to the left by one slot.

    $nextHashTableIndex = ( $hashTableIndex + 1 ) % SHM_CACHE_KEYS_SLOTS;

    for ( $i = 0; $i < SHM_CACHE_KEYS_SLOTS; ++$i ) {
      $data = shmop_read( $this->block, SHM_CACHE_KEYS_START + $nextHashTableIndex * SHM_CACHE_LONG_SIZE, SHM_CACHE_LONG_SIZE );
      $nextItemMetaOffset = unpack( 'l', $data )[ 1 ];
      // Reached an empty hash table slot
      if ( $nextItemMetaOffset === 0 )
        break;
      $nextItem = $this->getItemMetaByOffset( $nextItemMetaOffset );
      $this->writeItemKey( $nextHashTableIndex, 0 );
      $this->addItemKey( $nextItem[ 'key' ], $nextItemMetaOffset );
      $nextHashTableIndex = ( $nextHashTableIndex + 1 ) % SHM_CACHE_KEYS_SLOTS;
    }

    return $this->setItemCount( $this->getItemCount() - 1 );
  }

  private function mergeItemWithNextFreeValueSlots( $itemOffset ) {

    $item = $this->getItemMetaByOffset( $itemOffset );
    $nextOffset = $item[ 'nextoffset' ];
    $allocSize = $item[ 'valallocsize' ];

    while ( $nextOffset ) {
      $nextItem = $this->getItemMetaByOffset( $nextOffset );
      if ( !$nextItem[ 'free' ] )
        break;
      $nextOffset = $nextItem[ 'nextoffset' ];
      $allocSize += SHM_CACHE_ITEM_META_SIZE + $nextItem[ 'valallocsize' ];
    }

    if ( $allocSize !== $item[ 'valallocsize' ] ) {
      $this->writeItemMeta( $item[ 'offset' ], null, $allocSize );
    }
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

  private function writeItemMeta( $offset, $key = null, $valueAllocatedSize = null, $valueSize = null ) {

    if ( $offset < SHM_CACHE_VALUES_START ||
      $offset + SHM_CACHE_ITEM_META_SIZE + $valueAllocatedSize > SHM_CACHE_VALUES_START + SHM_CACHE_VALUES_SIZE )
    {
      trigger_error( 'Invalid offset "'. $offset .'"' );
      return false;
    }

    $data = '';
    $writeOffset = $offset;

    if ( $key !== null )
      $data .= pack( 'A'. self::MAX_KEY_LENGTH, substr( $key, 0, self::MAX_KEY_LENGTH ) );
    else
      $writeOffset += self::MAX_KEY_LENGTH;

    if ( $valueAllocatedSize !== null )
      $data .= pack( 'l', $valueAllocatedSize );
    else
      $writeOffset += SHM_CACHE_LONG_SIZE;

    if ( $valueSize !== null )
      $data .= pack( 'l', $valueSize );

    if ( shmop_write( $this->block, $data, $writeOffset ) === false ) {
      trigger_error( 'Could not write cache item metadata' );
      return false;
    }

    return true;
  }

  private function getItemMetaOffsetByHashTableIndex( $index ) {

    $data = shmop_read( $this->block, SHM_CACHE_KEYS_START + $index * SHM_CACHE_LONG_SIZE, SHM_CACHE_LONG_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not read item metadata offset from hash table index "'. $index .'"' );
      return false;
    }

    return unpack( 'l', $data )[ 1 ];
  }

  private function getItemMetaByKey( $key ) {

    $index = $this->getHashTableIndex( $key );
    if ( $index < 0 )
      return false;

    $metaOffset = $this->getItemMetaOffsetByHashTableIndex( $index );
    if ( $metaOffset <= 0 )
      return false;

    $item = $this->getItemMetaByOffset( $metaOffset );
    if ( !$item )
      return false;

    return $item;
  }

  private function getItemMetaByOffset( $offset ) {

    if ( $offset < SHM_CACHE_VALUES_START || $offset >= SHM_CACHE_VALUES_START + SHM_CACHE_VALUES_SIZE ) {
      trigger_error( 'Invalid offset '. $offset );
      return null;
    }

    $data = shmop_read( $this->block, $offset, SHM_CACHE_ITEM_META_SIZE );

    if ( !$data ) {
      trigger_error( 'Could not read item metadata at offset '. $offset );
      return null;
    }

    static $unpackFormat = 'A'. self::MAX_KEY_LENGTH .'key/lvalallocsize/lvalsize';

    $unpacked = unpack( $unpackFormat, $data );
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

    $i = 0;
    $index = $this->getHashTableBaseIndex( $key );

    // Find first empty hash table slot in this cluster (i.e. bucket)
    do {

      $hashTableOffset = SHM_CACHE_KEYS_START + $index * SHM_CACHE_LONG_SIZE;
      $data = shmop_read( $this->block, $hashTableOffset, SHM_CACHE_LONG_SIZE );

      if ( $data === false ) {
        trigger_error( 'Could not read hash table value' );
        return false;
      }

      $hashTableValue = unpack( 'l', $data )[ 1 ];
      if ( $hashTableValue === 0 )
        break;

      $index = ( $index + 1 ) % SHM_CACHE_KEYS_SLOTS;
    }
    while ( ++$i < SHM_CACHE_KEYS_SLOTS );

    return $this->writeItemKey( $index, $itemMetaOffset );
  }

  private function updateItemKey( $key, $itemMetaOffset ) {

    $hashTableIndex = $this->getHashTableIndex( $key );

    if ( $hashTableIndex < 0 ) {
      trigger_error( 'Could not get hash table offset for key "'. $key .'"' );
      return false;
    }

    return $this->writeItemKey( $hashTableIndex, $itemMetaOffset );
  }

  private function writeItemKey( $hashTableIndex, $itemMetaOffset ) {

    if ( $hashTableIndex < 0 || $hashTableIndex >= SHM_CACHE_KEYS_SLOTS ) {
      trigger_error( 'Invalid hash table offset "'. $hashTableIndex .'"' );
      return false;
    }

    if ( $itemMetaOffset && ( $itemMetaOffset < SHM_CACHE_VALUES_START ||
      $itemMetaOffset >= SHM_CACHE_VALUES_START + SHM_CACHE_VALUES_SIZE ) )
    {
      trigger_error( 'Invalid item offset "'. $itemMetaOffset .'"' );
      return false;
    }

    $hashTableOffset = SHM_CACHE_KEYS_START + $hashTableIndex * SHM_CACHE_LONG_SIZE;

    if ( shmop_write( $this->block, pack( 'l', $itemMetaOffset ), $hashTableOffset ) === false ) {
      trigger_error( 'Could not write item key' );
      return false;
    }

    return true;
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

    if ( $isNewBlock )
      $this->initializeMemBlock();
  }

  private function destroyMemBlock() {

    shmop_delete( $this->block );
    shmop_close( $this->block );
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
    if ( !$this->writeItemMeta( SHM_CACHE_VALUES_START, '', SHM_CACHE_VALUES_SIZE - SHM_CACHE_ITEM_META_SIZE, 0 ) )
      trigger_error( 'Could not create initial cache item' );
  }

  function dumpStats() {

    echo 'Available space for values: '. floor( SHM_CACHE_VALUES_SIZE / 1024 / 1024 ) .' MB'. PHP_EOL;
    echo 'Ring buffer pointer: '. $this->getRingBufferPointer() . PHP_EOL;
    echo 'Hash table clusters:'. PHP_EOL;

    for ( $i = SHM_CACHE_KEYS_START; $i < SHM_CACHE_KEYS_START + SHM_CACHE_KEYS_SIZE; $i += SHM_CACHE_LONG_SIZE ) {
      if ( unpack( 'l', shmop_read( $this->block, $i, SHM_CACHE_LONG_SIZE ) )[ 1 ] !== 0 )
        echo 'X';
      else
        echo '.';
    }

    echo PHP_EOL;
    echo PHP_EOL;
    echo 'Items in cache: '. $this->getItemCount() . PHP_EOL;

    $itemNr = 1;

    for ( $i = SHM_CACHE_VALUES_START; $i < SHM_CACHE_VALUES_START + SHM_CACHE_VALUES_SIZE; ) {

      $item = $this->getItemMetaByOffset( $i );

      echo '  ['. $itemNr++ .'] '. $item[ 'key' ] .' (hashidx: '. $this->getHashTableIndex( $item[ 'key' ] ) .', offset: '. $item[ 'offset' ] .', nextoffset: '. $item[ 'nextoffset' ] .
        ', valallocsize: '. $item[ 'valallocsize' ] .', valsize: '. $item[ 'valsize' ] .')'. PHP_EOL;

      if ( $item[ 'free' ] )
        echo '    [Free space]';
      else
        echo '    "'. substr( shmop_read( $this->block, $item[ 'offset' ] + SHM_CACHE_ITEM_META_SIZE,
          $item[ 'valsize' ] ), 0, 60 ) .'"';

      echo PHP_EOL;

      $i += SHM_CACHE_ITEM_META_SIZE + $item[ 'valallocsize' ];
    }

    echo PHP_EOL;
  }
}


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$cache = new ShmCache();
$memcached = new Memcached();
$memcached->addServer( 'localhost', 11211 );

if ( ( @$argc > 1 && $argv[ 1 ] === 'clear' ) || isset( $_REQUEST[ 'clear' ] ) ) {
  if ( $memcached->flush() && $cache->deleteAll() )
    echo 'Deleted all'. PHP_EOL;
  else
    echo 'ERROR: Failed to delete all'. PHP_EOL;
}

$itemsToCreate = 2000;
$totalSetTimeShm = 0;
$totalSetTimeMemcached = 0;

for ( $i = 0; $i < $itemsToCreate; ++$i ) {

  echo 'Set foobar'. $i . PHP_EOL;
  $valuePre = rand();
  $valuePost = str_repeat( 'x', 1000 );

  $start = microtime( true );
  if ( !$cache->set( 'foobar'. $i, $valuePre .' '. $valuePost ) ) {
    echo 'ERROR: Failed setting ShmCache value foobar'. $i . PHP_EOL;
    break;
  }
  $end = ( microtime( true ) - $start );
  echo 'ShmCache took '. $end .' s'. PHP_EOL;
  $totalSetTimeShm += $end;

  $start2 = microtime( true );
  if ( !$memcached->set( 'foobar'. $i, $valuePre .' '. $valuePost ) ) {
    echo 'ERROR: Failed setting Memcached value foobar'. $i . PHP_EOL;
    break;
  }
  $end2 = ( microtime( true ) - $start2 );
  echo 'Memcached took '. $end2 .' s'. PHP_EOL;
  $totalSetTimeMemcached += $end2;
}

$start = ( ShmCache::MAX_ITEMS >= $itemsToCreate )
  ? 0
  : $itemsToCreate - ShmCache::MAX_ITEMS;
$totalGetTimeShm = 0;
$totalGetTimeMemcached = 0;

for ( $i = $start; $i < $itemsToCreate; ++$i ) {

  echo 'Get '. $i . PHP_EOL;

  $start = microtime( true );
  if ( !$cache->get( 'foobar'. $i ) ) {
    echo 'ERROR: Failed getting ShmCache value foobar'. $i . PHP_EOL;
    break;
  }
  $end = ( microtime( true ) - $start );
  echo 'ShmCache took '. $end .' s'. PHP_EOL;
  $totalGetTimeShm += $end;

  $start2 = microtime( true );
  if ( !$memcached->get( 'foobar'. $i ) ) {
    echo 'ERROR: Failed getting Memcached value foobar'. $i . PHP_EOL;
    break;
  }
  $end2 = ( microtime( true ) - $start2 );
  echo 'Memcached took '. $end2 .' s'. PHP_EOL;
  $totalGetTimeMemcached += $end2;
}

echo PHP_EOL;
echo '----------------------------------------------'. PHP_EOL;
echo 'Total set:'. PHP_EOL;
echo 'ShmCache:  '. $totalSetTimeShm .' s'. PHP_EOL;
echo 'Memcached: '. $totalSetTimeMemcached .' s'. PHP_EOL . PHP_EOL;

echo 'Total get:'. PHP_EOL;
echo 'ShmCache:  '. $totalGetTimeShm .' s'. PHP_EOL;
echo 'Memcached: '. $totalGetTimeMemcached .' s'. PHP_EOL;
echo '----------------------------------------------'. PHP_EOL . PHP_EOL;

$value = $cache->get( 'foobar'. ( $itemsToCreate - 1 ) );
echo 'Old value: '. var_export( $value, true ) . PHP_EOL;
$num = ( $value ) ? intval( $value ) : 0;

if ( !$cache->set( 'foobar'. ( $itemsToCreate - 1 ), ( $num + 1 ) .' foo' ) )
  echo 'Failed setting value'. PHP_EOL;

$value = $cache->get( 'foobar'. ( $itemsToCreate - 1 ) );
echo 'New value: '. var_export( $value, true ) . PHP_EOL;

//echo '---------------------------------------'. PHP_EOL;
//echo 'Debug:'. PHP_EOL;
//$cache->dumpStats();

