<?php

namespace Crusse\ShmCache;

/**
 * Represents a shared memory block for ShmCache. Acts as a singleton: if you
 * instantiate this multiple times, you'll always get back the same shared
 * memory block. This makes sure that all ShmCache instances in aggregate always
 * allocate only the maximum of each instance's desired amount of memory, and
 * no more.
 *
 * See LOCKING.md for information about the memory structure and locking.
 */
class Memory {

  // Total amount of space to allocate for the shared memory block. This will
  // contain both the keys area and the zones area, so the amount allocatable
  // for zones will be slightly smaller.
  const DEFAULT_CACHE_SIZE = 134217728; // 128 MB
  const SAFE_AREA_SIZE = 1024;
  // Don't let value allocations become smaller than this, to reduce fragmentation
  const MIN_VALUE_ALLOC_SIZE = 128;
  // The largest cache item can be this big, minus a few bytes for zone metadata.
  // See MAX_CHUNK_SIZE for the actual largest cache item size.
  const ZONE_SIZE = 1048576; // 1 MiB
  // If a key string is longer than this, the key is truncated silently
  const MAX_KEY_LENGTH = 200;
  // The more hash table buckets there are, the more memory is used, but the
  // faster it is to find a hash table entry
  const HASH_BUCKET_COUNT = 512;
  // Used to resolve deadlocks
  const TRYLOCK_TIMEOUT = 3;

  const FLAG_SERIALIZED = 0b00000001;

  private $shm; // resource
  private $shmKey; // int

  private $locks; // LockManager

  public $metaArea; // MemoryArea
  public $statsArea; // MemoryArea
  public $hashBucketArea; // MemoryArea
  public $zonesArea; // MemoryArea

  private $statsProto; // ShmBackedObject
  private $zoneMetaProto; // ShmBackedObject
  private $chunkProto; // ShmBackedObject

  function __construct( $desiredSize ) {

    $this->locks = LockManager::getInstance();

    // PHP doesn't allow complex expressions in class consts so we'll create
    // these "consts" here
    $this->LONG_SIZE = strlen( pack( 'l', 1 ) ); // i.e. sizeof(long) in C
    $this->CHAR_SIZE = strlen( pack( 'c', 1 ) ); // i.e. sizeof(char) in C

    $this->openMemBlock( $desiredSize, $retIsNewBlock );

    $this->metaArea = new MemoryArea(
      $this->shm,
      0,
      1024 // Oversized for future needs
    );

    $this->statsArea = new MemoryArea(
      $this->shm,
      $this->metaArea->endOffset + self::SAFE_AREA_SIZE,
      1024 // Oversized for future needs
    );

    $this->hashBucketArea = new MemoryArea(
      $this->shm,
      $this->statsArea->endOffset + self::SAFE_AREA_SIZE,
      self::HASH_BUCKET_COUNT * $this->LONG_SIZE
    );

    $this->zonesArea = new MemoryArea(
      $this->shm,
      $this->hashBucketArea->endOffset + self::SAFE_AREA_SIZE,
      shmop_size( $this->shm ) - ( $this->hashBucketArea->endOffset + self::SAFE_AREA_SIZE )
    );

    $this->populateSizes();

    if ( $retIsNewBlock )
      $this->clearMemBlock();

    $this->defineShmObjectPrototypes();

    if ( $retIsNewBlock )
      $this->initializeMemBlock();
  }

  private function defineShmObjectPrototypes() {

    // You must hold the stats lock when reading from or writing to any
    // property of this object
    $this->statsProto = ShmBackedObject::createPrototype( $this->statsArea, [
      'gethits' => [
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ],
      'getmisses' => [
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ]
    ] );

    // You must hold the zone's lock when reading from or writing to any
    // property of this object
    $this->zoneMetaProto = ShmBackedObject::createPrototype( $this->zonesArea, [
      'usedspace' => [
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ]
    ] );

    // You must hold a bucket lock when reading from or writing to the
    // "key" and "hashnext" properties of this object.
    //
    // You must hold a zone lock when reading from or writing to the
    // "valallocsize", "valsize" and "flags" properties of this object.
    $this->chunkProto = ShmBackedObject::createPrototype( $this->zonesArea, [
      'key' => [
        'size' => self::MAX_KEY_LENGTH,
        'packformat' => 'A'. self::MAX_KEY_LENGTH
      ],
      'hashnext' => [
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ],
      'valallocsize' => [
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ],
      'valsize' => [
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ],
      'flags' => [
        'size' => $this->CHAR_SIZE,
        'packformat' => 'c'
      ]
    ] );
  }

