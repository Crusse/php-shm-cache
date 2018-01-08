<?php

namespace Crusse;

/**
 * A shared memory cache for storing data that is persisted across multiple PHP
 * script runs.
 * 
 * Features:
 * 
 * - FIFO queue: oldest element is evicted first when the cache is full
 * - Uses a hash table (with linear probing) for accessing items quickly
 * - Stores the hash table and items' values in Unix shared memory
 *
 * The same memory block is shared by all instances of ShmCache. This means the
 * maximum amount of memory used by ShmCache is always DEFAULT_CACHE_SIZE (or
 * $desiredSize, if defined).
 *
 * You can use the Unix programs `ipcs` and `ipcrm` to list and remove the
 * memory block created by this class, if something goes wrong.
 *
 * It is important that the first instantiation and any further uses of this
 * class are with the same Unix user (e.g. 'www-data'), because the shared
 * memory block cannot be deleted (e.g. in destroy()) by another user, at least
 * on Linux. If you have problems deleting the memory block created by this
 * class, using `ipcrm` as root is your best bet.
 *
 *
 * Memory block structure
 * ----------------------
 *
 * Metadata area:
 * [itemcount][ringbufferpointer][gethits][getmisses]
 *
 *     The item count is the amount of currently stored items. It's used to
 *     keep the hash table load factor below MAX_LOAD_FACTOR.
 *
 *     The ring buffer pointer points to the oldest cache item's value slot.
 *     When the cache is full (MAX_ITEMS or VALUES_SIZE has been reached), the
 *     oldest items are removed one by one when adding a new item, until there
 *     is enough space for the new item.
 * 
 * Keys area:
 * [itemmetaoffset,itemmetaoffset,...]
 *
 *     The keys area is a hash table of KEYS_SLOTS items. Our hash table
 *     implementation uses linear probing, so this table is ever filled up to
 *     MAX_LOAD_FACTOR.
 * 
 * Values area:
 * [[key,valallocsize,valsize,flags,value],...]
 *
 *     'key' is the original key string.
 *
 *     If 'valsize' is 0, that value slot is free.
 *
 *     The ring buffer pointer points to the oldest value slot.
 *
 *     ITEM_META_SIZE = sizeof(key) + sizeof(valallocsize) + sizeof(valsize) + sizeof(flags)
 *
 *     The value slots are always in the order in which they were added to the
 *     cache, so that we can remove the oldest items when the cache gets full.
 *
 */
class ShmCache {

  // Total amount of space to allocate for the shared memory block. This will
  // contain both the keys area and the values area, so the amount allocatable
  // for values will be slightly smaller.
  const DEFAULT_CACHE_SIZE = 134217728;
  const SAFE_AREA_SIZE = 64;
  // Don't let value allocations become smaller than this, to reduce fragmentation
  const MIN_VALUE_ALLOC_SIZE = 64;
  const MAX_VALUE_SIZE = 2097152; // 2 MiB
  const FULL_CACHE_REMOVED_ITEMS = 10;
  // Use a low load factor (i.e. make there be many more slots in the hash
  // table than the maximum amount of items we'll be storing). 0.5 or less.
  const MAX_LOAD_FACTOR = 0.5;
  // If a key string is longer than this, the key is truncated silently
  const MAX_KEY_LENGTH = 250;

  const FLAG_SERIALIZED = 0b00000001;

  private $shm;
  private $shmKey;
  private $lock;
  private $getHits = 0;
  private $getMisses = 0;

  /**
   * @param $desiredSize The size of the shared memory block, which will contain both keys and values. If a block already exists and its size is larger, the block's size will not be reduced. If its size is smaller, it will be enlarged.
   *
   * @throws \Exception
   */
  function __construct( $desiredSize = 0 ) {

    if ( !is_int( $desiredSize ) ) {
      throw new \InvalidArgumentException( '$desiredSize must be an integer' );
    }
    else if ( $desiredSize && $desiredSize < 1024 * 1024 * 16 ) {
      throw new \InvalidArgumentException( '$desiredSize must be at least 16 MiB, but you defined it as '.
        round( $desiredSize / 1024 / 1024, 5 ) .' MiB' );
    }

    $this->lock = new ShmCache\Lock();

    $this->initMemBlock( $desiredSize );
  }

  function __destruct() {

    if ( $this->shm ) {
      $this->flushBufferedStatsToShm();
      shmop_close( $this->shm );
    }
  }

  function set( $key, $value ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    $this->lock->getWriteLock();
    $ret = $this->_set( $key, $value, $retIsSerialized );
    $this->lock->releaseWriteLock();

    return $ret;
  }

  function get( $key ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ );

    $key = $this->sanitizeKey( $key );

    $this->lock->getReadLock();
    $ret = $this->_get( $key, $retIsSerialized, $retIsCacheHit );
    $this->lock->releaseReadLock();

    if ( $retIsCacheHit )
      ++$this->getHits;
    else
      ++$this->getMisses;

    if ( $ret && $retIsSerialized )
      $ret = unserialize( $ret );

