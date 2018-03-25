<?php

namespace Crusse\ShmCache;

/**
 * Represents a part of the whole ShmCache shared memory block.
 */
class MemoryArea {

  private $shmBlock;

  // Start offset of this area in $shmBlock (inclusive)
  public $startOffset;
  // End offset of this area in $shmBlock (exclusive)
  public $endOffset;
  public $size;

  function __construct( MemoryBlock $shmBlock, $startOffset, $size ) {

    $this->shmBlock = $shmBlock;
    $this->startOffset = $startOffset;
    $this->endOffset = $startOffset + $size;
    $this->size = $size;
  }
}