  function __destruct() {

    if ( $this->shm )
      shmop_close( $this->shm );
  }

  function getStatsObject() {
    return $this->statsProto->createInstance( 0 );
  }

  /**
   * You must hold a bucket lock when calling this.
   */
  function getChunkByKey( $key ) {

    $bucketIndex = $this->getBucketIndex( $key );
    assert( $this->locks->getBucketLock( $bucketIndex )->isLockedForRead() );
    $chunkOffset = $this->getBucketHeadChunkOffset( $bucketIndex );

    while ( $chunkOffset > 0 ) {

      $zoneLock = $this->locks->getZoneLock( $this->getZoneIndexForOffset( $chunkOffset ) );
      if ( !$zoneLock->lockForRead() )
        break;

      $chunk = $this->getChunkByOffset( $chunkOffset );

      // Chunk should not be free if it's in the hash table
      assert( $chunk->valallocsize > 0 );
      // Chunk should never point back to itself
      assert( $chunk->hashnext !== $chunkOffset );

      if ( $chunk->key === $key ) {
        // TODO: does the release cause a race condition if the chunk is used
        // afterwards? Reading a chunk requires a zone lock.
        $zoneLock->releaseRead();
        return $chunk;
      }

      $chunkOffset = $chunk->hashnext;

      $zoneLock->releaseRead();
    }

    return null;
  }

  /**
   * You must hold a bucket lock when calling this.
   */
  function addChunk( $key, $value, $valueSize, $valueIsSerialized ) {

    assert( $this->locks->getBucketLock( $this->getBucketIndex( $key ) )->isLockedForWrite() );
    assert( $valueSize >= 0 );

    if ( $valueSize > $this->MAX_VALUE_SIZE ) {
      trigger_error( 'Item "'. rawurlencode( $key ) .'" is too large ('. round( $valueSize / 1000, 2 ) .' KB) to cache' );
      return false;
    }

    $ret = false;
    $zoneLock = null;

    if ( !$this->locks::$oldestZoneIndex->lockForWrite() )
      return false;

    $newestZoneIndex = $this->getNewestZoneIndex();
    $zoneMeta = $this->getZoneMetaByIndex( $newestZoneIndex );

    $zoneLock = $this->locks->getZoneLock( $newestZoneIndex );
    if ( !$zoneLock->lockForWrite() ) {
      $zoneLock = null;
      goto cleanup;
    }

    $zoneFreeSpace = $this->getZoneFreeSpace( $zoneMeta );
    $requiredSize = $this->CHUNK_META_SIZE + $valueSize;

    assert( $requiredSize <= $this->MAX_CHUNK_SIZE );

    $freeChunk = null;

    // There is still space left in the newest zone for this value
    if ( $zoneFreeSpace >= $requiredSize ) {

      $freeChunkOffset = $this->getZoneFreeChunkOffset( $zoneMeta );
      $freeChunk = $this->getChunkByOffset( $freeChunkOffset );

      // Make sure the chunk pointed to by the zone's stack pointer (i.e. the
      // zone's free space) is large enough (i.e. it's merged with chunks that
      // come after it in the zone)
      if ( $freeChunk->valallocsize < $requiredSize ) {
        if ( !$this->mergeChunkWithNextFreeChunks( $freeChunk ) )
          goto cleanup;
      }
    }
    // The new value doesn't fit into the newest zone. Make space for the new
    // value by evicting all chunks in the oldest zone.
    else {

      // Unlock newest zone, so that we can lock the oldest zone (only one zone
      // lock should be held at a time to prevent deadlocks)
      $zoneLock->releaseWrite();
      $zoneLock = null;

      $oldestZoneIndex = $this->getOldestZoneIndex();
      $zoneMeta = $this->getZoneMetaByIndex( $oldestZoneIndex );

      $zoneLock = $this->locks->getZoneLock( $oldestZoneIndex );
      if ( !$zoneLock->lockForWrite() ) {
        $zoneLock = null;
        goto cleanup;
      }

      if ( !$this->removeAllChunksInZone( $zoneMeta ) )
        goto cleanup;

      if ( !$this->setOldestZoneIndex( $oldestZoneIndex + 1 ) )
        goto cleanup;

      $freeChunk = $this->getChunkByOffset( $this->getZoneFreeChunkOffset( $zoneMeta ) );
    }

    if ( !$this->writeChunkValue( $freeChunk, $value ) )
      goto cleanup;

    $flags = 0;
    if ( $valueIsSerialized )
      $flags |= self::FLAG_SERIALIZED;

    $freeChunk->key = $key;
    $freeChunk->flags = $flags;
    $freeChunk->hashnext = 0;
    $freeChunk->valsize = $valueSize;

    if ( !$this->linkChunkToHashTable( $freeChunk ) ) {
      trigger_error( 'Could not link chunk for key "'. $key .'" to hash table' );
      goto cleanup;
    }

    if ( !$this->splitChunkForFreeSpace( $freeChunk ) )
      goto cleanup;

    $zoneMeta->usedspace = ( $freeChunk->_endOffset - $zoneMeta->_endOffset ) + $freeChunk->valallocsize;

    assert( $zoneMeta->usedspace > 0 );
    assert( $zoneMeta->usedspace <= $this->MAX_CHUNK_SIZE );

    $ret = true;

    cleanup:

    if ( $zoneLock )
      $zoneLock->releaseWrite();

    $this->locks::$oldestZoneIndex->releaseWrite();

    return $ret;
  }

