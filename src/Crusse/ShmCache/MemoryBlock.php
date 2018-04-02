<?php

namespace Crusse\ShmCache;

/**
 * Represents a shared memory block for ShmCache. Acts as a singleton: if you
 * instantiate this multiple times, you'll always get back the same shared
 * memory block. This makes sure ShmCache always allocates the desired amount
 * of memory at maximum, and no more.
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
 * Statistics area:
 * [gethits][getmisses]
 *
 * Hash table bucket area:
 * [itemmetaoffset,itemmetaoffset,...]
 *
 *     Our hash table uses separate chaining. The itemmetaoffset points to
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
 *     'key' is the original key string.
 *
 *     'hashnext' is the offset in the zone area of the next entry (chunk) in
 *     the current hash table bucket.
 *
 *     If 'valsize' is 0, that value slot is free.
 *
 *     'valallocsize' is how big the allocated size is. This is usually the
 *     same as 'valsize', but can be larger if the value was replaced with
 *     a smaller value later, or if the 'valsize' < MIN_VALUE_ALLOC_SIZE.
 *
 *     CHUNK_META_SIZE = sizeof(key) + sizeof(hashnext) + sizeof(valallocsize) + sizeof(valsize) + sizeof(flags)
 */
class MemoryBlock {

  // Total amount of space to allocate for the shared memory block. This will
  // contain both the keys area and the zones area, so the amount allocatable
  // for zones will be slightly smaller.
  const DEFAULT_CACHE_SIZE = 134217728;
  const SAFE_AREA_SIZE = 1024;
  // Don't let value allocations become smaller than this, to reduce fragmentation
  const MIN_VALUE_ALLOC_SIZE = 128;
  // The largest cache item can be this big (minus a few bytes for zone metadata)
  const ZONE_SIZE = 1048576; // 1 MiB
  // If a key string is longer than this, the key is truncated silently
  const MAX_KEY_LENGTH = 200;
  // The more hash table buckets there are, the more memory is used but the
  // faster it is to find a hash table entry
  const HASH_BUCKET_COUNT = 512;
  const TRYLOCK_TIMEOUT = 3;

  const FLAG_SERIALIZED = 0b00000001;

  private $shm;
  private $shmKey;

  public $metaArea;
  public $statsArea;
  public $hashBucketArea;
  public $zonesArea;

  private $statsProto;
  private $zoneMetaProto;
  private $chunkProto;

  function __construct( $desiredSize ) {

    // PHP doesn't allow complex expressions in class consts so we'll create
    // these "consts" here
    $this->LONG_SIZE = strlen( pack( 'l', 1 ) ); // i.e. sizeof(long) in C
    $this->CHAR_SIZE = strlen( pack( 'c', 1 ) ); // i.e. sizeof(char) in C

    $this->initMemBlock( $desiredSize, $retIsNewBlock );

    $this->metaArea = new ShmCache\MemoryArea(
      $this->shm,
      0,
      1024 // Oversized for future needs
    );

    $this->statsArea = new ShmCache\MemoryArea(
      $this->shm,
      $this->metaArea->endOffset + self::SAFE_AREA_SIZE,
      1024 // Oversized for future needs
    );

    $this->hashBucketArea = new ShmCache\MemoryArea(
      $this->shm,
      $this->statsArea->endOffset + self::SAFE_AREA_SIZE,
      $hashBucketCount * $this->LONG_SIZE
    );

    $this->zonesArea = new ShmCache\MemoryArea(
      $this->shm,
      $this->hashBucketArea->endOffset + self::SAFE_AREA_SIZE,
      $this->BLOCK_SIZE - ( $this->hashBucketArea->endOffset + self::SAFE_AREA_SIZE )
    );

    $this->populateSizes();

    if ( $retIsNewBlock )
      $this->clearMemBlockToInitialData();

    $this->initializeShmObjectPrototypes();
  }

