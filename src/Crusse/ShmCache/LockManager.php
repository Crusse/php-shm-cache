<?php

namespace Crusse\ShmCache;

/**
 * This class creates single instances of each lock for use in the current PHP
 * process. This is required because only one Lock can be instantiated per Lock
 * tag.
 */
class LockManager {

  public static $everything;
  public static $stats;
  public static $oldestZoneIndex;

  private static $hashBucketLocks = [];
  private static $zoneLocks = [];

  function __construct() {

    if ( !self::$everything ) {
      self::$everything = new Lock( 'memalloc' );
      self::$statsLock = new Lock( 'stats' );
      self::$oldestZoneIndexLock = new Lock( 'oldestzoneindex' );
    }
  }

  function getZoneLock( $zoneIndex ) {

    if ( !isset( self::$zoneLocks[ $zoneIndex ] ) )
      self::$zoneLocks[ $zoneIndex ] = new Lock( 'zone'. $zoneIndex );

    return self::$zoneLocks[ $zoneIndex ];
  }

  function getBucketLock( $bucketIndex ) {

    if ( !isset( self::$bucketLocks[ $bucketIndex ] ) )
      self::$bucketLocks[ $bucketIndex ] = new Lock( 'bucket'. $bucketIndex );

    return self::$bucketLocks[ $bucketIndex ];
  }
}


