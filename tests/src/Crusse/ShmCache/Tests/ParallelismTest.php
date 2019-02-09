<?php

namespace Crusse\ShmCache\Tests;

class ParallelismTest extends \PHPUnit\Framework\TestCase {

  const PARALLEL_WORKERS = 6;
  const WORKER_TIMEOUT = 5;
  const WORKER_JOBS = 100;
  const ITERATIONS = 30;

  private $cache;
  private $server;

  // Run before each test method
  function setUp() {

    $this->cache = new \Crusse\ShmCache( 16 * 1024 * 1024 );
    $this->assertSame( true, $this->cache->flush() );
  }

  function tearDown() {
    $this->assertSame( true, $this->cache->destroy() );
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
        $server->addJob( 'add_and_set_random_cache_values', 'job'. $i );
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
      $expectToSeeThisMany = 5;

      for ( $i = self::WORKER_JOBS - 1, $j = 0; $j < $expectToSeeThisMany; $i--, $j++ ) {
        $jobValue = $res[ $i ];
        $cacheValue = $this->cache->get( 'job'. $i );
        $this->assertSame( true, $jobValue === $cacheValue, 'See /var/log/syslog for errors; the cache item "job'. $i .'" value is incorrect: '. var_export( $cacheValue, true ) );
      }
    }
  }

  function testParallelSetOfIdenticalKeys() {

    $handleWorkerResult = function( $result, $jobNumber, $total ) {
      $this->assertEquals( true, preg_match( '#^x+$#', $this->cache->get( 'identicalkey' ) ), 'See /var/log/syslog for errors' );
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
        $server->addJob( 'set_random_cache_values', 'identicalkey' );
      }

      // Run the background jobs. Note that the JobServer logs background
      // worker errors to /var/log/syslog, not PHPUnit stdout, so check syslog
      // if this unit test fails.
      try {
        $server->getResults( $handleWorkerResult );
      }
      catch ( \Exception $e ) {
        error_log( 'See /var/log/syslog for errors' );
        throw $e;
      }
    }
  }
}