  private function initializeShmObjectPrototypes() {

    // You must hold the stats lock when reading from or writing to any
    // property of this object
    $this->statsProto = ShmBackedObject::createPrototype( $this->statsArea, [
      'gethits' => [
        'offset' => 0,
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ],
      'getmisses' => [
        'offset' => $this->LONG_SIZE,
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ]
    ] );

    // You must hold the zone's lock when reading from or writing to any
    // property of this object
    $this->zoneMetaProto = ShmBackedObject::createPrototype( $this->zonesArea, [
      'usedspace' => [
        'offset' => 0,
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
        'offset' => 0,
        'size' => self::MAX_KEY_LENGTH,
        'packformat' => 'A'. self::MAX_KEY_LENGTH
      ],
      'hashnext' => [
        'offset' => self::MAX_KEY_LENGTH,
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ],
      'valallocsize' => [
        'offset' => self::MAX_KEY_LENGTH + $this->LONG_SIZE,
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ],
      'valsize' => [
        'offset' => self::MAX_KEY_LENGTH + 2 * $this->LONG_SIZE,
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ],
      'flags' => [
        'offset' => self::MAX_KEY_LENGTH + 3 * $this->LONG_SIZE,
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
    return $this->statsProto->createInstance();
  }

  function getChunkByOffset( $chunkOffset ) {
    return $this->chunkProto->createInstance( $chunkOffset );
  }

  /**
   * You must hold a bucket lock when calling this.
   */
  function getChunkByKey( $key ) {

    $bucketIndex = $this->getBucketIndex( $key );
    $chunkOffset = $this->getBucketHeadChunkOffset( $bucketIndex );
    $chunk = null;

    while ( $chunkOffset >= 0 ) {

      $chunk = $this->getChunkByOffset( $chunkOffset );

      $zoneLock = $this->getZoneLock( $this->getZoneIndexForChunk( $chunk ) );
      if ( !$zoneLock->lockForRead() )
        break;

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
   * You must hold a bucket lock when calling this.
   */
  function removeChunk( ShmBackedObject $chunk ) {

    if ( !$this->unlinkChunkFromHashTable( $chunk ) )
      return false;

    $zoneLock = $this->getZoneLock( $this->getZoneIndexForChunk( $chunk ) );
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

    // Free the chunk
    $chunk->valsize = 0;

    $ret = true;

    cleanup:
    $zoneLock->releaseWrite();

    return $ret;
  }

  function getChunkValue( ShmBackedObject $chunk ) {

    $data = shmop_read( $chunk->_shm, $chunk->_endOffset, $chunk->valsize );

    if ( $data === false ) {
      trigger_error( 'Could not read chunk value' );
      return false;
    }

    return $data;
  }

  function setChunkValue( ShmBackedObject $chunk, $value ) {

    if ( shmop_write( $chunk->_shm, $value, $chunk->_endOffset ) === false ) {
      trigger_error( 'Could not write chunk value' );
      return false;
    }

    return true;
  }

  function replaceChunkValue( ShmBackedObject $chunk, $value, $valueSize, $valueIsSerialized ) {

    $zoneLock = $this->getZoneLock( $this->getZoneIndexForChunk( $chunk ) );
    if ( !$zoneLock->lockForWrite() )
      return false;

    $ret = false;

    // There's enough space for the new value in the existing chunk.
    // Replace the value in-place.
    if ( $valueSize <= $existingChunk->valallocsize ) {

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

    $zoneLock = $this->getZoneLock( $this->getZoneIndexForChunk( $chunk ) );
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

      $chunk->valallocsize -= $leftOverSize;

      if ( !$this->mergeChunkWithNextFreeChunks( $leftOverChunk ) )
        goto cleanup;
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
    return $this->zoneMetaProto->createInstance( $zoneIndex * self::ZONE_SIZE );
  }

  function getZoneIndexForChunk( ShmBackedObject $chunk ) {

    $chunkOffsetRelativeToZonesStart = $chunk->_startOffset - $this->zonesArea->startOffset;

    return $chunkOffsetRelativeToZonesStart % self::ZONE_SIZE;
  }

  function getZoneMetaForChunk( ShmBackedObject $chunk ) {
    return $this->getZoneMetaByIndex( $this->getZoneIndexForChunk( $chunk ) );
  }

  function getZoneFreeSpace( ShmBackedObject $zoneMeta ) {
    return $this->MAX_CHUNK_SIZE - $zoneMeta->usedspace;
  }

  /**
   * You must hold a zone lock when calling this.
   */
  function getZoneFreeChunkOffset( ShmBackedObject $zoneMeta ) {
    return $zoneMeta->_endOffset + $zoneMeta->usedspace;
  }

  private function initMemBlock( $desiredSize, &$retIsNewBlock ) {

    $opened = $this->openMemBlock();

    // Try to re-create an existing block with a larger size, if the block is
    // smaller than the desired size. This fails (at least on Linux) if the
    // Unix user trying to delete the block is different than the user that
    // created the block.
    if ( $opened && $desiredSize ) {

      $currentSize = shmop_size( $this->shm );

      if ( $currentSize < $desiredSize ) {

        // Destroy and recreate the memory block with a larger size
        if ( shmop_delete( $this->shm ) ) {
          shmop_close( $this->shm );
          $this->shm = null;
          $opened = false;
        }
        else {
          trigger_error( 'Could not delete the memory block. Falling back to using the existing, smaller-than-desired block.' );
        }
      }
    }

    // No existing memory block. Create a new one.
    if ( !$opened )
      $this->createMemBlock( $desiredSize );

    // A new memory block. Write initial values.
    $retIsNewBlock = !$opened;

    return (bool) $this->shm;
  }

  // TODO: determine these from the ShmBackedObject prototype specs?
  private function populateSizes() {

    $this->BLOCK_SIZE = shmop_size( $this->shm );

    $allocatedSize = $this->LONG_SIZE;
    $hashNextSize = $this->LONG_SIZE;
    $valueSize = $this->LONG_SIZE;
    $flagsSize = $this->CHAR_SIZE;
    $this->CHUNK_META_SIZE = self::MAX_KEY_LENGTH + $hashNextSize + $allocatedSize + $valueSize + $flagsSize;

    $zoneMetaSize = $this->LONG_SIZE;
    $this->MAX_CHUNK_SIZE = self::ZONE_SIZE - $zoneMetaSize;

    $this->ZONE_COUNT = min( $this->zonesArea->size / self::ZONE_SIZE );
  }

  /**
   * @throws \Exception If both opening and creating a memory block failed
   * @return bool True if an existing block was opened, false if a new block was created
   */
  private function openMemBlock() {

    $blockKey = $this->getShmopKey();
    $mode = 0777;

    // The 'size' parameter is ignored by PHP when 'w' is used:
    // https://github.com/php/php-src/blob/9e709e2fa02b85d0d10c864d6c996e3368e977ce/ext/shmop/shmop.c#L183
    $this->shm = @shmop_open( $blockKey, "w", $mode, 0 );

    return (bool) $this->shm;
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
   * Write NULs over the whole block and initialize it with a single, free
   * cache item.
   */
  private function clearMemBlockToInitialData() {

    // Clear all bytes in the shared memory block
    $memoryWriteBatch = 1024 * 1024 * 4;
    for ( $i = 0; $i < $this->BLOCK_SIZE; $i += $memoryWriteBatch ) {

      // Last chunk might have to be smaller
      if ( $i + $memoryWriteBatch > $this->BLOCK_SIZE )
        $memoryWriteBatch = $this->BLOCK_SIZE - $i;

      $data = pack( 'x'. $memoryWriteBatch );
      $res = shmop_write( $this->shm, $data, $i );

      if ( $res === false )
        throw new \Exception( 'Could not write NUL bytes to the memory block' );
    }

    $this->setGetHits( 0 );
    $this->setGetMisses( 0 );

    // Initialize zones
    for ( $i = 0; $i < $this->ZONE_COUNT; $i++ ) {

      $zoneMeta = $this->getZoneMetaByIndex( $i );
      $zoneMeta->usedspace = 0;

      $chunk = $this->getChunkByOffset( $i * self::ZONE_SIZE + $zoneMeta->_size );
      $chunk->key = '';
      $chunk->hashnext = 0;
      $chunk->valallocsize = $this->MAX_CHUNK_SIZE - $this->CHUNK_META_SIZE;
      $chunk->valsize = 0;
      $chunk->flags = 0;
    }

    $this->setOldestZoneIndex( $this->ZONE_COUNT - 1 );
  }

  private function destroyMemBlock() {

    $deleted = shmop_delete( $this->shm );

    if ( !$deleted ) {
      throw new \Exception( 'Could not destroy the memory block. Try running the \'ipcrm\' command as a super-user to remove the memory block listed in the output of \'ipcs\'.' );
    }

    shmop_close( $this->shm );
    $this->shm = null;
  }

  private function getNewestZoneIndex() {

    $index = $this->getOldestZoneIndex() - 1;

    if ( $index < 0 )
      $index = $this->ZONE_COUNT;

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

    return $index;
  }

  /**
   * You must hold the oldest zone index lock when calling this.
   */
  private function setOldestZoneIndex( $index ) {

    if ( $index > $this->ZONE_COUNT - 1 )
      $index = 0;

    $data = pack( 'l', $index );
    $ret = shmop_write( $this->shm, $data, $this->metaArea->startOffset + $this->LONG_SIZE );

    if ( $ret === false ) {
      trigger_error( 'Could not write the oldest zone index' );
      return false;
    }

    return true;
  }

  private function addChunk( $key, $value, $valueSize, $valueIsSerialized ) {

    if ( $valueSize > $this->MAX_CHUNK_SIZE - $this->CHUNK_META_SIZE ) {
      trigger_error( 'Item "'. rawurlencode( $key ) .'" is too large ('. round( $valueSize / 1000, 2 ) .' KB) to cache' );
      return false;
    }

    $ret = false;
    $zoneLock = null;

    if ( !$this->locks->oldestZoneIndex->lockForWrite() )
      return false;

    $newestZoneIndex = $this->getNewestZoneIndex();
    if ( $newestZoneIndex < 0 )
      goto cleanup;

    $zoneMeta = $this->getZoneMetaByIndex( $newestZoneIndex );

    $zoneLock = $this->locks->getZoneLock( $newestZoneIndex );
    if ( !$zoneLock->lockForWrite() ) {
      $zoneLock = null;
      goto cleanup;
    }

    $zoneFreeSpace = $this->getZoneFreeSpace( $zoneMeta );
    $requiredSize = $this->CHUNK_META_SIZE + $valueSize;

    // The new value fits into the newest zone
    if ( $zoneFreeSpace >= $requiredSize ) {

      $freeChunk = $this->getChunkByOffset( $this->getZoneFreeChunkOffset( $zoneMeta ) );

      // Make sure the chunk pointed to by the zone's stack pointer is large
      // enough (i.e. it's merged with chunks that come after it in the zone)
      if ( $freeChunk->valallocsize < $requiredSize ) {
        if ( !$this->mergeChunkWithNextFreeChunks( $freeChunk ) )
          goto cleanup;
      }
    }
    // The new value doesn't fit into the newest zone. Make space for the new
    // value by evicting all chunks in the oldest zone.
    else {

      // Unlock newest zone, so that we can lock the oldest zone (only one zone
      // lock can be held at a time)
      $zoneLock->releaseWrite();
      $zoneLock = null;

      $oldestZoneIndex = $this->getOldestZoneIndex();
      if ( $oldestZoneIndex < 0 )
        goto cleanup;

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

    if ( !$this->setChunkValue( $freeChunk, $value ) )
      goto cleanup;

    if ( !$this->linkChunkToHashTable( $freeChunk ) )
      goto cleanup;

    if ( !$this->splitChunkForFreeSpace( $freeChunk ) )
      goto cleanup;

    $zoneMeta->usedspace += $freeChunk->_size + $freeChunk->valallocsize;

    $ret = true;

    cleanup:

    if ( $zoneLock )
      $zoneLock->releaseWrite();

    $this->locks->oldestZoneIndex->releaseWrite();

    return $ret;
  }

  /**
   * You must hold a bucket lock when calling this.
   */
  private function findHashTablePrevChunkOffset( ShmBackedObject $chunk, $bucketIndex ) {

    $chunkOffset = $chunk->_startOffset;
    $currentOffset = $this->getBucketHeadChunkOffset( $bucketIndex );

    while ( $currentOffset ) {
      $testChunk = $this->getChunkByOffset( $currentOffset );
      $nextOffset = $testChunk->hashnext;
      if ( $nextOffset === $chunkOffset )
        return $currentOffset;
      $currentOffset = $nextOffset;
    }

    return 0;
  }

  /**
   * @return int A chunk offset relative to the zones area
   */
  private function getBucketHeadChunkOffset( $index ) {

    $data = shmop_read(
      $this->shm,
      $this->hashBucketArea->startOffset + $bucketIndex * $this->LONG_SIZE,
      $this->LONG_SIZE
    );

    if ( $data === false ) {
      trigger_error( 'Could not read head chunk offset for bucket "'. $index .'"' );
      return 0;
    }

    return unpack( 'l', $data )[ 1 ];
  }

  private function setBucketHeadChunkOffset( $bucketIndex, $itemMetaOffset ) {

    $hashTableOffset = $this->KEYS_START + $hashTableIndex * $this->LONG_SIZE;
    $data = pack( 'l', $itemMetaOffset );
    $ret = shmop_write( $this->shm, $data, $hashTableOffset );

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

    while ( $existingChunkOffset ) {
      $existingChunk = $this->getChunkByOffset( $existingChunkOffset );
      if ( !$existingChunk->hashnext ) {
        $existingChunk->hashnext = $chunk->_startOffset;
        return true;
      }
      $existingChunkOffset = $existingChunk->hashnext;
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
      trigger_error( 'Key "'. rawurlencode( $chunk->key ) .'"\'s bucket is empty' );
      return false;
    }

    if ( $chunk->_startOffset === $bucketHeadChunkOffset ) {
      // Note that $chunk->hashnext can be 0 here
      return $this->setBucketHeadChunkOffset( $bucketIndex, $chunk->hashnext );
    }

    $prevChunkOffset = $this->findHashTablePrevChunkOffset( $chunk, $bucketIndex );
    if ( !$prevChunkOffset )
      return false;

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

    $zoneIndex = $this->getZoneIndexForChunk( $chunk );

    $newAllocSize = $origAllocSize = $chunk->valallocsize;
    $nextChunkOffset = $chunk->_endOffset + $origAllocSize;

    while ( $nextChunkOffset ) {

      $nextChunk = $this->getChunkByOffset( $nextChunkOffset );

      if ( $this->getZoneIndexForChunk( $nextChunk ) !== $zoneIndex )
        break;

      // Found a non-free chunk
      if ( $nextChunk->valsize )
        break;

      $newAllocSize += $nextChunk->_size + $nextChunk->valallocsize;
      $nextChunkOffset = $nextChunkOffset + $nextChunk->_size + $nextChunk->valallocsize;
    }

    if ( $newAllocSize !== $origAllocSize )
      $chunk->valallocsize = $newAllocSize;

    return true;
  }

  /**
   * You must hold a zone lock when calling this.
   */
  private function removeAllChunksInZone( ShmBackedObject $zoneMeta ) {

    $firstChunk = $chunk = $this->getChunkByOffset( $zoneMeta->_endOffset );
    $zoneIndex = $this->getZoneIndexForChunk( $firstChunk );

    $zoneLock = $this->getZoneLock( $zoneIndex );
    if ( !$zoneLock->lockForWrite() )
      return false;

    $ret = false;
    $bucketLock = null;

    while ( $chunk ) {

      // Already free
      if ( !$chunk->valsize )
        continue;

      $bucketLock = $this->getBucketLock( $this->getBucketIndex( $chunk->key ) );
      $startTime = microtime( true );

      while ( !$bucketLock->lockForWrite( true ) ) {

        $zoneLock->releaseWrite();

        if ( microtime( true ) - $startTime > self::TRYLOCK_TIMEOUT )
          return false;

        $zoneLock = $this->getZoneLock( $zoneIndex );
        if ( !$zoneLock->lockForWrite() )
          return false;
      }

      if ( !$this->unlinkChunkFromHashTable( $chunk ) )
        goto cleanup;

      $bucketLock->releaseWrite();
      $bucketLock = null;

      $chunk->valsize = 0;
      $chunk = $this->getChunkByOffset( $chunk->_endOffset + $chunk->valallocsize );
    }

    $firstChunk->valallocsize = $this->MAX_CHUNK_SIZE - $this->CHUNK_META_SIZE;

    // Reset the zone's chunk stack pointer
    $zoneMeta->usedspace = 0;
    $ret = true;

    cleanup:
    $zoneLock->releaseWrite();

    if ( $bucketLock )
      $bucketLock->releaseWrite();

    return $ret;
  }
}

