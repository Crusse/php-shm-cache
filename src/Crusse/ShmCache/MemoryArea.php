<?php

namespace Crusse\ShmCache;

/**
 * Represents a part of a Memory.
 */
class MemoryArea {

  // Start offset of this area in $shmBlock (inclusive)
  public $startOffset;
  // End offset of this area in $shmBlock (exclusive)
  public $endOffset;
  public $size;

  private $shm;

  function __construct( $shmBlock, $startOffset, $size ) {

    $this->shm = $shmBlock;
    $this->startOffset = $startOffset;
    $this->endOffset = $startOffset + $size;
    $this->size = $size;
  }

  function write( $offset, $data ) {

    assert( $this->startOffset + $offset + strlen( $data ) <= $this->endOffset );

    $ret = shmop_write( $this->shm, $data, $this->startOffset + $offset );

    if ( $ret === false ) {
      trigger_error( 'Could not write to '. __CLASS__ );
      return false;
    }

    return true;
  }

  function read( $offset, $byteCount ) {

    assert( $this->startOffset + $offset + $byteCount <= $this->endOffset );

    $ret = shmop_read( $this->shm, $this->startOffset + $offset, $byteCount );

    if ( $ret === false ) {
      trigger_error( 'Could not read from '. __CLASS__ );
      return false;
    }

    return $ret;
  }
}

