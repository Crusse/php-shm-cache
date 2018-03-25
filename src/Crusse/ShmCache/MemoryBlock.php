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
 *     The keys area is a hash table of KEYS_SLOTS items. Our hash table
 *     implementation uses linear probing, so this table is only ever filled up
 *     to MAX_LOAD_FACTOR.
 *
 * Zones area:
 * [[stackpointer,chunksarea],[stackpointer,chunksarea],...]
 *
 *     The zones area is a ring buffer. The oldestzoneindex points to
 *     the oldest zone.
 *
 *     A zone's stackpointer points to the start of free space (i.e. the first
 *     free chunk) in that zone. Everything on the left-hand side of the
 *     pointer is memory in use; everything on the right-hand side is free
 *     memory in that zone.
 *
 *     Each zone is roughly in the order in which the zones were created, so
 *     that we can easily find the oldest zones for eviction, to make space for
 *     new cache items.
 *
 *     Each chunk contains a single cache item entry. A chunksarea looks like
 *     this:
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
  }

  function __destruct() {

    if ( $this->shm )
      shmop_close( $this->shm );
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
      $this->setZoneStackPointer( 1, 0 );
      $this->writeChunkMeta(
        $i * self::ZONE_SIZE + self::ZONE_META_SIZE,
        '',
        $this->MAX_CHUNK_SIZE - $this->CHUNK_META_SIZE,
        0,
        0
      );
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

  private function getOldestZoneIndex() {

    $data = shmop_read( $this->shm, $this->metaArea->startOffset + $this->LONG_SIZE, $this->LONG_SIZE );
    $oldestItemOffset = unpack( 'l', $data )[ 1 ];

    if ( !is_int( $oldestItemOffset ) ) {
      trigger_error( 'Could not find the oldest zone index' );
      return null;
    }

    return $oldestItemOffset;
  }

  private function setOldestZoneIndex( $zonesAreaOffset ) {

    $data = pack( 'l', $zonesAreaOffset );
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

  private function getZoneStackPointer( $zoneIndex ) {

    $writeOffset = $this->zonesArea->startOffset + $zoneIndex * $this->ZONE_SIZE;
    $ret = shmop_read( $this->shm, $writeOffset, $this->LONG_SIZE );

    if ( $ret === false ) {
      trigger_error( 'Could not read zone '. $zoneIndex .' stack pointer' );
      return false;
    }

    return true;
  }

  /**
   * @param int $stackPointerOffset Relative to the zone's chunks area
   */
  private function setZoneStackPointer( $zoneIndex, $chunksAreaOffset ) {

    $writeOffset = $this->zonesArea->startOffset + $zoneIndex * $this->ZONE_SIZE;
    $data = pack( 'l', $chunksAreaOffset );
    $ret = shmop_write( $this->shm, $data, $writeOffset );

    if ( $ret === false ) {
      trigger_error( 'Could not write zone '. $zoneIndex .' stack pointer' );
      return false;
    }

    return true;
  }

  /**
   * If you give an argument to any of the nullable parameters, ALL parameters
   * after that MUST also be set.
   *
   * @param int $hashNextOffset Next hash table entry, relative to the whole zones area start
   */ 
  private function writeChunkMeta( $zoneAreaOffset, $key = null, $hashNextOffset = null, $valueAllocatedSize = null, $valueSize = null, $flags = null ) {

    $data = '';
    $writeOffset = $this->zonesArea->startOffset + $zoneAreaOffset;

    if ( $key !== null )
      $data .= pack( 'A'. self::MAX_KEY_LENGTH, $key );
    else
      $writeOffset += self::MAX_KEY_LENGTH;

    if ( $hashNextOffset !== null )
      $data .= pack( 'l', $hashNextOffset );
    else if ( $key === null )
      $writeOffset += $this->LONG_SIZE;

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

  /**
   * @param int $chunkOffset Relative to the zone area start
   */
  private function writeChunkValue( $chunkOffset, $value ) {

    if ( shmop_write( $this->shm, $value, $zoneAreaOffset + $this->CHUNK_META_SIZE ) === false ) {
      trigger_error( 'Could not write item value' );
      return false;
    }

    return true;
  }

  private function getChunkMetaByOffset( $offset, $withKey = true ) {

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

    $data = shmop_read( $this->shm, $offset, $this->CHUNK_META_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not read item metadata at offset '. $offset );
      return null;
    }

    return unpack( $unpackFormat, $data );
  }

  private function getNextItemOffset( $itemOffset, $itemValAllocSize ) {

    $nextItemOffset = $itemOffset + $this->CHUNK_META_SIZE + $itemValAllocSize;
    // If it's the last item in the zones area, the next item's offset is 0,
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

    $hashTableIndex = $this->getHashTableEntryOffset( $key );

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

  /**
   * Returns an offset in the zones area, to the chunk whose key matches
   * the given key.
   */
  private function getChunkOffset( $key ) {

    $bucketIndex = $this->getHashTableBucketIndex( $key );
    $chunkOffset = $this->getHeadChunkOffsetForBucketIndex( $bucketIndex );

    if ( $chunkOffset < 0 )
      return -1;

    while ( $chunkOffset ) {

      // Read a chunk's "key" and "hashnext" properties
      $data = shmop_read(
        $this->shm,
        $this->zonesArea->startOffset + $chunkOffset,
        self::MAX_KEY_LENGTH + $this->LONG_SIZE
      );

      if ( !$data )
        break;

      $props = unpack( 'A'. self::MAX_KEY_LENGTH .'key/lhashnext', $data );

      if ( $props[ 'key' ] === $key )
        return $chunkOffset;

      $chunkOffset = $props[ 'hashnext' ];
    }

    return -1;
  }

  /**
   * @return int A chunk offset relative to the zones area
   */
  private function getHeadChunkOffsetForBucketIndex( $index ) {

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
  private function getHashTableBucketIndex( $key ) {

    $hash = hash( 'crc32', $key, true );

    // Read as a 32-bit unsigned long
    // TODO: unpack() is slow. Is there an alternative?
    $index = unpack( 'L', $hash )[ 1 ];
    // Modulo by the amount of hash table buckets to get an array index
    $index %= self::HASH_BUCKET_COUNT;

    return $index;
  }

  /**
   * Remove the item hash table entry and frees its chunk.
   */
  private function removeItem( $key, $chunkOffset ) {

    $bucketIndex = $this->getHashTableBucketIndex( $key );
    $bucketHeadChunkOffset = $this->getHeadChunkOffsetForBucketIndex( $bucketIndex );

    // Hash table bucket is empty
    if ( $bucketHeadChunkOffset < 0 ) {
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

      $nextItem = $this->getChunkMetaByOffset( $nextItemMetaOffset );
      $this->writeItemKey( $nextHashTableIndex, 0 );
      $this->addItemKey( $nextItem[ 'key' ], $nextItemMetaOffset );
      $nextHashTableIndex = ( $nextHashTableIndex + 1 ) % $this->KEYS_SLOTS;
    }

    return true;
  }
}