  /**
   * You must hold a bucket lock when calling this.
   */
  function removeChunk( ShmBackedObject $chunk ) {

    assert( $this->locks->getBucketLock( $this->getBucketIndex( $chunk->key ) )->isLockedForWrite() );

    $zoneLock = $this->locks->getZoneLock( $this->getZoneIndexForOffset( $chunk->_startOffset ) );
    if ( !$zoneLock->lockForWrite() )
      return false;

    $ret = false;

    // Already free
    if ( !$chunk->valsize ) {
      $ret = true;
      goto cleanup;
    }

    if ( !$this->unlinkChunkFromHashTable( $chunk ) )
      goto cleanup;

    // Free the chunk
    $chunk->valsize = 0;

    if ( !$this->mergeChunkWithNextFreeChunks( $chunk ) )
      goto cleanup;

    $nextChunkOffset = $chunk->_endOffset + $chunk->valallocsize;
    $zoneMeta = $this->getZoneMetaForChunk( $chunk );
    $zoneFreeChunkOffset = $this->getZoneFreeChunkOffset( $zoneMeta );

    // This is the top chunk in the zone's chunk stack. Adjust the zone's chunk
    // stack pointer.
    if ( $zoneFreeChunkOffset === $nextChunkOffset || $zoneFreeChunkOffset === 0 )
      $zoneMeta->usedspace -= $chunk->_size + $chunk->valallocsize;

    assert( $zoneMeta->usedspace >= 0 );
    assert( $zoneMeta->usedspace <= $this->MAX_CHUNK_SIZE );

    $ret = true;

    cleanup:
    $zoneLock->releaseWrite();

    return $ret;
  }

  function getChunkValue( ShmBackedObject $chunk ) {

    $data = $this->zonesArea->read( $chunk->_endOffset, $chunk->valsize );

    if ( $data === false ) {
      trigger_error( 'Could not read chunk value' );
      return false;
    }

    return $data;
  }

  function replaceChunkValue( ShmBackedObject $chunk, $value, $valueSize, $valueIsSerialized ) {

    $zoneLock = $this->locks->getZoneLock( $this->getZoneIndexForOffset( $chunk->_startOffset ) );
    if ( !$zoneLock->lockForWrite() )
      return false;

    $ret = false;

    // There's enough space for the new value in the existing chunk.
    // Replace the value in-place.
    if ( $valueSize <= $chunk->valallocsize ) {

      if ( !$this->writeChunkValue( $chunk, $value ) )
        goto cleanup;

      $flags = 0;
      if ( $valueIsSerialized )
        $flags |= self::FLAG_SERIALIZED;

      $chunk->valsize = $valueSize;
      $chunk->flags = $flags;

      $ret = true;
    }

    cleanup:
    $zoneLock->releaseWrite();

    return $ret;
  }

  /**
   * Returns an index in the key hash table, to the first element of the
   * hash table item cluster (i.e. bucket) for the given key.
   *
   *
   * @return int
   */
  function getBucketIndex( $key ) {

    // Read as a 32-bit unsigned long
    // TODO: unpack() is slow. Is there an alternative?
    //
    // The hash function must result in as uniform a distribution of bits
    // as possible, over all the possible $key values. CRC32 looks pretty good:
    // http://michiel.buddingh.eu/distribution-of-hash-values
    //
    return unpack( 'L', hash( 'crc32', $key, true ) )[ 1 ] % self::HASH_BUCKET_COUNT;
  }

