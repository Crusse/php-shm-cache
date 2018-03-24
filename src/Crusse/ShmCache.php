<?php

namespace Crusse;

/**
 * A shared memory cache for storing data that is persisted across multiple PHP
 * script runs.
 * 
 * Features:
 * 
 * - Stores the hash table and items' values in Unix shared memory
 * - FIFO queue: tries to evict the oldest items when the cache is full
 *
 * The same memory block is shared by all instances of ShmCache. This means the
 * maximum amount of memory used by ShmCache is always DEFAULT_CACHE_SIZE, or
 * $desiredSize, if defined.
 *
 * You can use the Unix programs `ipcs` and `ipcrm` to list and remove the
 * memory block created by this class, if something goes wrong.
 *
 * It is important that the first instantiation and any further uses of this
 * class are with the same Unix user (e.g. 'www-data'), because the shared
 * memory block cannot be deleted (e.g. in destroy()) by another user, at least
 * on Linux. If you have problems deleting the memory block created by this
 * class via $cache->destroy(), using `ipcrm` as root is your best bet.
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
 *     implementation uses linear probing, so this table is only ever filled up
 *     to MAX_LOAD_FACTOR.
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

  // Don't let value allocations become smaller than this, to reduce fragmentation
  const MIN_VALUE_ALLOC_SIZE = 32;
  const MAX_VALUE_SIZE = 2097152; // 2 MiB
  // If a key string is longer than this, the key is truncated silently
  const MAX_KEY_LENGTH = 250;
  // The more hash table buckets there are, the more memory is used but the
  // faster it is to find a hash table entry. This also affects how many locks
  // there are for hash table entries.
  const HASH_BUCKET_COUNT = 512;

  const FLAG_SERIALIZED = 0b00000001;

  // TODO: currently we're locking the whole memory block whenever there's
  // a read or a write. We should probably add multiple locks to only lock a portion
  // of the memory block. Maybe have separate locks for these (but be careful
  // with coordinating the different locks):
  //
  // [
  //   [itemcount]
  //   [ringbufferpointer]
  // ]
  // [gethits and getmisses]
  // [A fixed number of slices of the keys area?]
  // [A fixed number of slices of the values area? How to align locks at value boundaries?]
  private $memAllocLock;
  private $statsLock;
  private $bucketLocks = [];

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

    $this->memAllocLock = new ShmCache\Lock( 'memalloc' );
    $this->statsLock = new ShmCache\Lock( 'stats' );

    $memBlock = new ShmCache\MemoryBlock( $desiredSize, self::HASH_BUCKET_COUNT );
  }

  function __destruct() {

    if ( $this->shm ) {
      $this->flushBufferedStatsToShm();
      shmop_close( $this->shm );
    }
  }

  function set( $key, $value ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    $this->memAllocLock->getReadLock();

    $lock = $this->getItemLock( $key );
    $lock->getWriteLock();
    $ret = $this->_set( $key, $value, $retIsSerialized );
    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function get( $key ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    $this->memAllocLock->getReadLock();

    $lock = $this->getItemLock( $key );
    $lock->getReadLock();
    $ret = $this->_get( $key, $retIsSerialized, $retIsCacheHit );
    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

    if ( $ret && $retIsSerialized )
      $ret = unserialize( $ret );

    if ( $retIsCacheHit )
      ++$this->getHits;
    else
      ++$this->getMisses;

    return $ret;
  }

  function exists( $key ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    $this->memAllocLock->getReadLock();

    $lock = $this->getItemLock( $key );
    $lock->getReadLock();
    $ret = ( $this->getHashTableIndex( $key ) > -1 );
    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function add( $key, $value ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    $this->memAllocLock->getReadLock();

    $lock = $this->getItemLock( $key );
    $lock->getWriteLock();

    if ( $this->getHashTableIndex( $key ) > -1 )
      $ret = false;
    else
      $ret = $this->_set( $key, $value, $retIsSerialized );

    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function replace( $key, $value ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    $this->memAllocLock->getReadLock();

    $lock = $this->getItemLock( $key );
    $lock->getWriteLock();

    if ( $this->getHashTableIndex( $key ) < 0 )
      $ret = false;
    else
      $ret = $this->_set( $key, $value, $retIsSerialized );

    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function increment( $key, $offset = 1, $initialValue = 0 ) {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $offset = (int) $offset;
    $initialValue = (int) $initialValue;

    if ( !$this->memAllocLock->getReadLock() )
      return false;

    $lock = $this->getItemLock( $key );
    if ( !$lock->getWriteLock() )
      return false;

    $value = $this->_get( $key, $retIsSerialized, $retIsCacheHit );
    if ( $retIsSerialized )
      $value = unserialize( $value );

    if ( $value === false ) {
      $value = $initialValue;
    }
    else if ( !is_numeric( $value ) ) {
      trigger_error( 'Item '. $key .' is not numeric' );
      $lock->releaseLock();
      return false;
    }

    $value = max( $value + $offset, 0 );
    $valueSerialized = $this->maybeSerialize( $value, $retIsSerialized );
    $success = $this->_set( $key, $valueSerialized, $retIsSerialized );

    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

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
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    if ( !$this->memAllocLock->getReadLock() )
      return false;

    $lock = $this->getItemLock( $key );
    if ( !$lock->getWriteLock() )
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

    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function flush() {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    if ( !$this->memAllocLock->getWriteLock() )
      return false;

    try {
      $this->clearMemBlock();
      $ret = true;
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
      $ret = false;
    }

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  /**
   * Deletes the shared memory block created by this class. This will only
   * work if the block was created by the same Unix user or group that is
   * currently running this PHP script.
   */
  function destroy() {

    if ( !$this->shm )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    if ( !$this->memAllocLock->getWriteLock() )
      return false;

    try {
      $this->destroyMemBlock();
      $ret = true;
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
      $ret = false;
    }

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function getStats() {

    if ( !$this->memAllocLock->getReadLock() )
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
      // TODO: acquire item lock?
      if ( unpack( 'l', shmop_read( $this->shm, $i, $this->LONG_SIZE ) )[ 1 ] !== 0 )
        ++$ret->usedHashTableSlots;
    }

    $ret->hashTableLoadFactor = $ret->usedHashTableSlots / $ret->availableHashTableSlots;

    for ( $i = $this->VALUES_START; $i < $this->VALUES_START + $this->VALUES_SIZE; ) {

      // TODO: acquire item lock?
      $item = $this->getItemMetaByOffset( $i );

      if ( $item[ 'valsize' ] ) {
        ++$ret->items;
        $ret->usedValueMemSize += $item[ 'valsize' ];
      }

      $i += $this->ITEM_META_SIZE + $item[ 'valallocsize' ];
    }

    if ( !$this->memAllocLock->releaseLock() )
      throw new \Exception( 'Could not release a lock' );

    $ret->avgItemValueSize = ( $ret->items )
      ? $ret->usedValueMemSize / $ret->items
      : 0;

    return $ret;
  }

  private function getItemLock( $key ) {

    $baseIndex = $this->getHashTableBaseIndex( $key );
    $lockIndex = $baseIndex % self::HASH_BUCKET_COUNT;

    if ( !isset( $this->bucketLocks[ $lockIndex ] ) )
      $this->bucketLocks[ $lockIndex ] = new ShmCache\Lock( 'item'. $lockIndex );

    return $this->bucketLocks[ $lockIndex ];
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

    // Don't need this anymore
    unset( $index );

    // Note: whenever we cannot store the value to the cache, we remove any
    // existing item with the same key (in removeItem() above). This emulates memcached:
    // https://github.com/memcached/memcached/wiki/Performance#how-it-handles-set-failures
    if ( $newValueSize > self::MAX_VALUE_SIZE ) {
      trigger_error( 'Item "'. rawurlencode( $key ) .'" is too large ('. round( $newValueSize / 1000, 2 ) .' KB) to cache' );
      goto error;
    }

    $itemsToRemove = self::FULL_CACHE_REMOVED_ITEMS;

    // TODO: metadata lock
    $oldestItemOffset = $this->getRingBufferPointer();
    if ( $oldestItemOffset <= 0 )
      goto error;
    $replacedItem = $this->getItemMetaByOffset( $oldestItemOffset );
    if ( !$replacedItem )
      goto error;
    $replacedItemOffset = $oldestItemOffset;
    if ( $replacedItem[ 'valsize' ] ) {
      if ( !$this->removeItem( $replacedItem[ 'key' ], $replacedItemOffset ) )
        goto error;
      --$itemsToRemove;
    }

    $allocatedSize = $replacedItem[ 'valallocsize' ];

    // When the maximum amount of cached items is reached, and we're adding
    // a new item (rather than just writing into an existing item's memory
    // space) we start removing the oldest items. We don't remove just one,
    // but multiple at once, so that any calls to set() afterwards will not
    // immediately have to remove an item again.
    if ( $this->getItemCount() >= $this->MAX_ITEMS ) {

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

        // Free the first item
        $firstItem = $this->getItemMetaByOffset( $this->VALUES_START );
        if ( !$firstItem )
          goto error;
        if ( $firstItem[ 'valsize' ] ) {
          if ( !$this->removeItem( $firstItem[ 'key' ], $this->VALUES_START ) )
            goto error;
        }
        $replacedItemOffset = $this->VALUES_START;
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
    // TODO: unpack() is slow. Is there an alternative?
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

    static $unpackFormatNoKey;
    static $unpackFormatWithKey;

    if ( !isset( $unpackFormatNoKey ) )
      $unpackFormatNoKey = 'lvalallocsize/lvalsize/cflags';
    if ( !isset( $unpackFormatWithKey ) )
      $unpackFormatWithKey = 'A'. self::MAX_KEY_LENGTH .'key/lvalallocsize/lvalsize/cflags';

    if ( $withKey ) {
      $unpackFormat = $unpackFormatWithKey;
    }
    else {
      $unpackFormat = $unpackFormatNoKey;
      $offset += self::MAX_KEY_LENGTH;
    }

    $data = shmop_read( $this->shm, $offset, $this->ITEM_META_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not read item metadata at offset '. $offset );
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

  private function flushBufferedStatsToShm() {

    // Flush all of our get() hit and miss counts to the shared memory
    try {
      if ( $this->statsLock->getWriteLock() ) {

        if ( $this->getHits ) {
          $this->setGetHits( $this->getGetHits() + $this->getHits );
          $this->getHits = 0;
        }

        if ( $this->getMisses ) {
          $this->setGetMisses( $this->getGetMisses() + $this->getMisses );
          $this->getMisses = 0;
        }

        $this->statsLock->releaseLock();
      }
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
    }
  }
}


