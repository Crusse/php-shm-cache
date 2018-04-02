<?php

namespace Crusse\ShmCache;

class LockManager {

  public $everything;
  public $stats;
  public $oldestZoneIndex;

  private $hashBucketLocks = [];
  private $zoneLock;

  function __construct() {

    $this->memAllocLock = new Lock( 'memalloc' );
    $this->statsLock = new Lock( 'stats' );
    $this->oldestZoneIndexLock = new Lock( 'oldestzoneindex' );
  }

  function getZoneLock( $zoneIndex ) {

    if ( !$this->zoneLock )
      $this->zoneLock = new Lock( 'zone'. $zoneIndex );

    return $this->zoneLock;
  }

  function getBucketLock( $bucketIndex ) {

    if ( !isset( $this->bucketLocks[ $bucketIndex ] ) )
      $this->bucketLocks[ $bucketIndex ] = new Lock( 'bucket'. $bucketIndex );

    return $this->bucketLocks[ $bucketIndex ];
  }
}


