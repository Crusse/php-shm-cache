<?php

namespace Crusse\ShmCache;

class Stats {

  public $getHits = 0;
  public $getMisses = 0;

  private $locks;
  private $statsObject;

  function __construct( Memory $memory ) {

    $this->locks = LockManager::getInstance();
    $this->statsObject = $memory->getStatsObject();
  }

  function getStats() {

    // TODO
    return [];

    if ( !$this->locks::$everything->lockForRead() )
      throw new \Exception( 'Could not get a lock' );

    $ret = (object) [
      'items' => 0,
      'maxItems' => $this->MAX_ITEMS,
      'availableHashTableSlots' => $this->KEYS_SLOTS,
      'usedHashTableSlots' => 0,
      'hashTableLoadFactor' => 0,
      'hashTableMemorySize' => $this->KEYS_SIZE,
      'availableValueMemSize' => $this->VALUES_SIZE,
      'usedValueMemSize' => 0,
      'avgItemValueSize' => 0,
      'oldestZoneIndex' => $this->getOldestZoneIndex(),
      'getHitCount' => $this->getGetHits(),
      'getMissCount' => $this->getGetMisses(),
      'itemMetadataSize' => $this->CHUNK_META_SIZE,
      'minItemValueSize' => self::MIN_VALUE_ALLOC_SIZE,
      'maxItemValueSize' => self::MAX_CHUNK_SIZE,
    ];

    for ( $i = $this->KEYS_START; $i < $this->KEYS_START + $this->KEYS_SIZE; $i += $this->LONG_SIZE ) {
      // TODO: acquire item lock?
      if ( unpack( 'l', shmop_read( $this->shm, $i, $this->LONG_SIZE ) )[ 1 ] !== 0 )
        ++$ret->usedHashTableSlots;
    }

    $ret->hashTableLoadFactor = $ret->usedHashTableSlots / $ret->availableHashTableSlots;

    for ( $i = $this->VALUES_START; $i < $this->VALUES_START + $this->VALUES_SIZE; ) {

      // TODO: acquire item lock?
      $item = $this->getChunkByOffset( $i );

      if ( $item[ 'valsize' ] ) {
        ++$ret->items;
        $ret->usedValueMemSize += $item[ 'valsize' ];
      }

      $i += $this->CHUNK_META_SIZE + $item[ 'valallocsize' ];
    }

    if ( !$this->locks::$everything->releaseRead() )
      throw new \Exception( 'Could not release a lock' );

    $ret->avgItemValueSize = ( $ret->items )
      ? $ret->usedValueMemSize / $ret->items
      : 0;

    return $ret;
  }

  /**
   * Flush all buffered stats to shared memory.
   */
  function flushToShm() {

    if ( $this->locks::$stats->lockForWrite() ) {

      if ( $this->getHits ) {
        $this->statsObject->gethits += $this->getHits;
        $this->getHits = 0;
      }

      if ( $this->getMisses ) {
        $this->statsObject->getmisses += $this->getMisses;
        $this->getMisses = 0;
      }

      $this->locks::$stats->releaseWrite();
    }
  }
}

