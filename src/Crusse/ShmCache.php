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
 */
class ShmCache {

  const FLAG_SERIALIZED = 0b00000001;
  const FLAG_MUST_FREE = 0b00000010;

  private $memAllocLock;
  private $statsLock;
  private $oldestZoneIndexLock;
  private $hashBucketLocks = [];
  private $zoneLocks = [];

  private $getHits = 0;
  private $getMisses = 0;

  private $memory;

  /**
   * @param $desiredSize The size of the shared memory block, which will contain all ShmCache data. If a block already exists and its size is larger, the block's size will not be reduced. If its size is smaller, it will be enlarged.
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
    $this->oldestZoneIndexLock = new ShmCache\Lock( 'oldestzoneindex' );

    if ( !$this->memAllocLock->getWriteLock() )
      throw new \Exception( 'Could not get a lock' );

    $this->memory = new ShmCache\MemoryBlock( $desiredSize, self::MAX_KEY_LENGTH );

    if ( !$this->memAllocLock->releaseLock() )
      throw new \Exception( 'Could not release a lock' );
  }

  function __destruct() {

    if ( $this->memory ) {
      $this->flushBufferedStatsToShm();
      unset( $this->memory );
    }
  }

  function set( $key, $value ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    $this->memAllocLock->getReadLock();

    $lock = $this->getHashBucketLock( $key );
    $lock->getWriteLock();
    $ret = $this->_set( $key, $value, $retIsSerialized );
    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function get( $key ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    $this->memAllocLock->getReadLock();

    $lock = $this->getHashBucketLock( $key );
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

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    $this->memAllocLock->getReadLock();

    $lock = $this->getHashBucketLock( $key );
    $lock->getReadLock();
    $ret = ( $this->getChunkOffset( $key ) > -1 );
    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function add( $key, $value ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    $this->memAllocLock->getReadLock();

    $lock = $this->getHashBucketLock( $key );
    $lock->getWriteLock();

    if ( $this->getChunkOffset( $key ) > -1 )
      $ret = false;
    else
      $ret = $this->_set( $key, $value, $retIsSerialized );

    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function replace( $key, $value ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    $this->memAllocLock->getReadLock();

    $lock = $this->getHashBucketLock( $key );
    $lock->getWriteLock();

    if ( $this->getChunkOffset( $key ) < 0 )
      $ret = false;
    else
      $ret = $this->_set( $key, $value, $retIsSerialized );

    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function increment( $key, $offset = 1, $initialValue = 0 ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $offset = (int) $offset;
    $initialValue = (int) $initialValue;

    if ( !$this->memAllocLock->getReadLock() )
      return false;

    $lock = $this->getHashBucketLock( $key );
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

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    if ( !$this->memAllocLock->getReadLock() )
      return false;

    $lock = $this->getHashBucketLock( $key );
    if ( !$lock->getWriteLock() )
      return false;

    $index = $this->getChunkOffset( $key );
    $ret = false;

    if ( $index >= 0 ) {

      $metaOffset = $this->getItemMetaOffsetByHashTableIndex( $index );

      if ( $metaOffset > 0 ) {

        $item = $this->getChunkByOffset( $metaOffset );

        if ( $item ) {
          if ( !$item[ 'valsize' ] )
            $ret = true;
          else
            $ret = $this->removeChunk( $key, $metaOffset );
        }
      }
    }

    $lock->releaseLock();

    $this->memAllocLock->releaseLock();

    return $ret;
  }

  function flush() {

    if ( !$this->memory )
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

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    if ( !$this->memAllocLock->getWriteLock() )
      return false;

    try {
      $this->flushBufferedStatsToShm();
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
      'oldestZoneIndex' => $this->getOldestZoneIndex(),
      'getHitCount' => $this->getGetHits(),
      'getMissCount' => $this->getGetMisses(),
      'itemMetadataSize' => $this->CHUNK_META_SIZE,
      'minItemValueSize' => self::MIN_VALUE_ALLOC_SIZE,
      'maxItemValueSize' => self::MAX_CHUNK_SIZE,
    ];

    for ( $i = $this->KEYS_START; $i < $this->KEYS_START + $this->KEYS_SIZE; $i += $this->LONG_SIZE ) {
      // TODO: acquire item lock?
      if ( unpack( 'l', shmop_read( $this->shm, $i, $this->LONG_SIZE ) )[ 1 ] !== 0 )
        ++$ret->usedHashTableSlots;
    }

    $ret->hashTableLoadFactor = $ret->usedHashTableSlots / $ret->availableHashTableSlots;

    for ( $i = $this->VALUES_START; $i < $this->VALUES_START + $this->VALUES_SIZE; ) {

      // TODO: acquire item lock?
      $item = $this->getChunkByOffset( $i );

      if ( $item[ 'valsize' ] ) {
        ++$ret->items;
        $ret->usedValueMemSize += $item[ 'valsize' ];
      }

      $i += $this->CHUNK_META_SIZE + $item[ 'valallocsize' ];
    }

    if ( !$this->memAllocLock->releaseLock() )
      throw new \Exception( 'Could not release a lock' );

    $ret->avgItemValueSize = ( $ret->items )
      ? $ret->usedValueMemSize / $ret->items
      : 0;

    return $ret;
  }

  private function getHashBucketLock( $key ) {

    $index = $this->memory->getBucketIndex( $key );

    if ( !isset( $this->bucketLocks[ $index ] ) )
      $this->bucketLocks[ $index ] = new ShmCache\Lock( 'bucket'. $index );

    return $this->bucketLocks[ $index ];
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

    $index = $this->getChunkOffset( $key );
    $ret = false;
    $retIsCacheHit = false;
    $retIsSerialized = false;

    if ( $index >= 0 ) {

      $metaOffset = $this->getItemMetaOffsetByHashTableIndex( $index );

      if ( $metaOffset > 0 ) {

        $item = $this->getChunkByOffset( $metaOffset );

        if ( $item ) {

          $data = shmop_read( $this->shm, $metaOffset + $this->CHUNK_META_SIZE, $item[ 'valsize' ] );

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
    $existingChunk = $this->getChunkByKey( $key );

    if ( $existingChunk ) {

      // There's enough space for the new value in the existing chunk.
      // Replace the value in-place.
      if ( $newValueSize <= $existingChunk->valallocsize ) {

        $flags = 0;
        if ( $valueIsSerialized )
          $flags |= self::FLAG_SERIALIZED;

        $existingChunk->valsize = $newValueSize;
        $existingChunk->flags = $flags;

        if ( !$this->writeChunkValue( $chunk->_startOffset, $value ) )
          goto error;

        goto success;
      }
      // The new value is too large to fit into the existing item's spot, and
      // would overwrite 1 or more items to the right of it. We'll instead
      // remove the existing item, and handle this as a new value, so that this
      // item will replace 1 or more of the _oldest_ items (that are pointed to
      // by the ring buffer pointer).
      else {
        if ( !$this->removeChunk( $existingChunk ) )
          goto error;
      }
    }

    // Note: whenever we cannot store the value to the cache, we remove any
    // existing item with the same key (in removeChunk() above). This emulates Memcached:
    // https://github.com/memcached/memcached/wiki/Performance#how-it-handles-set-failures
    if ( $newValueSize > $this->MAX_CHUNK_SIZE ) {
      trigger_error( 'Item "'. rawurlencode( $key ) .'" is too large ('. round( $newValueSize / 1000, 2 ) .' KB) to cache' );
      goto error;
    }

    $newestZoneIndex = $this->getNewestZoneIndex();
    if ( $newestZoneIndex < 0 )
      goto error;

    $zoneMeta = $this->getZoneMetaByIndex( $newestZoneIndex );
    $zoneFreeSpace = $this->getZoneFreeSpace( $zoneMeta );

    // The new value doesn't fit into the oldest zone. Make space for the new
    // value by evicting all chunks in the oldest zone.
    if ( $zoneFreeSpace < $newValueSize ) {

      // TODO: oldestZoneIndexLock
      $oldestZoneIndex = $this->getOldestZoneIndex();
      if ( $oldestZoneIndex < 0 )
        goto error;

      $zoneMeta = $this->getZoneMetaByIndex( $oldestZoneIndex );
      if ( !$this->removeAllChunksInZone( $zoneMeta ) )
        goto error;

      $this->setOldestZoneIndex( $oldestZoneIndex + 1 );
    }

    $leftOverSize = $zoneFreeSpace - $newValueSize;
    $freeChunk = $this->getChunkByOffset( $this->getZoneFreeChunkOffset( $zoneMeta ) );

    // TODO TODO TODO

    // Split the chunk into two, if there is enough space left over
    if ( $leftOverSize >= $this->CHUNK_META_SIZE + self::MIN_VALUE_ALLOC_SIZE ) {

      $leftOverChunkOffset = $freeChunk->_endOffset + $newValueSize;
      $leftOverChunkValAllocSize = $leftOverSize - $this->CHUNK_META_SIZE;

      $leftOverChunk = $this->getChunkByOffset( $leftOverChunkOffset );
      $leftOverChunk->key = '';
      $leftOverChunk->hashnext = 0;
      $leftOverChunk->valallocsize = $this->MAX_CHUNK_SIZE - $this->CHUNK_META_SIZE;
      $leftOverChunk->valsize = 0;
      $leftOverChunk->flags = 0;

      $freeChunk->valallocsize -= $leftOverSize;
    }

    $flags = 0;
    if ( $valueIsSerialized )
      $flags |= self::FLAG_SERIALIZED;

    $freeChunk->key = $key;
    $freeChunk->valsize = $newValueSize;
    $freeChunk->flags = $flags;

    if ( !$this->writeChunkValue( $freeChunk, $value ) )
      goto error;

    if ( !$this->linkChunkToHashTable( $freeChunk ) )
      goto error;

    $zoneMeta->usedspace += $freeChunk->_size + $freeChunk->valallocsize;

    success:
    return true;

    error:
    return false;
  }

  private function sanitizeKey( $key ) {
    return substr( $key, 0, self::MAX_KEY_LENGTH );
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


