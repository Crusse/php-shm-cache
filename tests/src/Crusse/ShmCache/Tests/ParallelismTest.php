<?php

namespace Crusse\ShmCache\Tests;

class ParallelismTest extends \PHPUnit\Framework\TestCase {

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

    for ( $j = 0; $j < 50; $j++ ) {

      $server = new \Crusse\JobServer\Server( 4 );
      $server->addWorkerInclude( __DIR__ .'/../../../../functions.php' );
      $server->setWorkerTimeout( 5 );

      $numJobs = 100;

      for ( $i = 0; $i < $numJobs; $i++ ) {
        $server->addJob( 'add_and_set_random_cache_values', 'job'. $i );
      }

      $res = $server->getOrderedResults();

      for ( $i = 0; $i < $numJobs; $i++ ) {
        $value = $res[ $i ];
        $this->assertSame( $value, $this->cache->get( 'job'. $i ), 'Reading cache key "job'. $i .'"' );
      }
    }
  }

  function testParallelSetOfIdenticalKeys() {

    for ( $j = 0; $j < 50; $j++ ) {

      $server = new \Crusse\JobServer\Server( 4 );
      $server->addWorkerInclude( __DIR__ .'/../../../../functions.php' );
      $server->setWorkerTimeout( 5 );

      $numJobs = 100;

      for ( $i = 0; $i < $numJobs; $i++ ) {
        $server->addJob( 'set_random_cache_values', 'identicalkey' );
      }

      $res = $server->getOrderedResults();

      for ( $i = 0; $i < $numJobs; $i++ ) {
        $this->assertSame( true, in_array( $this->cache->get( 'identicalkey' ), $res ) );
      }
    }
  }
}