  function flush() {
    $this->clearMemBlock();
    $this->initializeMemBlock();
  }

  function destroy() {

    $deleted = shmop_delete( $this->shm );

    if ( !$deleted ) {
      throw new \Exception( 'Could not destroy the memory block. Try running the \'ipcrm\' command as a super-user to remove the memory block listed in the output of \'ipcs\'.' );
    }

    shmop_close( $this->shm );
    $this->shm = null;
  }

  private function openMemBlock( $desiredSize, &$retIsNewBlock ) {

    $blockKey = $this->getShmopKey();
    $mode = 0777;

    // The 'size' parameter is ignored by PHP when 'w' is used:
    // https://github.com/php/php-src/blob/9e709e2fa02b85d0d10c864d6c996e3368e977ce/ext/shmop/shmop.c#L183
    $this->shm = @shmop_open( $blockKey, "w", $mode, 0 );

    $blockExisted = (bool) $this->shm;

    // Try to re-create an existing block with a larger size, if the block is
    // smaller than the desired size. This fails (at least on Linux) if the
    // Unix user trying to delete the block is different than the user that
    // created the block.
    if ( $blockExisted && $desiredSize ) {

      $currentSize = shmop_size( $this->shm );

      if ( $currentSize < $desiredSize ) {

        // Destroy and recreate the memory block with a larger size
        if ( shmop_delete( $this->shm ) ) {
          shmop_close( $this->shm );
          $this->shm = null;
          $blockExisted = false;
        }
        else {
          trigger_error( 'Could not delete the memory block. Falling back to using the existing, smaller-than-desired block.' );
        }
      }
    }

    // No existing memory block. Create a new one.
    if ( !$blockExisted )
      $this->createMemBlock( $desiredSize );

    // A new memory block. Write initial values.
    $retIsNewBlock = !$blockExisted;

    return (bool) $this->shm;
  }

  // TODO: determine these from the ShmBackedObject prototype specs?
  private function populateSizes() {

    $allocatedSize = $this->LONG_SIZE;
    $hashNextSize = $this->LONG_SIZE;
    $valueSize = $this->LONG_SIZE;
    $flagsSize = $this->CHAR_SIZE;
    $this->CHUNK_META_SIZE = self::MAX_KEY_LENGTH + $hashNextSize + $allocatedSize + $valueSize + $flagsSize;

    $this->ZONE_META_SIZE = $this->LONG_SIZE;

    $this->MAX_CHUNK_SIZE = self::ZONE_SIZE - $this->ZONE_META_SIZE;
    $this->MIN_CHUNK_SIZE = $this->CHUNK_META_SIZE + self::MIN_VALUE_ALLOC_SIZE;
    $this->MAX_VALUE_SIZE = $this->MAX_CHUNK_SIZE - $this->CHUNK_META_SIZE;

    // Note: we cast to (int) so that this is not a float value. Otherwise
    // PHP's === doesn't work when comparing with ints, because 42 !== 42.0.
    $this->ZONE_COUNT = (int) floor( $this->zonesArea->size / self::ZONE_SIZE );

    $this->MAX_CHUNKS_PER_ZONE = (int) floor( ( self::ZONE_SIZE - $this->ZONE_META_SIZE ) / $this->MIN_CHUNK_SIZE );
    $this->MAX_CHUNKS = (int) ( $this->MAX_CHUNKS_PER_ZONE * $this->ZONE_COUNT );

    $this->MAX_TOTAL_VALUE_SIZE = (int) ( $this->ZONE_COUNT * $this->MAX_VALUE_SIZE );

    $this->SHM_SIZE = (int) shmop_size( $this->shm );
  }

