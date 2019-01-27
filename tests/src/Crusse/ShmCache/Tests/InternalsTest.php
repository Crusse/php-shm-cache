<?php

namespace Crusse\ShmCache\Tests;

class InternalsTest extends \PHPUnit\Framework\TestCase {

  const CACHE_SIZE = 16777216;

  private $cache;

  function setUp() {
    // Fail on infinite loops or failure to acquire locks
    set_time_limit( 30 );

    // Destroy the cache to delete any previous shared memory block created by
    // ShmCache, so that we can be sure ShmCache creates a memory block of
    // CACHE_SIZE
    $cache = new \Crusse\ShmCache( self::CACHE_SIZE );
    $this->assertSame( true, $cache->destroy() );

    $this->cache = new \Crusse\ShmCache( self::CACHE_SIZE );

    // Make sure ShmCache created a memory block of CACHE_SIZE
    $memory = new \Crusse\ShmCache\Memory( self::CACHE_SIZE );
    $this->assertEquals( self::CACHE_SIZE, $memory->SHM_SIZE );
  }

  function tearDown() {
    $this->assertSame( true, $this->cache->destroy() );
  }

  function testTooLargeValue() {

    $memory = new \Crusse\ShmCache\Memory( self::CACHE_SIZE );

    $this->assertSame( true, $this->cache->set( 'foo', str_repeat( 'x', $memory->MAX_VALUE_SIZE ) ) );
    $this->assertSame( false, @$this->cache->set( 'foo', str_repeat( 'x', $memory->MAX_VALUE_SIZE + 1 ) ) );
  }

  function testRemoveOldestItemsWhenValueIsAreaFull() {

    $memory = new \Crusse\ShmCache\Memory( self::CACHE_SIZE );

    // Try to store 100 items of 1 MB size
    for ( $i = 0; $i < 100; ++$i ) {
      $this->assertSame( true, @$this->cache->set( 'foo'. $i, str_repeat( 'x', $memory->MAX_VALUE_SIZE ) ) );
    }

    // We expect the last 15 stored items are still available (not all of the
    // 16 MB of the cache is available for storage, which is why we don't
    // expect 16 values to be available).
    for ( $i = 85; $i < 100; ++$i ) {
      $this->assertSame( 0, strpos( $this->cache->get( 'foo'. $i ), 'xxxxxx' ), 'Could not read "foo'. $i .'"' );
    }
  }

  function testRemoveOldestItemsWhenMemoryIsFull() {

    $memory = new \Crusse\ShmCache\Memory( self::CACHE_SIZE );

    // Sanity check to make sure our memory is actually 16 MB, and not a much
    // larger size due to an earlier ShmCache instantiaton, as ShmCache uses
    // the largest shared memory size ever reserved
    $this->assertLessThanOrEqual( self::CACHE_SIZE, $memory->SHM_SIZE );
    $this->assertLessThan( self::CACHE_SIZE, $memory->MAX_TOTAL_VALUE_SIZE );

    $valueCount = $memory->MAX_CHUNKS + 50;
    $smallValue = 'xxxxx';
    $mediumValue = str_repeat( 'x', (int) ( $memory->MAX_VALUE_SIZE / 2 ) );
    $bigValue = str_repeat( 'x', $memory->MAX_VALUE_SIZE );

    // Try to store more than max amount of items
    for ( $i = 0; $i < $valueCount; ++$i ) {
      // Vary the value size for a more realistic test
      $value = ( $i % 3 === 0 )
        ? $smallValue
        : ( $i % 3 === 1 ? $bigValue : $mediumValue );
      $this->assertSame( true, $this->cache->set( 'foo'. $i, $value ) );
    }

    // We expect the last few stored items are still available (not all of the
    // 16 MB of the cache is available for storage, which is why we don't
    // expect 16 values to be available).
    for ( $i = $valueCount - 5; $i < $valueCount; ++$i ) {
      $this->assertSame( 0, strpos( $this->cache->get( 'foo'. $i ), 'xxxxx' ), 'Could not read "foo'. $i .'"' );
    }
  }
}

