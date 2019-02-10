<?php

namespace Crusse\ShmCache\Tests;

require_once __DIR__ .'/../../../../functions.php';

class ParallelismTest extends \PHPUnit\Framework\TestCase {

  const PARALLEL_WORKERS = 6;
  const WORKER_TIMEOUT = 5;
  const WORKER_JOBS = 200;
  const ITERATIONS = 20;
  const CACHE_SIZE = 16777216;

  private $cache;
  private $memory;
  private $server;

  // Run before each test method
  function setUp() {
    // Fail on infinite loops or failure to acquire locks
    set_time_limit( 30 );

    // Destroy the cache to delete any previous shared memory block created by
    // ShmCache, so that we can be sure ShmCache creates a memory block of
    // CACHE_SIZE
    $cache = new \Crusse\ShmCache( self::CACHE_SIZE );
    $this->assertSame( true, $cache->destroy() );

    $this->cache = new \Crusse\ShmCache( self::CACHE_SIZE );
    $this->memory = new \Crusse\ShmCache\Memory( self::CACHE_SIZE );

    $this->assertSame( true, $this->cache->flush() );

    // Sanity check to make sure our memory is actually 16 MB, and not a much
    // larger size due to an earlier ShmCache instantiaton, as ShmCache uses
    // the largest shared memory size ever reserved
    $this->assertLessThanOrEqual( self::CACHE_SIZE, $this->memory->SHM_SIZE );
  }

  function tearDown() {
    $this->assertSame( true, $this->cache->destroy() );
    unset( $this->memory );
    unset( $this->cache );
  }

  function testParallelSetOfDifferentKeys() {

    // Iterate the test many times for a better chance to hit a possible
    // deadlock etc.
    for ( $j = 0; $j < self::ITERATIONS; $j++ ) {

      // In case of infinite loops due to deadlocks etc.
      set_time_limit( 30 );

      $server = new \Crusse\JobServer\Server( self::PARALLEL_WORKERS );
      $server->addWorkerInclude( __DIR__ .'/../../../../functions.php' );
      $server->setWorkerTimeout( self::WORKER_TIMEOUT );

      for ( $i = 0; $i < self::WORKER_JOBS; $i++ ) {
        $server->addJob( 'add_and_set_cache_values', 'job'. $i );
      }

      // Run the background jobs. Note that the JobServer logs background
      // worker errors to /var/log/syslog, not PHPUnit stdout, so check syslog
      // if this unit test fails.
      try {
        $res = $server->getOrderedResults();
      }
      catch ( \Exception $e ) {
        error_log( 'See /var/log/syslog for errors' );
        throw $e;
      }

      // We only expect to see the last few cache items due to limited size in
      // the cache (earlier items were flushed out of the way of later items)

      for ( $i = 0; $i < self::WORKER_JOBS; $i++ ) {
        $jobValue = $res[ $i ];
        $cacheValue = $this->cache->get( 'job'. $i );

        if ( $cacheValue !== false ) {
          $this->assertSame( true, $jobValue === $cacheValue, 'See /var/log/syslog for errors; the cache item "job'. $i .'" value is incorrect: '. var_export( $cacheValue, true ) . PHP_EOL . PHP_EOL . $this->memory->getZoneUsageDump() . $this->memory->getBucketUsageDump() );
          $valueCount++;
        }
      }
    }
  }

  function testParallelSetOfIdenticalKeys() {

    $handleWorkerResult = function( $result, $jobNumber, $total ) {
      $this->assertEquals( true, preg_match( '#^x+$#', $this->cache->get( 'identicalkey' ) ), 'See /var/log/syslog for errors' . PHP_EOL . PHP_EOL . $this->memory->getZoneUsageDump() );
    };

    // Iterate the test many times for a better chance to hit a possible
    // deadlock etc.
    for ( $j = 0; $j < self::ITERATIONS; $j++ ) {

      // In case of infinite loops due to deadlocks etc.
      set_time_limit( 30 );

      $server = new \Crusse\JobServer\Server( self::PARALLEL_WORKERS );
      $server->addWorkerInclude( __DIR__ .'/../../../../functions.php' );
      $server->setWorkerTimeout( self::WORKER_TIMEOUT );

      for ( $i = 0; $i < self::WORKER_JOBS; $i++ ) {
        $server->addJob( 'set_cache_values', 'identicalkey' );
      }

      // Run the background jobs. Note that the JobServer logs background
      // worker errors to /var/log/syslog, not PHPUnit stdout, so check syslog
      // if this unit test fails.
      try {
        $server->getResults( $handleWorkerResult );
      }
      catch ( \Exception $e ) {
        error_log( 'See /var/log/syslog for errors' . PHP_EOL . PHP_EOL . $this->memory->getZoneUsageDump() );
        throw $e;
      }
    }
  }
}

