<?php

namespace Crusse\ShmCache\Tests;

class ParallelismTest extends \PHPUnit\Framework\TestCase {

  const PARALLEL_WORKERS = 6;
  const WORKER_TIMEOUT = 5;
  const WORKER_JOBS = 500;

  private $cache;
  private $server;

  // Run before each test method
  function setUp() {

    $this->cache = new \Crusse\ShmCache( 16 * 1024 * 1024 );
    $this->assertSame( true, $this->cache->flush() );

    // In case of infinite loops due to deadlocks etc.
    set_time_limit( 60 );
  }

  function tearDown() {
    $this->assertSame( true, $this->cache->destroy() );
  }

  function testParallelSetOfDifferentKeys() {

    // Iterate the test many times for a better chance to hit a possible
    // deadlock etc.
    for ( $j = 0; $j < 50; $j++ ) {

      $server = new \Crusse\JobServer\Server( self::PARALLEL_WORKERS );
      $server->addWorkerInclude( __DIR__ .'/../../../../functions.php' );
      $server->setWorkerTimeout( self::WORKER_TIMEOUT );

      for ( $i = 0; $i < self::WORKER_JOBS; $i++ ) {
        $server->addJob( 'add_and_set_random_cache_values', 'job'. $i );
      }

      // Run the background jobs. Note that the JobServer logs background
      // worker errors to /var/log/syslog, not PHPUnit stdout, so check syslog
      // if this unit test fails.
      $res = $server->getOrderedResults();

      for ( $i = 0; $i < self::WORKER_JOBS; $i++ ) {
        $value = $res[ $i ];
        $this->assertSame( $value, $this->cache->get( 'job'. $i ), 'Reading cache key "job'. $i .'"' );
      }
    }
  }

  function testParallelSetOfIdenticalKeys() {

    $handleWorkerResult = function( $result, $jobNumber, $total ) {
      $this->assertEquals( true, preg_match( '#^x+$#', $this->cache->get( 'identicalkey' ) ) );
    };

    // Iterate the test many times for a better chance to hit a possible
    // deadlock etc.
    for ( $j = 0; $j < 50; $j++ ) {

      $server = new \Crusse\JobServer\Server( self::PARALLEL_WORKERS );
      $server->addWorkerInclude( __DIR__ .'/../../../../functions.php' );
      $server->setWorkerTimeout( self::WORKER_TIMEOUT );

      for ( $i = 0; $i < self::WORKER_JOBS; $i++ ) {
        $server->addJob( 'set_random_cache_values', 'identicalkey' );
      }

      // Run the background jobs. Note that the JobServer logs background
      // worker errors to /var/log/syslog, not PHPUnit stdout, so check syslog
      // if this unit test fails.
      $server->getResults( $handleWorkerResult );
    }
  }
}

