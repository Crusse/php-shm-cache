<?php

namespace Crusse\ShmCache;

/**
 * Represents a part of the whole ShmCache shared memory block.
 */
class MemoryArea {

  private $shmBlock;

  public $startOffset;
  public $size;

  function __construct( MemoryBlock $shmBlock, $startOffset, $size ) {

    $this->shmBlock = $shmBlock;
    $this->startOffset = $startOffset;
    $this->size = $size;
  }
}

