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
 *     after that point is free space.
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
 *     same as 'valsize', but can be larger if the value was changed to
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
  const MIN_VALUE_ALLOC_SIZE = 32;
  const ZONE_SIZE = 1048576; // 1 MiB
  // If a key string is longer than this, the key is truncated silently
  const MAX_KEY_LENGTH = 200;
  // The more hash table buckets there are, the more memory is used but the
  // faster it is to find a hash table entry
  const HASH_BUCKET_COUNT = 512;

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

    $this->zoneMetaProto = ShmBackedObject::createPrototype( $this->zonesArea, [
      // First free chunk's offset in this zone. Relative to the zone's
      // chunksarea start.
      'usedspace' => [
        'offset' => 0,
        'size' => $this->LONG_SIZE,
        'packformat' => 'l'
      ]
    ] );

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

  function getChunkByOffset( $chunkOffset ) {
    return $this->chunkProto->createInstance( $chunkOffset );
  }

  /**
   * Returns an offset in the zones area, to the chunk whose key matches
   * the given key.
   */
  function getChunkByKey( $key ) {

    $bucketIndex = $this->getBucketIndex( $key );
    $chunkOffset = $this->getBucketHeadChunkOffset( $bucketIndex );

    while ( $chunkOffset >= 0 ) {

      $chunk = $this->getChunkByOffset( $chunkOffset );

      if ( $chunk->key === $key )
        return $chunkOffset;

      $chunkOffset = $chunk->hashnext;
    }

    return null;
  }

  function removeChunk( ShmBackedObject $chunk ) {

    if ( !$this->unlinkChunkFromHashTable( $chunk ) )
      return false;

    $chunk->valsize = 0;

    // TODO: merge with neighboring chunks, if they're dirty or free

    return true;
  }

  function getZoneMetaByIndex( $zoneIndex ) {
    return $this->zoneMetaProto->createInstance( $zoneIndex * self::ZONE_SIZE );
  }

  function getZoneFreeSpace( ShmBackedObject $zoneMeta ) {
    return $this->MAX_CHUNK_SIZE - $zoneMeta->usedspace;
  }

  function removeAllChunksInZone( ShmBackedObject $zone ) {

    $chunk = $this->getChunkByOffset( $zone->_startOffset + $this->ZONE_META_SIZE );

    while ( $chunk ) {
      $this->removeChunk( $chunk );
      $chunk = $this->getChunkByOffset( $zone->_startOffset + $this->ZONE_META_SIZE );
    }
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

    $this->ZONE_META_SIZE = $this->LONG_SIZE;

    $allocatedSize = $this->LONG_SIZE;
    $hashNextSize = $this->LONG_SIZE;
    $valueSize = $this->LONG_SIZE;
    $flagsSize = $this->CHAR_SIZE;
    $this->CHUNK_META_SIZE = self::MAX_KEY_LENGTH + $hashNextSize + $allocatedSize + $valueSize + $flagsSize;

    $this->MAX_CHUNK_SIZE = self::ZONE_SIZE - $this->ZONE_META_SIZE;

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

      $chunk = $this->getChunkByOffset( $i * self::ZONE_SIZE + self::ZONE_META_SIZE );
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

    // TODO: oldestZoneIndexLock
    $index = $this->getOldestZoneIndex() - 1;

    if ( $index < 0 )
      $index = $this->ZONE_COUNT;

    return $index;
  }

  private function getOldestZoneIndex() {

    $data = shmop_read( $this->shm, $this->metaArea->startOffset + $this->LONG_SIZE, $this->LONG_SIZE );
    $index = unpack( 'l', $data )[ 1 ];

    if ( !is_int( $index ) ) {
      trigger_error( 'Could not find the oldest zone index' );
      return -1;
    }

    return $index;
  }

  private function setOldestZoneIndex( $index ) {

    $data = pack( 'l', $index );
    $ret = shmop_write( $this->shm, $data, $this->metaArea->startOffset + $this->LONG_SIZE );

    if ( $ret === false ) {
      trigger_error( 'Could not write the oldest zone index' );
      return false;
    }

    return true;
  }

  private function getGetHits() {

    $data = shmop_read(
      $this->shm,
      $this->statsArea->startOffset,
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
      $this->statsArea->startOffset + $this->LONG_SIZE,
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

    if ( shmop_write( $this->shm, pack( 'l', $count ), $this->statsArea->startOffset ) === false ) {
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

    if ( shmop_write( $this->shm, pack( 'l', $count ), $this->statsArea->startOffset + $this->LONG_SIZE ) === false ) {
      trigger_error( 'Could not write the get() miss count' );
      return false;
    }

    return true;
  }

  /**
   * @param int $chunkOffset Relative to the zone area start
   */
  private function writeChunkValue( ShmBackedObject $chunk, $value ) {

    if ( shmop_write( $chunk->_shm, $value, $chunk->_startOffset + $this->CHUNK_META_SIZE ) === false ) {
      trigger_error( 'Could not write chunk value' );
      return false;
    }

    return true;
  }

  // TODO
  private function getNextItemOffset( $itemOffset, $itemValAllocSize ) {

    $nextItemOffset = $itemOffset + $this->CHUNK_META_SIZE + $itemValAllocSize;
    // If it's the last item in the zones area, the next item's offset is 0,
    // which means "none"
    if ( $nextItemOffset > $this->LAST_ITEM_MAX_OFFSET )
      $nextItemOffset = 0;

    return $nextItemOffset;
  }

  private function findHashTablePrevChunkOffset( ShmBackedObject $chunk, $bucketIndex ) {

    $chunkOffset = $chunk->_startOffset;
    $currentOffset = $this->getBucketHeadChunkOffset( $bucketIndex );

    while ( $currentOffset >= 0 ) {
      $testChunk = $this->getChunkByOffset( $currentOffset );
      $nextOffset = $testChunk->hashnext;
      if ( $nextOffset == $chunkOffset )
        return $currentOffset;
      $currentOffset = $nextOffset;
    }

    return -1;
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
  private function getBucketIndex( $key ) {

    $hash = hash( 'crc32', $key, true );

    // Read as a 32-bit unsigned long
    // TODO: unpack() is slow. Is there an alternative?
    $index = unpack( 'L', $hash )[ 1 ];
    // Modulo by the amount of hash table buckets to get an array index
    $index %= self::HASH_BUCKET_COUNT;

    return $index;
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

    if ( !$headChunkOffset )
      return -1;

    if ( $data === false ) {
      trigger_error( 'Could not read head chunk offset for bucket "'. $index .'"' );
      return -1;
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

  private function unlinkChunkFromHashTable( ShmBackedObject $chunk ) {

    $bucketIndex = $this->getBucketIndex( $chunk->key );
    $bucketHeadChunkOffset = $this->getBucketHeadChunkOffset( $bucketIndex );

    if ( $bucketHeadChunkOffset < 0 ) {
      trigger_error( 'Key "'. rawurlencode( $chunk->key ) .'"\'s bucket is empty' );
      return false;
    }


    if ( $chunk->_startOffset === $bucketHeadChunkOffset ) {
      return $this->setBucketHeadChunkOffset( $bucketIndex, $chunk->hashnext );
    }

    $prevChunkOffset = $this->findHashTablePrevChunkOffset( $chunk, $bucketIndex );
    if ( $prevChunkOffset < 0 )
      return false;

    $chunk = $this->getChunkByOffset( $chunkOffset );
    $prevChunk = $this->getChunkByOffset( $prevChunkOffset );

    //              ________________
    //             |                v
    // Link prevChunk currentChunk nextChunk
    //
    $prevChunk->hashnext = $chunk->hashnext;

    return true;
  }
}

