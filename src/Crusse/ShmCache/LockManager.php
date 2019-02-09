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

  private static $bucketLocks = [];
  private static $zoneLocks = [];

  // Prevent instantiation
  private function __construct() {}

  static function getInstance() {

    static $instance;

    if ( !$instance ) {
      self::$everything = new Lock( 'everything' );
      self::$stats = new Lock( 'stats' );
      self::$oldestZoneIndex = new Lock( 'oldestzoneindex' );

      $instance = new static;
    }

    return $instance;
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