  private function getShmopKey() {

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

  private function createMemBlock( $desiredSize ) {

    $blockKey = $this->getShmopKey();
    $mode = 0777;
    $this->shm = shmop_open( $blockKey, "n", $mode, ( $desiredSize ) ? $desiredSize : self::DEFAULT_CACHE_SIZE );

    return (bool) $this->shm;
  }

  /**
   * Write NULs over the whole memory block.
   */
  private function clearMemBlock() {

    $shmSize = shmop_size( $this->shm );
    $memoryWriteBatch = 1024 * 1024 * 4;
    $data = pack( 'x'. $memoryWriteBatch );

    // Clear all bytes to NUL in the whole shared memory block
    for ( $i = 0; $i < $shmSize; $i += $memoryWriteBatch ) {

      // Last chunk might have to be smaller
      if ( $i + $memoryWriteBatch > $shmSize ) {
        $memoryWriteBatch = $shmSize - $i;
        $data = pack( 'x'. $memoryWriteBatch );
      }

      $res = shmop_write( $this->shm, $data, $i );

      if ( $res === false )
        throw new \Exception( 'Could not write NUL bytes to the memory block' );
    }
  }

  /**
   * Initialize the memory block with a single, free cache item.
   */
  private function initializeMemBlock() {

    // Initialize zones
    for ( $i = 0; $i < $this->ZONE_COUNT; $i++ ) {

      $zoneMeta = $this->getZoneMetaByIndex( $i );
      $zoneMeta->usedspace = 0;

      // Each zone starts out with a single chunk that takes up the whole space
      // of the zone
      $chunk = $this->getChunkByOffset( $i * self::ZONE_SIZE + $zoneMeta->_size );
      $chunk->key = '';
      $chunk->hashnext = 0;
      $chunk->valallocsize = $this->MAX_VALUE_SIZE;
      $chunk->valsize = 0;
      $chunk->flags = 0;

      assert( $zoneMeta->usedspace === 0 );
      assert( $chunk->valallocsize > 0 );
      assert( $chunk->valallocsize <= $this->MAX_VALUE_SIZE );
    }

    $this->setOldestZoneIndex( $this->ZONE_COUNT - 1 );
  }

  private function getNewestZoneIndex() {

    $index = $this->getOldestZoneIndex() - 1;

    if ( $index === -1 )
      $index = $this->ZONE_COUNT - 1;

    return $index;
  }

  /**
   * You must hold the oldest zone index lock when calling this.
   */
  private function getOldestZoneIndex() {

    assert( $this->locks::$oldestZoneIndex->isLockedForRead() );

    $data = shmop_read( $this->shm, $this->metaArea->startOffset + $this->LONG_SIZE, $this->LONG_SIZE );
    $index = unpack( 'l', $data )[ 1 ];

    if ( !is_int( $index ) ) {
      trigger_error( 'Could not find the oldest zone index' );
      return -1;
    }

    assert( $index >= 0 );
    assert( $index < $this->ZONE_COUNT );

    return $index;
  }

  /**
   * You must hold the oldest zone index lock when calling this.
   */
  private function setOldestZoneIndex( $index ) {

    assert( $this->locks::$oldestZoneIndex->isLockedForWrite() );

    // Allow setting index to 1 too small or 1 too large, and wrap them around
    if ( $index === $this->ZONE_COUNT )
      $index = 0;
    else if ( $index === -1 )
      $index = $this->ZONE_COUNT - 1;

    assert( $index >= 0 );
    assert( $index < $this->ZONE_COUNT );

    $data = pack( 'l', $index );
    $ret = shmop_write( $this->shm, $data, $this->metaArea->startOffset + $this->LONG_SIZE );

    if ( $ret === false ) {
      trigger_error( 'Could not write the oldest zone index' );
      return false;
    }

    return true;
  }

  /**
   * You must hold a bucket lock when calling this.
   */
  private function findHashTablePrevChunkOffset( ShmBackedObject $chunk, $bucketHeadChunkOffset ) {

    assert( $this->locks->getBucketLock( $this->getBucketIndex( $chunk->key ) )->isLockedForRead() );

    $chunkOffset = $chunk->_startOffset;
    $currentOffset = $bucketHeadChunkOffset;

    while ( $currentOffset ) {
      $testChunk = $this->getChunkByOffset( $currentOffset );
      // TODO: lock the zone? There's a problem: a zone is already locked when
      // findHashTablePrevChunkOffset() is called, and currently we don't allow
      // locking two zones simultaneously due to deadlocks (zone A -> zone B,
      // versus zone B -> zone A in another process).
      $nextOffset = $testChunk->hashnext;

      if ( $nextOffset === $chunkOffset ) {
        return $currentOffset;
      }

      $currentOffset = $nextOffset;
    }

    return 0;
  }

  /**
   * @return int A chunk offset relative to the zonesArea
   */
  private function getBucketHeadChunkOffset( $bucketIndex ) {

    assert( $bucketIndex >= 0 );
    assert( $bucketIndex < self::HASH_BUCKET_COUNT );

    $data = shmop_read(
      $this->shm,
      $this->hashBucketArea->startOffset + $bucketIndex * $this->LONG_SIZE,
      $this->LONG_SIZE
    );

    if ( $data === false ) {
      trigger_error( 'Could not read head chunk offset for bucket "'. $index .'"' );
      return 0;
    }

    $chunkOffset = unpack( 'l', $data )[ 1 ];

    assert( $chunkOffset >= 0 );
    assert( $chunkOffset <= $this->zonesArea->size - $this->MIN_CHUNK_SIZE );

    return $chunkOffset;
  }

  private function setBucketHeadChunkOffset( $bucketIndex, $chunkOffset ) {

    assert( $bucketIndex >= 0 );
    assert( $bucketIndex < self::HASH_BUCKET_COUNT );
    assert( $chunkOffset >= 0 );
    assert( $chunkOffset <= $this->zonesArea->size - $this->MIN_CHUNK_SIZE );

    $ret = shmop_write(
      $this->shm,
      pack( 'l', $chunkOffset ),
      $this->hashBucketArea->startOffset + $bucketIndex * $this->LONG_SIZE
    );

    if ( $ret === false ) {
      trigger_error( 'Could not write item key' );
      return false;
    }

    return true;
  }

  /**
   * You must hold a bucket lock when calling this.
   */
  private function linkChunkToHashTable( ShmBackedObject $chunk ) {

    assert( $chunk->_startOffset > 0 );
    assert( $chunk->_startOffset <= $this->zonesArea->size - $this->MIN_CHUNK_SIZE );

    $bucketIndex = $this->getBucketIndex( $chunk->key );
    assert( $this->locks->getBucketLock( $bucketIndex )->isLockedForWrite() );
    $existingChunkOffset = $this->getBucketHeadChunkOffset( $bucketIndex );

    if ( !$existingChunkOffset )
      return $this->setBucketHeadChunkOffset( $bucketIndex, $chunk->_startOffset );

    while ( $existingChunkOffset > 0 ) {

      $existingChunk = $this->getChunkByOffset( $existingChunkOffset );
      $hashNext = $existingChunk->hashnext;

      if ( !$hashNext ) {
        // Check that we're not trying to link an already linked chunk to
        // the hash table
        assert( $existingChunk->_startOffset !== $chunk->_startOffset );

        $existingChunk->hashnext = $chunk->_startOffset;
        return true;
      }

      $existingChunkOffset = $hashNext;
    }

    return false;
  }

  /**
   * You must hold a bucket lock and a zone lock when calling this.
   */
  private function unlinkChunkFromHashTable( ShmBackedObject $chunk ) {

    $bucketIndex = $this->getBucketIndex( $chunk->key );
    assert( $this->locks->getBucketLock( $bucketIndex )->isLockedForWrite() );
    $bucketHeadChunkOffset = $this->getBucketHeadChunkOffset( $bucketIndex );

    if ( !$bucketHeadChunkOffset ) {
      trigger_error( 'The hash table bucket for key "'. rawurlencode( $chunk->key ) .'" is empty' );
      return false;
    }

    // The chunk to unlink is the head chunk of the hash table
    if ( $chunk->_startOffset === $bucketHeadChunkOffset ) {

      // Check that the chunk doesn't point to itself
      assert( $chunk->hashnext !== $chunk->_startOffset );

      // Note that $chunk->hashnext is 0 here when the hash table bucket
      // has no more chunks in it
      return $this->setBucketHeadChunkOffset( $bucketIndex, $chunk->hashnext );
    }

    // $chunk is not the head chunk of the hash table bucket. Find the chunk
    // that immediately precedes $chunk in the bucket.
    $prevChunkOffset = $this->findHashTablePrevChunkOffset( $chunk, $bucketHeadChunkOffset );
    if ( !$prevChunkOffset ) {
      trigger_error( 'Found no previous chunk in the hash table bucket for key "'. rawurlencode( $chunk->key ) .'"' );
      return false;
    }

    $prevChunk = $this->getChunkByOffset( $prevChunkOffset );

    //               ________________
    //              |                v
    // Link [prevChunk currentChunk nextChunk]
    //
    $prevChunk->hashnext = $chunk->hashnext;
    $chunk->hashnext = 0;

    return true;
  }

  /**
   * Increases the chunk's valallocsize.
   *
   * You must hold a bucket lock and a zone lock when calling this.
   */
  private function mergeChunkWithNextFreeChunks( ShmBackedObject $chunk ) {

    $zoneIndex = $this->getZoneIndexForOffset( $chunk->_startOffset );

    $newAllocSize = $origAllocSize = $chunk->valallocsize;
    $nextChunkOffset = $chunk->_endOffset + $origAllocSize;

    while ( $nextChunkOffset ) {

      // Hit the next zone
      if ( $this->getZoneIndexForOffset( $nextChunkOffset ) !== $zoneIndex )
        break;

      $nextChunk = $this->getChunkByOffset( $nextChunkOffset );

      // Found a non-free chunk
      if ( $nextChunk->valsize )
        break;

      $newAllocSize += $nextChunk->_size + $nextChunk->valallocsize;
      $nextChunkOffset = $nextChunkOffset + $nextChunk->_size + $nextChunk->valallocsize;
    }

    if ( $newAllocSize !== $origAllocSize ) {
      assert( $newAllocSize > $origAllocSize );
      $chunk->valallocsize = $newAllocSize;
    }

    assert( $chunk->valallocsize > 0 );
    assert( $chunk->valallocsize <= $this->MAX_VALUE_SIZE );

    return true;
  }

  /**
   * You must hold write locks for the zone and the oldestZoneIndex when
   * calling this.
   */
  private function removeAllChunksInZone( ShmBackedObject $zoneMeta ) {

    // TODO: assert() that we have the right locks (in other functions, too)

    $zoneIndex = $this->getZoneIndexForOffset( $zoneMeta->_startOffset );
    $zoneLock = $this->locks->getZoneLock( $zoneIndex );

    $ret = false;
    $bucketLock = null;
    $lastPossibleChunkOffset = $this->lastAllowedChunkOffsetInZone( $zoneMeta );
    $chunk = $firstChunk = $this->getChunkByOffset( $zoneMeta->_endOffset );

    while ( true ) {

      assert( $chunk->valallocsize > 0 );
      assert( $chunk->valallocsize <= $this->MAX_VALUE_SIZE );

      // Not already freed
      if ( $chunk->valsize ) {

        $bucketLock = $this->locks->getBucketLock( $this->getBucketIndex( $chunk->key ) );
        $startTime = microtime( true );

        // Attempt to get a try-lock until a timeout. We use a try-lock rather
        // than a regular lock to prevent a deadlock, as someone might have locked
        // the bucket already, and is trying to lock the zone.
        while ( !$bucketLock->lockForWrite( true ) ) {

          $zoneLock->releaseWrite();
          $zoneLock = null;

          $this->locks::$oldestZoneIndex->releaseWrite();

          if ( microtime( true ) - $startTime > self::TRYLOCK_TIMEOUT ) {
            trigger_error( 'Try-lock timeout' );
            $bucketLock = null;
            goto cleanup;
          }

          if ( !$this->locks::$oldestZoneIndex->lockForWrite() )
            goto cleanup;

          $zoneLock = $this->locks->getZoneLock( $zoneIndex );

          if ( !$zoneLock->lockForWrite() ) {
            $zoneLock = null;
            goto cleanup;
          }
        }

        if ( !$this->unlinkChunkFromHashTable( $chunk ) )
          goto cleanup;

        $bucketLock->releaseWrite();
        $bucketLock = null;

        $chunk->valsize = 0;
      }

      $nextChunkOffset = $chunk->_endOffset + $chunk->valallocsize;

      if ( $nextChunkOffset > $lastPossibleChunkOffset )
        break;

      $chunk = $this->getChunkByOffset( $nextChunkOffset );
    }

    $firstChunk->valallocsize = $this->MAX_VALUE_SIZE;
    assert( $firstChunk->valallocsize > 0 );
    assert( $firstChunk->valallocsize <= $this->MAX_VALUE_SIZE );

    // Reset the zone's chunk stack pointer
    $zoneMeta->usedspace = 0;

    assert( $zoneMeta->usedspace === 0 );
    assert( $this->getZoneFreeSpace( $zoneMeta ) === $this->MAX_CHUNK_SIZE );

    $ret = true;

    cleanup:

    if ( $bucketLock )
      $bucketLock->releaseWrite();

    return $ret;
  }

  /**
   * Split the chunk into two, if the difference between valsize and
   * valallocsize is large enough.
   *
   * You must hold a bucket lock and a zone lock when calling this.
   */
  private function splitChunkForFreeSpace( ShmBackedObject $chunk ) {

    $zoneIndex = $this->getZoneIndexForOffset( $chunk->_startOffset );
    assert( $this->locks->getZoneLock( $zoneIndex )->isLockedForWrite() );
    assert( $this->locks->getBucketLock( $this->getBucketIndex( $chunk->key ) )->isLockedForWrite() );

    $ret = false;
    $valSize = $chunk->valsize;

    $leftOverSize = $chunk->valallocsize - $valSize;

    if ( $leftOverSize >= $this->MIN_CHUNK_SIZE ) {

      $leftOverChunk = $this->getChunkByOffset( $chunk->_endOffset + $valSize );
      $leftOverChunk->key = '';
      $leftOverChunk->hashnext = 0;
      $leftOverChunk->valallocsize = $leftOverSize - $this->CHUNK_META_SIZE;
      $leftOverChunk->valsize = 0;
      $leftOverChunk->flags = 0;

      assert( $leftOverChunk->valallocsize > 0 );
      assert( $leftOverChunk->valallocsize <= $this->MAX_VALUE_SIZE );

      $chunk->valallocsize -= $leftOverSize;

      assert( $chunk->valallocsize === $valSize );

      if ( !$this->mergeChunkWithNextFreeChunks( $leftOverChunk ) )
        goto cleanup;

      assert( $leftOverChunk->valallocsize > 0 );
    }

    $ret = true;

    cleanup:

    return $ret;
  }

  /**
   * @param int $chunkOffset Offset relative to the zonesArea
   *
   * @return ShmBackedObject
   */
  private function getChunkByOffset( $chunkOffset ) {
    assert( $chunkOffset > 0 );
    assert( $chunkOffset <= $this->zonesArea->size - $this->MIN_CHUNK_SIZE );
    return $this->chunkProto->createInstance( $chunkOffset );
  }

  private function writeChunkValue( ShmBackedObject $chunk, $value ) {

    if ( !$this->zonesArea->write( $chunk->_endOffset, $value ) ) {
      trigger_error( 'Could not write chunk value' );
      return false;
    }

    return true;
  }

  private function getZoneMetaByIndex( $zoneIndex ) {
    assert( $zoneIndex >= 0 );
    assert( $zoneIndex < $this->ZONE_COUNT );
    return $this->zoneMetaProto->createInstance( $zoneIndex * self::ZONE_SIZE );
  }

  /**
   * @param int $offset Relative to the zonesArea start
   */
  private function getZoneIndexForOffset( $offset ) {
    assert( $offset >= 0 );
    assert( $offset <= $this->zonesArea->size - $this->MIN_CHUNK_SIZE );
    return (int) floor( $offset / self::ZONE_SIZE );
  }

  private function getZoneMetaForChunk( ShmBackedObject $chunk ) {
    return $this->getZoneMetaByIndex( $this->getZoneIndexForOffset( $chunk->_startOffset ) );
  }

  private function getZoneFreeSpace( ShmBackedObject $zoneMeta ) {

    $freeSpace = $this->MAX_CHUNK_SIZE - $zoneMeta->usedspace;

    assert( $freeSpace >= 0 );
    assert( $freeSpace <= $this->MAX_CHUNK_SIZE );

    return $freeSpace;
  }

  /**
   * Returns the offset of the chunks area stack pointer of the zone. The stack
   * pointer points to the first free chunk in the zone.
   *
   * Returns 0 if there's no free chunk space in the zone.
   *
   * You must hold a zone lock when calling this.
   *
   * @return int Offset relative to the zonesArea
   */
  private function getZoneFreeChunkOffset( ShmBackedObject $zoneMeta ) {

    $zoneIndex = $this->getZoneIndexForOffset( $zoneMeta->_startOffset );
    assert( $this->locks->getZoneLock( $zoneIndex )->isLockedForRead() );

    $freeChunkOffset = $zoneMeta->_endOffset + $zoneMeta->usedspace;

    assert( $freeChunkOffset >= $zoneMeta->_endOffset );
    // We allow $freeChunkOffset to point all the way to the first byte of
    // the next zone, but no further
    assert( $freeChunkOffset <= $zoneMeta->_startOffset + self::ZONE_SIZE );

    // Next chunk offset points to the next zone, i.e. there's no free space in
    // this zone
    if ( $this->getZoneIndexForOffset( $freeChunkOffset ) !== $zoneIndex )
      return 0;

    // $freeChunkOffset points to the given zone. Make sure it doesn't point
    // any further than the last allowed chunk offset in this zone.
    assert( $freeChunkOffset <= $this->lastAllowedChunkOffsetInZone( $zoneMeta ) );

    return $freeChunkOffset;
  }

  private function lastAllowedChunkOffsetInZone( ShmBackedObject $zoneMeta ) {
    return $zoneMeta->_startOffset + self::ZONE_SIZE - $this->MIN_CHUNK_SIZE;
  }
}

