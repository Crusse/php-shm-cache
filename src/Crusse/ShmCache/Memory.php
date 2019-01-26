<?php

namespace Crusse\ShmCache;

/**
 * Represents a shared memory block for ShmCache. Acts as a singleton: if you
 * instantiate this multiple times, you'll always get back the same shared
 * memory block. This makes sure that all ShmCache instances in aggregate always
 * allocate only the maximum of each instance's desired amount of memory, and
 * no more.
 *
 * Memory block structure
 * ----------------------
 *
 * Metadata area:
 * [oldestzoneindex]
 *
 *     The item count is the amount of currently stored items.
 *
 *     The oldestzoneindex points to the oldest zone in the zones area.
 *     When the cache is full, the oldest zone is evicted.
 *
 * Stats area:
 * [gethits][getmisses]
 *
 * Hash table bucket area:
 * [itemmetaoffset,itemmetaoffset,...]
 *
 *     Our hash table uses "separate chaining". The itemmetaoffset points to
 *     a chunk in a zone's chunksarea.
 *
 * Zones area:
 * [[usedspace,chunksarea],[usedspace,chunksarea],...]
 *
 *     The zones area is a ring buffer. The oldestzoneindex points to
 *     the oldest zone. Each zone is a stack of chunks.
 *
 *     A zone's usedspace can be used to calculate the first free chunk in
 *     that zone. All chunks up to that point is memory in use; all chunks
 *     after that point is free space. usedspace is therefore essentially
 *     a stack pointer.
 *
 *     Each zone is roughly in the order in which the zones were created, so
 *     that we can easily find the oldest zones for eviction, to make space for
 *     new cache items.
 *
 *     Each chunk contains a single cache item. A chunksarea looks like this:
 *
 * Chunks area:
 * [[key,hashnext,valallocsize,valsize,flags,value],...]
 *
 *     'key' is the hash table key as a string.
 *
 *     'hashnext' is the offset (in the zone area) of the next chunk in
 *     the current hash table bucket. If 0, it's the last entry in the bucket.
 *     This is used to traverse the entries in a hash table bucket, which is
 *     a linked list.
 *
 *     If 'valsize' is 0, that value slot is free. This doesn't mean that all
 *     the next chunks in this zone are free as well -- only the zone's usedspace
 *     (i.e. its stack pointer) tells where the zone's free area starts.
 *
 *     'valallocsize' is how big the allocated size is. This is usually the
 *     same as 'valsize', but can be larger if the value was replaced with
 *     a smaller value later, or if 'valsize' < MIN_VALUE_ALLOC_SIZE.
 *
 *     To traverse a single zone's chunks from left to right, keep incrementing
 *     your offset by chunkSize.
 *
 *     CHUNK_META_SIZE = MAX_KEY_LENGTH + sizeof(hashnext) + sizeof(valallocsize) + sizeof(valsize) + sizeof(flags)
 *     chunkSize = CHUNK_META_SIZE + valallocsize
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
   * @param int $chunkOffset Offset relative to the zones area
   *
   * @return ShmBackedObject
   */
  function getChunkByOffset( $chunkOffset ) {
    assert( $chunkOffset > 0 );
    assert( $chunkOffset <= $this->zonesArea->size - $this->MIN_CHUNK_SIZE );
    return $this->chunkProto->createInstance( $chunkOffset );
  }

  /**
   * You must hold a bucket lock when calling this.
   */
  function getChunkByKey( $key ) {

    $bucketIndex = $this->getBucketIndex( $key );
    $chunkOffset = $this->getBucketHeadChunkOffset( $bucketIndex );

    while ( $chunkOffset > 0 ) {

      $zoneLock = $this->locks->getZoneLock( $this->getZoneIndexForChunkOffset( $chunkOffset ) );
      if ( !$zoneLock->lockForRead() )
        break;

      $chunk = $this->getChunkByOffset( $chunkOffset );

      // Chunk should not be freed if it's in the hash table
      assert( $chunk->valallocsize > 0 );
      // Chunk should never point back to itself
      assert( $chunk->hashnext !== $chunkOffset );

      if ( $chunk->key === $key ) {
        $zoneLock->releaseRead();
        return $chunk;
      }

      $chunkOffset = $chunk->hashnext;

      $zoneLock->releaseRead();
    }

    return null;
  }

  /**
   * TODO: do you need to hold a bucket lock when calling this?
   */
  function addChunk( $key, $value, $valueSize, $valueIsSerialized ) {

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

    // The new value fits into the newest zone
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

    $flags = 0;
    if ( $valueIsSerialized )
      $flags |= self::FLAG_SERIALIZED;

    $freeChunk->key = $key;
    $freeChunk->valsize = $valueSize;
    $freeChunk->flags = $flags;
    $freeChunk->hashnext = 0;

    if ( !$this->setChunkValue( $freeChunk, $value ) )
      goto cleanup;

    if ( !$this->linkChunkToHashTable( $freeChunk ) ) {
      trigger_error( 'Could not link chunk for key "'. $key .'" to hash table' );
      goto cleanup;
    }

    // FIXME: there might be a bug here, as tests are currently failing, but
    // not when this is commented out. Check if mergeChunkWithNextFreeChunks()
    // or splitChunkForFreeSpace() has a bug.
    if ( !$this->splitChunkForFreeSpace( $freeChunk ) )
      goto cleanup;

    $zoneMeta->usedspace += $freeChunk->_size + $freeChunk->valallocsize;

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

    if ( !$this->unlinkChunkFromHashTable( $chunk ) )
      return false;

    $zoneLock = $this->locks->getZoneLock( $this->getZoneIndexForChunkOffset( $chunk->_startOffset ) );
    if ( !$zoneLock->lockForWrite() )
      return false;

    $ret = false;

    if ( !$this->mergeChunkWithNextFreeChunks( $chunk ) )
      goto cleanup;

    $nextChunkOffset = $chunk->_endOffset + $chunk->valallocsize;
    $zoneMeta = $this->getZoneMetaForChunk( $chunk );

    // This is the top chunk in the zone's chunk stack. Adjust the zone's chunk
    // stack pointer.
    if ( $this->getZoneFreeChunkOffset( $zoneMeta ) === $nextChunkOffset )
      $zoneMeta->usedspace -= $chunk->_size + $chunk->valallocsize;

    assert( $zoneMeta->usedspace >= 0 );

    // Free the chunk
    $chunk->valsize = 0;

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

  function setChunkValue( ShmBackedObject $chunk, $value ) {

    if ( !$this->zonesArea->write( $chunk->_endOffset, $value ) ) {
      trigger_error( 'Could not write chunk value' );
      return false;
    }

    return true;
  }

  function replaceChunkValue( ShmBackedObject $chunk, $value, $valueSize, $valueIsSerialized ) {

    $zoneLock = $this->locks->getZoneLock( $this->getZoneIndexForChunkOffset( $chunk->_startOffset ) );
    if ( !$zoneLock->lockForWrite() )
      return false;

    $ret = false;

    // There's enough space for the new value in the existing chunk.
    // Replace the value in-place.
    if ( $valueSize <= $chunk->valallocsize ) {

      $flags = 0;
      if ( $valueIsSerialized )
        $flags |= self::FLAG_SERIALIZED;

      $chunk->valsize = $valueSize;
      $chunk->flags = $flags;

      if ( !$this->setChunkValue( $chunk, $value ) )
        goto cleanup;

      $ret = true;
    }

    cleanup:
    $zoneLock->releaseWrite();

    return $ret;
  }

  /**
   * Split the chunk into two, if the difference between valsize and
   * valallocsize is large enough.
   *
   * You must hold a bucket lock when calling this.
   */
  function splitChunkForFreeSpace( ShmBackedObject $chunk ) {

    $zoneLock = $this->locks->getZoneLock( $this->getZoneIndexForChunkOffset( $chunk->_startOffset ) );
    if ( !$zoneLock->lockForWrite() )
      return false;

    $ret = false;

    $leftOverSize = $chunk->valallocsize - $chunk->valsize;

    if ( $leftOverSize >= $this->CHUNK_META_SIZE + self::MIN_VALUE_ALLOC_SIZE ) {

      $leftOverChunkOffset = $chunk->_endOffset + $chunk->valsize;
      $leftOverChunkValAllocSize = $leftOverSize - $this->CHUNK_META_SIZE;

      $leftOverChunk = $this->getChunkByOffset( $leftOverChunkOffset );
      $leftOverChunk->key = '';
      $leftOverChunk->hashnext = 0;
      $leftOverChunk->valallocsize = $leftOverChunkValAllocSize;
      $leftOverChunk->valsize = 0;
      $leftOverChunk->flags = 0;

      assert( $leftOverChunk->valallocsize > 0 );
      assert( $leftOverChunk->valallocsize <= $this->MAX_VALUE_SIZE );

      $chunk->valallocsize -= $leftOverSize;

      assert( $chunk->valallocsize > 0 );
      assert( $chunk->valallocsize <= $this->MAX_VALUE_SIZE );

      if ( !$this->mergeChunkWithNextFreeChunks( $leftOverChunk ) )
        goto cleanup;

      assert( $leftOverChunk->valallocsize > 0 );
    }

    $ret = true;

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

  /**
   * You must hold the zone's lock when calling this.
   */
  function getZoneMetaByIndex( $zoneIndex ) {
    assert( $zoneIndex >= 0 );
    assert( $zoneIndex < $this->ZONE_COUNT );
    return $this->zoneMetaProto->createInstance( $zoneIndex * self::ZONE_SIZE );
  }

  /**
   * @param int $chunkOffset Offset relative to the zones area start
   */
  function getZoneIndexForChunkOffset( $chunkOffset ) {
    assert( $chunkOffset <= $this->zonesArea->size - $this->MIN_CHUNK_SIZE );
    return (int) floor( $chunkOffset / self::ZONE_SIZE );
  }

  function getZoneMetaForChunk( ShmBackedObject $chunk ) {
    return $this->getZoneMetaByIndex( $this->getZoneIndexForChunkOffset( $chunk->_startOffset ) );
  }

  function getZoneFreeSpace( ShmBackedObject $zoneMeta ) {

    $freeSpace = $this->MAX_CHUNK_SIZE - $zoneMeta->usedspace;

    assert( $freeSpace >= 0 );
    assert( $freeSpace <= $this->MAX_CHUNK_SIZE );

    return $freeSpace;
  }

  /**
   * Returns the offset of the zone's chunk stack pointer, i.e. returns the
   * offset to the start of free chunk space.
   *
   * You must hold a zone lock when calling this.
   */
  function getZoneFreeChunkOffset( ShmBackedObject $zoneMeta ) {
    $freeChunkOffset = $zoneMeta->_endOffset + $zoneMeta->usedspace;
    assert( $freeChunkOffset <= $this->zonesArea->size - $this->MIN_CHUNK_SIZE );
    return $freeChunkOffset;
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

    $zoneMetaSize = $this->LONG_SIZE;
    $this->MAX_CHUNK_SIZE = self::ZONE_SIZE - $zoneMetaSize;
    $this->MIN_CHUNK_SIZE = $this->CHUNK_META_SIZE + self::MIN_VALUE_ALLOC_SIZE;
    $this->MAX_VALUE_SIZE = $this->MAX_CHUNK_SIZE - $this->CHUNK_META_SIZE;

    // Note: we cast to (int) so that this is not a float value. Otherwise
    // PHP's === doesn't work when comparing with ints, because 42 !== 42.0.
    $this->ZONE_COUNT = (int) floor( $this->zonesArea->size / self::ZONE_SIZE );

    $this->MAX_CHUNKS_PER_ZONE = (int) floor( ( self::ZONE_SIZE - $zoneMetaSize ) / $this->MIN_CHUNK_SIZE );
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

    $chunkOffset = $chunk->_startOffset;
    $currentOffset = $bucketHeadChunkOffset;

    while ( $currentOffset ) {
      $testChunk = $this->getChunkByOffset( $currentOffset );
      $nextOffset = $testChunk->hashnext;

      if ( $nextOffset === $chunkOffset ) {
        return $currentOffset;
      }

      $currentOffset = $nextOffset;
    }

    return 0;
  }

  /**
   * @return int A chunk offset relative to the zones area
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

    $bucketIndex = $this->getBucketIndex( $chunk->key );
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
   * You must hold a bucket lock when calling this.
   */
  private function unlinkChunkFromHashTable( ShmBackedObject $chunk ) {

    $bucketIndex = $this->getBucketIndex( $chunk->key );
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
      trigger_error( 'Found no previous chunk in the hash table bucket for key "'. $rawurlencode( $chunk->key ) .'"' );
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

    $zoneIndex = $this->getZoneIndexForChunkOffset( $chunk->_startOffset );

    $newAllocSize = $origAllocSize = $chunk->valallocsize;
    $nextChunkOffset = $chunk->_endOffset + $origAllocSize;

    while ( $nextChunkOffset ) {

      // Hit the next zone
      if ( $this->getZoneIndexForChunkOffset( $nextChunkOffset ) !== $zoneIndex )
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
   * You must hold a zone lock when calling this.
   * TODO: or does this method handle all zone locking by itself?
   */
  private function removeAllChunksInZone( ShmBackedObject $zoneMeta ) {

    $firstChunk = $chunk = $this->getChunkByOffset( $zoneMeta->_endOffset );
    $zoneIndex = $this->getZoneIndexForChunkOffset( $firstChunk->_startOffset );

    $zoneLock = $this->locks->getZoneLock( $zoneIndex );
    if ( !$zoneLock->lockForWrite() )
      return false;

    $ret = false;
    $bucketLock = null;
    $lastPossibleChunkOffset = self::ZONE_SIZE - $this->MIN_CHUNK_SIZE;

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

          // TODO: according to LOCKING.txt the ringBufferPtr lock also needs
          // to be unlocked here

          if ( microtime( true ) - $startTime > self::TRYLOCK_TIMEOUT ) {
            trigger_error( 'Try-lock timeout' );
            goto cleanup;
          }

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

    $ret = true;

    cleanup:

    if ( $zoneLock )
      $zoneLock->releaseWrite();

    if ( $bucketLock )
      $bucketLock->releaseWrite();

    return $ret;
  }
}

