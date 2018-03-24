<?php

namespace Crusse\ShmCache;

/**
 * Represents a shared memory block for ShmCache. Acts as a singleton: if you
 * instantiate this multiple times, you'll always get back the same shared
 * memory block. This makes sure ShmCache always allocates the desired amount
 * of memory at maximum, and no more.
 */
class MemoryBlock {

  // Total amount of space to allocate for the shared memory block. This will
  // contain both the keys area and the values area, so the amount allocatable
  // for values will be slightly smaller.
  const DEFAULT_CACHE_SIZE = 134217728;
  const SAFE_AREA_SIZE = 1024;

  private $shm;
  private $shmKey;

  public $metaArea;
  public $statsArea;
  public $hashArea;

  function __construct( $desiredSize, $hashBucketCount ) {

    $this->initMemBlock( $desiredSize, $hashBucketCount );

    $this->metaArea = new ShmCache\MemoryArea( $this->shm, 0, 1024 );
    $this->statsArea = new ShmCache\MemoryArea( $this->shm, $this->metaArea->startOffset + self::SAFE_AREA_SIZE, 1024 );
    $this->hashArea = new ShmCache\MemoryArea( $this->shm, $this->statsArea->startOffset + self::SAFE_AREA_SIZE,  );
  }

  private function initMemBlock( $desiredSize, $hashBucketCount ) {

    if ( !$this->memAllocLock->getReadLock() )
      throw new \Exception( 'Could not get a lock' );

    $opened = $this->openMemBlock();

    // Try to re-create an existing block with a larger size, if the block is
    // smaller than the desired size. This fails (at least on Linux) if the
    // Unix user trying to delete the block is different than the user that
    // created the block.
    if ( $opened && $desiredSize ) {

      $currentSize = shmop_size( $this->shm );

      if ( !$this->memAllocLock->releaseLock() )
        throw new \Exception( 'Could not release a lock' );

      if ( $currentSize < $desiredSize ) {

        if ( !$this->memAllocLock->getWriteLock() )
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

        if ( !$this->memAllocLock->releaseLock() )
          throw new \Exception( 'Could not release a lock' );
      }
    }
    else {

      if ( !$this->memAllocLock->releaseLock() )
        throw new \Exception( 'Could not release a lock' );
    }

    // No existing memory block. Create a new one.
    if ( !$opened ) {
      if ( !$this->memAllocLock->getWriteLock() )
        throw new \Exception( 'Could not get a lock' );

      $this->createMemBlock( $desiredSize );
    }
    else {
      if ( !$this->memAllocLock->getReadLock() )
        throw new \Exception( 'Could not get a lock' );
    }

    $this->populateSizes();

    // A new memory block. Write initial values.
    if ( !$opened ) {
      $this->clearMemBlock();

      if ( !$this->memAllocLock->releaseLock() )
        throw new \Exception( 'Could not release a lock' );
    }
    else {
      if ( !$this->memAllocLock->releaseLock() )
        throw new \Exception( 'Could not release a lock' );
    }

    return (bool) $this->shm;
  }

  private function populateSizes( $hashBucketCount, $maxKeyLength ) {

    $this->LONG_SIZE = strlen( pack( 'l', 1 ) ); // i.e. sizeof(long) in C
    $this->CHAR_SIZE = strlen( pack( 'c', 1 ) ); // i.e. sizeof(char) in C

    $this->BLOCK_SIZE = shmop_size( $this->shm );

    $allocatedSize = $this->LONG_SIZE;
    $valueSize = $this->LONG_SIZE;
    $flagsSize = $this->CHAR_SIZE;
    $this->ITEM_META_SIZE = $maxKeyLength + $allocatedSize + $valueSize + $flagsSize;
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

  private function destroyMemBlock() {

    $this->flushBufferedStatsToShm();

    $deleted = shmop_delete( $this->shm );

    if ( !$deleted ) {
      throw new \Exception( 'Could not destroy the memory block. Try running the \'ipcrm\' command as a super-user to remove the memory block listed in the output of \'ipcs\'.' );
    }

    shmop_close( $this->shm );
    $this->shm = null;
  }
}