    return $ret;
  }

  function exists( $key ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ );

    $key = $this->sanitizeKey( $key );

    $this->lock->getReadLock();
    $ret = ( $this->getHashTableIndex( $key ) > -1 );
    $this->lock->releaseReadLock();

    return $ret;
  }

  function add( $key, $value ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    $this->lock->getWriteLock();

    if ( $this->getHashTableIndex( $key ) > -1 )
      $ret = false;
    else
      $ret = $this->_set( $key, $value, $retIsSerialized );

    $this->lock->releaseWriteLock();

    return $ret;
  }

  function replace( $key, $value ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    $this->lock->getWriteLock();

    if ( $this->getHashTableIndex( $key ) < 0 )
      $ret = false;
    else
      $ret = $this->_set( $key, $value, $retIsSerialized );

    $this->lock->releaseWriteLock();

    return $ret;
  }

  function increment( $key, $offset = 1, $initialValue = 0 ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ );

    $key = $this->sanitizeKey( $key );
    $offset = (int) $offset;
    $initialValue = (int) $initialValue;

    if ( !$this->lock->getWriteLock() )
      return false;

    $value = $this->_get( $key, $retIsSerialized, $retIsCacheHit );
    if ( $retIsSerialized )
      $value = unserialize( $value );

    if ( $value === false ) {
      $value = $initialValue;
    }
    else if ( !is_numeric( $value ) ) {
      trigger_error( 'Item '. $key .' is not numeric' );
      $this->lock->releaseWriteLock();
      return false;
    }

    $value = max( $value + $offset, 0 );
    $valueSerialized = $this->maybeSerialize( $value, $retIsSerialized );
    $success = $this->_set( $key, $valueSerialized, $retIsSerialized );

    if ( !$this->lock->releaseWriteLock() )
      return false;

    if ( $success )
      return $value;

    return false;
  }

  function decrement( $key, $offset = 1, $initialValue = 0 ) {

    $offset = (int) $offset;
    $initialValue = (int) $initialValue;

    return $this->increment( $key, -$offset, $initialValue );
  }

  function delete( $key ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ );

    $key = $this->sanitizeKey( $key );

    if ( !$this->lock->getWriteLock() )
      return false;

    $index = $this->getHashTableIndex( $key );
    $ret = false;

    if ( $index >= 0 ) {

      $metaOffset = $this->getItemMetaOffsetByHashTableIndex( $index );

      if ( $metaOffset > 0 ) {

        $item = $this->getItemMetaByOffset( $metaOffset, false );

        if ( $item ) {
          if ( !$item[ 'valsize' ] )
            $ret = true;
          else
            $ret = $this->removeItem( $key, $metaOffset );
        }
      }
    }

    if ( !$this->lock->releaseWriteLock() )
      return false;

    return $ret;
  }

  function flush() {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ );

    if ( !$this->lock->getWriteLock() )
      return false;

    try {
      $this->clearMemBlock();
      $ret = true;
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
      $ret = false;
    }

    if ( !$this->lock->releaseWriteLock() )
      return false;

    return $ret;
  }

  /**
   * Deletes the shared memory block created by this class. This will only
   * work if the block was created by the same user or group that is currently
   * running this PHP script.
   */
  function destroy() {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ );

    if ( !$this->lock->getWriteLock() )
      return false;

    try {
      $this->destroyMemBlock();
      $ret = true;
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
      $ret = false;
    }

    if ( !$this->lock->releaseWriteLock() )
      return false;

    return $ret;
  }

  function getStats() {

    if ( !$this->lock->getReadLock() )
      throw new \Exception( 'Could not get a lock' );

    $ret = (object) [
      'items' => 0,
      'maxItems' => $this->MAX_ITEMS,
      'availableHashTableSlots' => $this->KEYS_SLOTS,
      'usedHashTableSlots' => 0,
      'hashTableLoadFactor' => 0,
      'hashTableMemorySize' => $this->KEYS_SIZE,
      'availableValueMemSize' => $this->VALUES_SIZE,
      'usedValueMemSize' => 0,
      'avgItemValueSize' => 0,
      'ringBufferPointer' => $this->getRingBufferPointer(),
      'getHitCount' => $this->getGetHits(),
      'getMissCount' => $this->getGetMisses(),
      'itemMetadataSize' => $this->ITEM_META_SIZE,
      'minItemValueSize' => self::MIN_VALUE_ALLOC_SIZE,
      'maxItemValueSize' => self::MAX_VALUE_SIZE,
    ];

    for ( $i = $this->KEYS_START; $i < $this->KEYS_START + $this->KEYS_SIZE; $i += $this->LONG_SIZE ) {
      if ( unpack( 'l', shmop_read( $this->shm, $i, $this->LONG_SIZE ) )[ 1 ] !== 0 )
        ++$ret->usedHashTableSlots;
    }

    $ret->hashTableLoadFactor = $ret->usedHashTableSlots / $ret->availableHashTableSlots;

    for ( $i = $this->VALUES_START; $i < $this->VALUES_START + $this->VALUES_SIZE; ) {

      $item = $this->getItemMetaByOffset( $i );

      if ( $item[ 'valsize' ] ) {
        ++$ret->items;
        $ret->usedValueMemSize += $item[ 'valsize' ];
      }

      $i += $this->ITEM_META_SIZE + $item[ 'valallocsize' ];
    }

    if ( !$this->lock->releaseReadLock() )
      throw new \Exception( 'Could not release a lock' );

    $ret->avgItemValueSize = ( $ret->items )
      ? $ret->usedValueMemSize / $ret->items
      : 0;

    return $ret;
  }

  function dumpHashTableClusters() {

    if ( !$this->lock->getReadLock() )
      throw new \Exception( 'Could not get a lock' );

    for ( $i = $this->KEYS_START; $i < $this->KEYS_START + $this->KEYS_SIZE; $i += $this->LONG_SIZE ) {
      if ( unpack( 'l', shmop_read( $this->shm, $i, $this->LONG_SIZE ) )[ 1 ] !== 0 )
        echo 'x';
      else
        echo '.';
    }

    if ( !$this->lock->releaseReadLock() )
      throw new \Exception( 'Could not release a lock' );

    echo PHP_EOL;
  }

  function dumpItems() {

    if ( !$this->lock->getReadLock() )
      throw new \Exception( 'Could not get a lock' );

    echo 'Items in cache: '. $this->getItemCount() . PHP_EOL;

    $itemNr = 1;
    $nonFreeItems = 0;

    for ( $i = $this->VALUES_START; $i < $this->VALUES_START + $this->VALUES_SIZE; ) {

      $item = $this->getItemMetaByOffset( $i );

      echo '['. $itemNr++ .'] '. ( !$item[ 'valsize' ] ? '[None]' : $item[ 'key' ] ) .
        ' (hashidx: '. ( !$item[ 'valsize' ] ? '[None]' : $this->getHashTableIndex( $item[ 'key' ] ) ) .
        ', offset: '. $i .', nextoffset: '. $this->getNextItemOffset( $i, $item[ 'valallocsize' ] ) .
        ', valallocsize: '. $item[ 'valallocsize' ] .', valsize: '. $item[ 'valsize' ] .')'. PHP_EOL;

      if ( !$item[ 'valsize' ] ) {
        echo '    [Free space]';
      }
      else {
        echo '    "'. shmop_read( $this->shm, $i + $this->ITEM_META_SIZE, min( 60, $item[ 'valsize' ] ) ) .'"';
        ++$nonFreeItems;
      }

      echo PHP_EOL;

      $i += $this->ITEM_META_SIZE + $item[ 'valallocsize' ];
    }

    if ( !$this->lock->releaseReadLock() )
      throw new \Exception( 'Could not release a lock' );
  }

  private function maybeSerialize( $value, &$retIsSerialized ) {

    $retIsSerialized = false;

    if ( !is_string( $value ) ) {
      $value = serialize( $value );
      $retIsSerialized = true;
    }

    return $value;
  }

  private function _get( $key, &$retIsSerialized, &$retIsCacheHit ) {

    $index = $this->getHashTableIndex( $key );
    $ret = false;
    $retIsCacheHit = false;
    $retIsSerialized = false;

    if ( $index >= 0 ) {

      $metaOffset = $this->getItemMetaOffsetByHashTableIndex( $index );

      if ( $metaOffset > 0 ) {

        $item = $this->getItemMetaByOffset( $metaOffset, false );

        if ( $item ) {

          $data = shmop_read( $this->shm, $metaOffset + $this->ITEM_META_SIZE, $item[ 'valsize' ] );

          if ( $data === false ) {
            trigger_error( 'Could not read value for item "'. rawurlencode( $key ) .'"' );
          }
          else {
            $retIsSerialized = $item[ 'flags' ] & self::FLAG_SERIALIZED;
            $retIsCacheHit = true;
            $ret = $data;
          }
        }
      }
    }

    return $ret;
  }

  private function _set( $key, $value, $valueIsSerialized ) {

    $newValueSize = strlen( $value );
    $index = $this->getHashTableIndex( $key );

    if ( $index >= 0 ) {

      $metaOffset = $this->getItemMetaOffsetByHashTableIndex( $index );

      if ( $metaOffset > 0 ) {

        $existingItem = $this->getItemMetaByOffset( $metaOffset, false );

        if ( $existingItem ) {
          // There's enough space for the new value in the existing item's value
          // memory area spot. Replace the value in-place.
          if ( $newValueSize <= $existingItem[ 'valallocsize' ] ) {
            $flags = 0;
            if ( $valueIsSerialized )
              $flags |= self::FLAG_SERIALIZED;
            if ( !$this->writeItemMeta( $metaOffset, null, null, $newValueSize, $flags ) )
              goto error;
            if ( !$this->writeItemValue( $metaOffset + $this->ITEM_META_SIZE, $value ) )
              goto error;
            goto success;
          }
          // The new value is too large to fit into the existing item's spot, and
          // would overwrite 1 or more items to the right of it. We'll instead
          // remove the existing item, and handle this as a new value, so that this
          // item will replace 1 or more of the _oldest_ items (that are pointed to
          // by the ring buffer pointer).
          else {
            if ( !$this->removeItem( $key, $metaOffset ) )
              goto error;
          }
        }
      }
    }

    // Note: whenever we cannot store the value to the cache, we remove any
    // existing item with the same key. This emulates memcached:
    // https://github.com/memcached/memcached/wiki/Performance#how-it-handles-set-failures
    if ( $newValueSize > self::MAX_VALUE_SIZE ) {
      trigger_error( 'Item "'. rawurlencode( $key ) .'" is too large ('. round( $newValueSize / 1000, 2 ) .' KB) to cache' );
      goto error;
    }

    $oldestItemOffset = $this->getRingBufferPointer();
    if ( $oldestItemOffset <= 0 )
      goto error;
    $replacedItem = $this->getItemMetaByOffset( $oldestItemOffset );
    if ( !$replacedItem )
      goto error;
    $replacedItemOffset = $oldestItemOffset;
    $replacedItemIsFree = !$replacedItem[ 'valsize' ];
    if ( !$replacedItemIsFree ) {
      if ( !$this->removeItem( $replacedItem[ 'key' ], $replacedItemOffset ) )
        goto error;
    }

    $allocatedSize = $replacedItem[ 'valallocsize' ];

    // When the maximum amount of cached items is reached, and we're adding
    // a new item (rather than just writing into an existing item's memory
    // space) we start removing the oldest items. We don't remove just one,
    // but multiple at once, so that any calls to set() afterwards will not
    // immediately have to remove an item again.
    if ( $this->getItemCount() >= $this->MAX_ITEMS ) {

      $itemsToRemove = self::FULL_CACHE_REMOVED_ITEMS;

      // $replacedItem is the oldest item in the buffer, and was already
      // removed above
      if ( !$replacedItemIsFree )
        --$itemsToRemove;

      $allocatedSize = $this->mergeItemWithNextFreeValueSlots( $replacedItemOffset );
      $removedOffset = $this->getNextItemOffset( $replacedItemOffset, $allocatedSize );
      $loopedAround = false;

      while ( $itemsToRemove > 0 ) {

        // Loop around if we reached the last item in the values memory area
        if ( !$removedOffset ) {
          if ( $loopedAround ) {
            trigger_error( 'Possible memory corruption. Unexpectedly found no next item for item "'.
              rawurlencode( $removedItem[ 'key' ] ) .'"' );
            break;
          }
          $removedOffset = $this->VALUES_START;
          $loopedAround = true;
        }

        // If we reach the offset the we start at, we've seen all the elements
        if ( $loopedAround && $removedOffset >= $replacedItemOffset )
          break;

        $removedItem = $this->getItemMetaByOffset( $removedOffset );
        if ( !$removedItem )
          goto error;

        if ( $loopedAround && $removedOffset === $this->VALUES_START )
          $removedAllocSize = $this->mergeItemWithNextFreeValueSlots( $removedOffset );
        else
          $removedAllocSize = $removedItem[ 'valallocsize' ];

        if ( $removedItem[ 'valsize' ] ) {
          if ( !$this->removeItem( $removedItem[ 'key' ], $removedOffset ) )
            goto error;
          --$itemsToRemove;
        }

        $removedOffset = $this->getNextItemOffset( $removedOffset, $removedAllocSize );
      }
    }

    $nextItemOffset = $this->getNextItemOffset( $replacedItemOffset, $allocatedSize );

    // The new value doesn't fit into an existing cache item. Make space for the
    // new value by merging next oldest cache items one by one into the current
    // cache item, until we have enough space.
    while ( $allocatedSize < $newValueSize ) {

      // Loop around if we reached the end of the values area
      if ( !$nextItemOffset ) {

        // We'll free the old item at the end of the values area, as we don't
        // wrap items around. Each item is a contiguous run of memory.
        if ( !$replacedItemIsFree ) {
          if ( !$this->removeItem( $key, $replacedItemOffset ) )
            goto error;
        }

        // Free the first item
        $firstItem = $this->getItemMetaByOffset( $this->VALUES_START );
        if ( !$firstItem )
          goto error;
        if ( $firstItem[ 'valsize' ] ) {
          if ( !$this->removeItem( $firstItem[ 'key' ], $this->VALUES_START ) )
            goto error;
        }
        $replacedItemOffset = $this->VALUES_START;
        $replacedItemIsFree = true;
        $allocatedSize = $firstItem[ 'valallocsize' ];
        $nextItemOffset = $this->getNextItemOffset( $this->VALUES_START, $allocatedSize );

        continue;
      }

      $nextItem = $this->getItemMetaByOffset( $nextItemOffset );
      if ( !$nextItem )
        goto error;

      if ( $nextItem[ 'valsize' ] ) {
        if ( !$this->removeItem( $nextItem[ 'key' ], $nextItemOffset ) )
          goto error;
      }

      // Merge the next item's space into this item
      $itemAllocSize = $nextItem[ 'valallocsize' ];
      $allocatedSize += $this->ITEM_META_SIZE + $itemAllocSize;
      $nextItemOffset = $this->getNextItemOffset( $nextItemOffset, $itemAllocSize );
    }

    $splitSlotSize = $allocatedSize - $newValueSize;

    // Split the cache item into two, if there is enough space left over
    if ( $splitSlotSize >= $this->ITEM_META_SIZE + self::MIN_VALUE_ALLOC_SIZE ) {

      $splitSlotOffset = $replacedItemOffset + $this->ITEM_META_SIZE + $newValueSize;
      $splitItemValAllocSize = $splitSlotSize - $this->ITEM_META_SIZE;

      if ( !$this->writeItemMeta( $splitSlotOffset, '', $splitItemValAllocSize, 0, 0 ) )
        goto error;

      $allocatedSize -= $splitSlotSize;
      $nextItemOffset = $splitSlotOffset;
    }

    $flags = 0;
    if ( $valueIsSerialized )
      $flags |= self::FLAG_SERIALIZED;

    if ( !$this->writeItemMeta( $replacedItemOffset, $key, $allocatedSize, $newValueSize, $flags ) )
      goto error;
    if ( !$this->writeItemValue( $replacedItemOffset + $this->ITEM_META_SIZE, $value ) )
      goto error;

    if ( !$this->addItemKey( $key, $replacedItemOffset ) )
      goto error;

    $this->setItemCount( $this->getItemCount() + 1 );

    $newBufferPtr = ( $nextItemOffset )
      ? $nextItemOffset
      : $this->VALUES_START;

    if ( !$this->setRingBufferPointer( $newBufferPtr ) )
      goto error;

    success:
    return true;

    error:
    return false;
  }

  private function sanitizeKey( $key ) {
    return substr( $key, 0, self::MAX_KEY_LENGTH );
  }

  private function getGetHits() {

    $data = shmop_read(
      $this->shm,
      $this->METADATA_AREA_START + $this->LONG_SIZE + $this->LONG_SIZE,
      $this->LONG_SIZE
    );

    if ( $data === false ) {
      trigger_error( 'Could not read the get() hit count' );
      return null;
    }

    return unpack( 'l', $data )[ 1 ];
  }

  private function getGetMisses() {

    $data = shmop_read(
      $this->shm,
      $this->METADATA_AREA_START + $this->LONG_SIZE + $this->LONG_SIZE + $this->LONG_SIZE,
      $this->LONG_SIZE
    );

    if ( $data === false ) {
      trigger_error( 'Could not read the get() miss count' );
      return null;
    }

    return unpack( 'l', $data )[ 1 ];
  }

  private function setGetHits( $count ) {

    if ( $count < 0 ) {
      trigger_error( 'get() hit count must be 0 or larger' );
      return false;
    }

    if ( shmop_write( $this->shm, pack( 'l', $count ), $this->METADATA_AREA_START + $this->LONG_SIZE + $this->LONG_SIZE ) === false ) {
      trigger_error( 'Could not write the get() hit count' );
      return false;
    }

    return true;
  }

  private function setGetMisses( $count ) {

    if ( $count < 0 ) {
      trigger_error( 'get() miss count must be 0 or larger' );
      return false;
    }

    if ( shmop_write( $this->shm, pack( 'l', $count ), $this->METADATA_AREA_START + $this->LONG_SIZE + $this->LONG_SIZE + $this->LONG_SIZE ) === false ) {
      trigger_error( 'Could not write the get() miss count' );
      return false;
    }

    return true;
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
    $index %= $this->KEYS_SLOTS;

    return $index;
  }

  /**
   * Returns an index in the key hash table, to the element whose key matches
   * the given key exactly.
   */
  private function getHashTableIndex( $key ) {

    $index = $this->getHashTableBaseIndex( $key );
    $metaOffset = $this->getItemMetaOffsetByHashTableIndex( $index );

    if ( !$metaOffset )
      return -1;

    for ( $i = 0; $i < $this->KEYS_SLOTS; ++$i ) {

      $item = $this->getItemMetaByOffset( $metaOffset );
      if ( $item[ 'valsize' ] && $item[ 'key' ] === $key )
        return $index;

      $index = ( $index + 1 ) % $this->KEYS_SLOTS;
      $metaOffset = $this->getItemMetaOffsetByHashTableIndex( $index );
      if ( !$metaOffset )
        break;
    }

    return -1;
  }

  private function writeItemValue( $offset, $value ) {

    if ( shmop_write( $this->shm, $value, $offset ) === false ) {
      trigger_error( 'Could not write item value' );
      return false;
    }

    return true;
  }

  /**
   * Remove the item hash table entry and clears its value (i.e. frees its
   * memory).
   */
  private function removeItem( $key, $itemOffset ) {

    $hashTableIndex = $this->getHashTableIndex( $key );

    // Key doesn't exist in the hash table. This item was probably already
    // removed, or never existed.
    if ( $hashTableIndex < 0 ) {
      trigger_error( 'Item "'. rawurlencode( $key ) .'" has no element in the hash table' );
      return false;
    }

    // Clear the hash table key
    if ( !$this->writeItemKey( $hashTableIndex, 0 ) ) {
      trigger_error( 'Could not free the item "'. rawurlencode( $key ) .'" key' );
      return false;
    }

    // Make the value space free by setting the valsize to 0
    if ( shmop_write( $this->shm, pack( 'l', 0 ), $itemOffset + self::MAX_KEY_LENGTH + $this->LONG_SIZE ) === false ) {
      trigger_error( 'Could not free the item "'. rawurlencode( $key ) .'" value' );
      return false;
    }

    // After we've removed the item, we have an empty slot in the hash table.
    // This would prevent our logic from finding any items that hash to the
    // same base index as $key so we need to fill the gap by moving
    // all following contiguous table items to the left by one slot.

    $nextHashTableIndex = ( $hashTableIndex + 1 ) % $this->KEYS_SLOTS;

    for ( $i = 0; $i < $this->KEYS_SLOTS; ++$i ) {

      $data = shmop_read( $this->shm, $this->KEYS_START + $nextHashTableIndex * $this->LONG_SIZE, $this->LONG_SIZE );
      $nextItemMetaOffset = unpack( 'l', $data )[ 1 ];

      // Reached an empty hash table slot
      if ( $nextItemMetaOffset === 0 )
        break;

      $nextItem = $this->getItemMetaByOffset( $nextItemMetaOffset );
      $this->writeItemKey( $nextHashTableIndex, 0 );
      $this->addItemKey( $nextItem[ 'key' ], $nextItemMetaOffset );
      $nextHashTableIndex = ( $nextHashTableIndex + 1 ) % $this->KEYS_SLOTS;
    }

    return $this->setItemCount( $this->getItemCount() - 1 );
  }

  private function mergeItemWithNextFreeValueSlots( $itemOffset ) {

    $item = $this->getItemMetaByOffset( $itemOffset, false );
    $allocSize = $origAllocSize = $item[ 'valallocsize' ];
    $nextItemOffset = $this->getNextItemOffset( $itemOffset, $allocSize );

    while ( $nextItemOffset ) {
      $nextItem = $this->getItemMetaByOffset( $nextItemOffset, false );
      if ( $nextItem[ 'valsize' ] )
        break;
      $thisItemAllocSize = $nextItem[ 'valallocsize' ];
      $allocSize += $this->ITEM_META_SIZE + $thisItemAllocSize;
      $nextItemOffset = $this->getNextItemOffset( $nextItemOffset, $thisItemAllocSize );
    }

    if ( $allocSize !== $origAllocSize ) {
      // Resize
      $this->writeItemMeta( $itemOffset, null, $allocSize );
      // Fix the ring buffer pointer
      $bufferPtr = $this->getRingBufferPointer();
      if ( $bufferPtr > $itemOffset && $bufferPtr < $itemOffset + $allocSize )
        $this->setRingBufferPointer( $itemOffset );
    }

    return $allocSize;
  }

  private function getItemCount() {

    $data = shmop_read( $this->shm, $this->METADATA_AREA_START, $this->LONG_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not read the item count' );
      return null;
    }

    return unpack( 'l', $data )[ 1 ];
  }

  private function setItemCount( $count ) {

    if ( $count < 0 ) {
      trigger_error( 'Item count must be 0 or larger' );
      return false;
    }

    if ( shmop_write( $this->shm, pack( 'l', $count ), $this->METADATA_AREA_START ) === false ) {
      trigger_error( 'Could not write the item count' );
      return false;
    }

    return true;
  }

  private function getRingBufferPointer() {

    $data = shmop_read( $this->shm, $this->METADATA_AREA_START + $this->LONG_SIZE, $this->LONG_SIZE );
    $oldestItemOffset = unpack( 'l', $data )[ 1 ];

    if ( !$oldestItemOffset ) {
      trigger_error( 'Could not find the ring buffer pointer' );
      return null;
    }

    if ( $oldestItemOffset < $this->VALUES_START || $oldestItemOffset >= $this->VALUES_START + $this->VALUES_SIZE ) {
      trigger_error( 'The ring buffer pointer is out of bounds' );
      return null;
    }

    return $oldestItemOffset;
  }

  private function setRingBufferPointer( $itemMetaOffset ) {

    $data = pack( 'l', $itemMetaOffset );
    $ret = shmop_write( $this->shm, $data, $this->METADATA_AREA_START + $this->LONG_SIZE );

    if ( $ret === false ) {
      trigger_error( 'Could not write the ring buffer pointer' );
      return false;
    }

    return true;
  }

  private function writeItemMeta( $offset, $key = null, $valueAllocatedSize = null, $valueSize = null, $flags = null ) {

    if ( $key !== null ) {
      if ( $valueAllocatedSize !== null ) {
        if ( $valueSize === null && $flags !== null )
          throw new \InvalidArgumentException( 'If $valueAllocatedSize and $flags are set, $valueSize must also be set' );
      }
      else if ( $valueSize !== null || $flags !== null ) {
        throw new \InvalidArgumentException( 'If $key and ($valueSize or $flags) are set, $valueAllocatedSize must also be set' );
      }
    }
    else if ( $valueAllocatedSize !== null ) {
      if ( $valueSize === null && $flags !== null )
        throw new \InvalidArgumentException( 'If $valueAllocatedSize and $flags are set, $valueSize must also be set' );
    }

    $data = '';
    $writeOffset = $offset;

    if ( $key !== null )
      $data .= pack( 'A'. self::MAX_KEY_LENGTH, $key );
    else
      $writeOffset += self::MAX_KEY_LENGTH;

    if ( $valueAllocatedSize !== null )
      $data .= pack( 'l', $valueAllocatedSize );
    else if ( $key === null )
      $writeOffset += $this->LONG_SIZE;

    if ( $valueSize !== null )
      $data .= pack( 'l', $valueSize );
    else if ( $key === null && $valueAllocatedSize === null )
      $writeOffset += $this->LONG_SIZE;

    if ( $flags !== null )
      $data .= pack( 'c', $flags );

    $ret = shmop_write( $this->shm, $data, $writeOffset );

    if ( $ret === false ) {
      trigger_error( 'Could not write cache item metadata' );
      return false;
    }

    return true;
  }

  private function getItemMetaOffsetByHashTableIndex( $index ) {

    $data = shmop_read( $this->shm, $this->KEYS_START + $index * $this->LONG_SIZE, $this->LONG_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not read item metadata offset from hash table index "'. $index .'"' );
      return false;
    }

    return unpack( 'l', $data )[ 1 ];
  }

  private function getItemMetaByOffset( $offset, $withKey = true ) {

    $unpackFormat = 'lvalallocsize/lvalsize/cflags';
    $readOffset = $offset;

    if ( $withKey )
      $unpackFormat = 'A'. self::MAX_KEY_LENGTH .'key/'. $unpackFormat;
    else
      $readOffset += self::MAX_KEY_LENGTH;

    $data = shmop_read( $this->shm, $readOffset, $this->ITEM_META_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not read item metadata at offset '. $offset .' (started reading at '. $readOffset .')' );
      return null;
    }

    return unpack( $unpackFormat, $data );
  }

  private function getNextItemOffset( $itemOffset, $itemValAllocSize ) {

    $nextItemOffset = $itemOffset + $this->ITEM_META_SIZE + $itemValAllocSize;
    // If it's the last item in the values area, the next item's offset is 0,
    // which means "none"
    if ( $nextItemOffset > $this->LAST_ITEM_MAX_OFFSET )
      $nextItemOffset = 0;

    return $nextItemOffset;
  }

  private function addItemKey( $key, $itemMetaOffset ) {

    $i = 0;
    $index = $this->getHashTableBaseIndex( $key );

    // Find first empty hash table slot in this cluster (i.e. bucket)
    do {

      $hashTableOffset = $this->KEYS_START + $index * $this->LONG_SIZE;
      $data = shmop_read( $this->shm, $hashTableOffset, $this->LONG_SIZE );

      if ( $data === false ) {
        trigger_error( 'Could not read hash table value' );
        return false;
      }

      $hashTableValue = unpack( 'l', $data )[ 1 ];
      if ( $hashTableValue === 0 )
        break;

      $index = ( $index + 1 ) % $this->KEYS_SLOTS;
    }
    while ( ++$i < $this->KEYS_SLOTS );

    return $this->writeItemKey( $index, $itemMetaOffset );
  }

  private function updateItemKey( $key, $itemMetaOffset ) {

    $hashTableIndex = $this->getHashTableIndex( $key );

    if ( $hashTableIndex < 0 ) {
      trigger_error( 'Could not get hash table offset for key "'. rawurlencode( $key ) .'"' );
      return false;
    }

    return $this->writeItemKey( $hashTableIndex, $itemMetaOffset );
  }

  private function writeItemKey( $hashTableIndex, $itemMetaOffset ) {

    $hashTableOffset = $this->KEYS_START + $hashTableIndex * $this->LONG_SIZE;
    $data = pack( 'l', $itemMetaOffset );

    $ret = shmop_write( $this->shm, $data, $hashTableOffset );

    if ( $ret === false ) {
      trigger_error( 'Could not write item key' );
      return false;
    }

    return true;
  }

  private function initMemBlock( $desiredSize ) {

    if ( !$this->lock->getReadLock() )
      throw new \Exception( 'Could not get a lock' );

    $opened = $this->openMemBlock();

    // Try to re-create an existing block with a larger size, if the block is
    // smaller than the desired size. This fails (at least on Linux) if the
    // Unix user trying to delete the block is different than the user that
    // created the block.
    if ( $opened && $desiredSize ) {

      $currentSize = shmop_size( $this->shm );

      if ( !$this->lock->releaseReadLock() )
        throw new \Exception( 'Could not release a lock' );

      if ( $currentSize < $desiredSize ) {

        if ( !$this->lock->getWriteLock() )
          throw new \Exception( 'Could not get a lock' );

        // Destroy and recreate the memory block with a larger size
        if ( shmop_delete( $this->shm ) ) {
          shmop_close( $this->shm );
          $this->shm = null;
          $opened = false;
        }
        else {
          trigger_error( 'Could not delete the memory block. Falling back to using the existing, smaller-than-desired block.' );
        }

        if ( !$this->lock->releaseWriteLock() )
          throw new \Exception( 'Could not release a lock' );
      }
    }
    else {

      if ( !$this->lock->releaseReadLock() )
        throw new \Exception( 'Could not release a lock' );
    }

    // No existing memory block. Create a new one.
    if ( !$opened ) {
      if ( !$this->lock->getWriteLock() )
        throw new \Exception( 'Could not get a lock' );

      $this->createMemBlock( $desiredSize );
    }
    else {
      if ( !$this->lock->getReadLock() )
        throw new \Exception( 'Could not get a lock' );
    }

    $this->populateSizes();

    // A new memory block. Write initial values.
    if ( !$opened ) {
      $this->clearMemBlock();

      if ( !$this->lock->releaseWriteLock() )
        throw new \Exception( 'Could not release a lock' );
    }
    else {
      if ( !$this->lock->releaseReadLock() )
        throw new \Exception( 'Could not release a lock' );
    }

    return (bool) $this->shm;
  }

  private function getMemBlockKey() {

    if ( $this->shmKey )
      return $this->shmKey;

    $tmpFile = '/var/lock/php-shm-cache-87b1dcf602a-memory.lock';
    if ( !file_exists( $tmpFile ) ) {
      if ( !touch( $tmpFile ) )
        throw new \Exception( 'Could not create '. $tmpFile );
      if ( !chmod( $tmpFile, 0777 ) )
        throw new \Exception( 'Could not change permissions of '. $tmpFile );
    }

    $this->shmKey = fileinode( $tmpFile );
    if ( !$this->shmKey )
      throw new \InvalidArgumentException( 'Invalid shared memory block key' );

    return $this->shmKey;
  }

  /**
   * @throws \Exception If both opening and creating a memory block failed
   * @return bool True if an existing block was opened, false if a new block was created
   */
  private function openMemBlock() {

    $blockKey = $this->getMemBlockKey();
    $mode = 0777;

    // The 'size' parameter is ignored by PHP when 'w' is used:
    // https://github.com/php/php-src/blob/9e709e2fa02b85d0d10c864d6c996e3368e977ce/ext/shmop/shmop.c#L183
    $this->shm = @shmop_open( $blockKey, "w", $mode, 0 );

    return (bool) $this->shm;
  }

  private function createMemBlock( $desiredSize ) {

    $blockKey = $this->getMemBlockKey();
    $mode = 0777;
    $this->shm = shmop_open( $blockKey, "n", $mode, ( $desiredSize ) ? $desiredSize : self::DEFAULT_CACHE_SIZE );

    return (bool) $this->shm;
  }

  private function populateSizes() {

    $primes = [
      5003,
      10007,
      20011,
      30011,
      40009,
      50021,
      75013,
      100012,
      125017,
      150011,
      200009,
      250013,
      300017,
    ];

    $this->BLOCK_SIZE = shmop_size( $this->shm );

    $this->LONG_SIZE = strlen( pack( 'l', 1 ) ); // i.e. sizeof(long) in C
    $this->CHAR_SIZE = strlen( pack( 'c', 1 ) ); // i.e. sizeof(char) in C

    $keySize = self::MAX_KEY_LENGTH;
    $allocatedSize = $this->LONG_SIZE;
    $valueSize = $this->LONG_SIZE;
    $flagsSize = $this->CHAR_SIZE;
    $this->ITEM_META_SIZE = $keySize + $allocatedSize + $valueSize + $flagsSize;

    // Metadata area

    $itemCountSize = $this->LONG_SIZE;
    $ringBufferPtrSize = $this->LONG_SIZE;
    $getHitsSize = $this->LONG_SIZE;
    $getMissesSize = $this->LONG_SIZE;
    $this->METADATA_AREA_START = 0;
    $this->METADATA_AREA_SIZE = $itemCountSize + $ringBufferPtrSize + $getHitsSize + $getMissesSize;

    // Keys area (i.e. cache keys hash table)

    $approxValuesAreaSize = $this->BLOCK_SIZE - 100000 * $this->LONG_SIZE;
    $approxBytesPerValuesAreaItem = $this->ITEM_META_SIZE + min( 1024, self::MAX_VALUE_SIZE );

    $this->MAX_ITEMS = floor( $approxValuesAreaSize / $approxBytesPerValuesAreaItem );
    $hashTableSlotCount = ceil( $this->MAX_ITEMS / self::MAX_LOAD_FACTOR );

    foreach ( $primes as $prime ) {
      if ( $prime >= $hashTableSlotCount ) {
        $hashTableSlotCount = $prime;
        break;
      }
    }

    $this->KEYS_SLOTS = $hashTableSlotCount;
    $this->KEYS_START = $this->METADATA_AREA_START + $this->METADATA_AREA_SIZE + self::SAFE_AREA_SIZE;
    // The hash table values are "pointers", i.e. offsets to the values area
    $this->KEYS_SIZE = $this->KEYS_SLOTS * $this->LONG_SIZE;

    // Values area (i.e. item metadata and the actual cached values)

    $this->VALUES_START = $this->KEYS_START + $this->KEYS_SIZE + self::SAFE_AREA_SIZE;
    $this->VALUES_SIZE = $this->BLOCK_SIZE - $this->VALUES_START;
    $this->LAST_ITEM_MAX_OFFSET = $this->VALUES_START + $this->VALUES_SIZE -
      $this->ITEM_META_SIZE - self::MIN_VALUE_ALLOC_SIZE;
  }

  private function destroyMemBlock() {

    $this->flushBufferedStatsToShm();

    $deleted = shmop_delete( $this->shm );

    if ( !$deleted ) {
      throw new \Exception( 'Could not destroy the memory block. Try running the \'ipcrm\' command as a super-user to remove the memory block listed in the output of \'ipcs\'.' );
    }

    shmop_close( $this->shm );
    $this->shm = null;
  }

  private function flushBufferedStatsToShm() {

    // Flush all of our get() hit and miss counts to the shared memory
    try {
      if ( $this->lock->getWriteLock() ) {

        if ( $this->getHits )
          $this->setGetHits( $this->getGetHits() + $this->getHits );

        if ( $this->getMisses )
          $this->setGetMisses( $this->getGetMisses() + $this->getMisses );

        $this->lock->releaseWriteLock();
      }
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
    }
  }

  /**
   * Write NULs over the whole block and initialize it with a single, free
   * cache item.
   */
  private function clearMemBlock() {

    // Clear all bytes in the shared memory block
    $memoryWriteChunk = 1024 * 1024 * 4;
    for ( $i = 0; $i < $this->BLOCK_SIZE; $i += $memoryWriteChunk ) {

      // Last chunk might have to be smaller
      if ( $i + $memoryWriteChunk > $this->BLOCK_SIZE )
        $memoryWriteChunk = $this->BLOCK_SIZE - $i;

      $data = pack( 'x'. $memoryWriteChunk );
      $res = shmop_write( $this->shm, $data, $i );

      if ( $res === false )
        throw new \Exception( 'Could not write NUL bytes to the memory block' );
    }

    // The ring buffer pointer always points to the oldest cache item. In this
    // case it's the first and only cache item, which represents free space.
    $this->setRingBufferPointer( $this->VALUES_START );
    $this->setItemCount( 0 );
    $this->setGetHits( 0 );
    $this->setGetMisses( 0 );
    // Initialize first cache item (i.e. free space)
    $this->writeItemMeta( $this->VALUES_START, '', $this->VALUES_SIZE - $this->ITEM_META_SIZE, 0, 0 );
  }
}


