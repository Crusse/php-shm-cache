<?php

namespace Crusse\ShmCache;

/**
 * Represents a part of the whole ShmCache shared memory block.
 */
class MemoryArea {

  // Start offset of this area in $shmBlock (inclusive)
  public $startOffset;
  // End offset of this area in $shmBlock (exclusive)
  public $endOffset;
  public $size;

  private $shm;

  function __construct( MemoryBlock $shmBlock, $startOffset, $size ) {

    $this->shm = $shmBlock;
    $this->startOffset = $startOffset;
    $this->endOffset = $startOffset + $size;
    $this->size = $size;
  }

  function write( $offset, $data ) {

    $ret = shmop_write( $this->shm, $data, $this->startOffset + $offset );

    if ( $ret === false ) {
      trigger_error( 'Could not write to '. __CLASS__ );
      return false;
    }

    return true;
  }

  function read( $offset, $byteCount ) {

    $data = shmop_read( $this->shm, $this->startOffset + $offset, $byteCount );

    if ( $ret === false ) {
      trigger_error( 'Could not read from '. __CLASS__ );
      return false;
    }

    return $data;
  }
}

