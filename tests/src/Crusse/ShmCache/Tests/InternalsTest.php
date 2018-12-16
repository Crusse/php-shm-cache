<?php

namespace Crusse\ShmCache\Tests;

class InternalsTest extends \PHPUnit\Framework\TestCase {

  function testTooLargeValue() {

    $cacheSize = 1024 * 1024 * 16;

    $cache = @new \Crusse\ShmCache( $cacheSize );
    $this->assertSame( true, $cache->flush() );

    $memory = new \Crusse\ShmCache\Memory( $cacheSize, new \Crusse\ShmCache\LockManager );
    $maxValueSize = $memory->MAX_CHUNK_SIZE - $memory->CHUNK_META_SIZE;

    $this->assertSame( true, $cache->set( 'foo', str_repeat( 'x', $maxValueSize ) ) );
    $this->assertSame( false, @$cache->set( 'foo', str_repeat( 'x', $maxValueSize + 1 ) ) );
  }

  function testRemoveOldestItemsWhenValueIsAreaFull() {

    // 16 MB cache
    $cache = @new \Crusse\ShmCache( 1024 * 1024 * 16 );
    $this->assertSame( true, $cache->flush() );

    // Try to store 100 items of 1 MB size
    for ( $i = 0; $i < 100; ++$i ) {
      $this->assertSame( true, $cache->set( 'foo'. $i, str_repeat( 'x', 1024 * 1024 ) ) );
    }

    // We expect the last 15 stored items are still available (not all of the
    // 16 MB of the cache is available for storage, which is why we don't
    // expect 16 values to be available).
    for ( $i = 85; $i < 100; ++$i ) {
      $this->assertSame( 0, strpos( $cache->get( 'foo'. $i ), 'xxxxxx' ), 'Could not read "foo'. $i .'"' );
    }

    $this->assertSame( true, $cache->destroy() );
  }

  function testRemoveOldestItemsWhenValueAreaIsFullWithRandomValueSizes() {

    // 16 MB cache
    $cache = @new \Crusse\ShmCache( 1024 * 1024 * 16 );
    $this->assertSame( true, $cache->flush() );

    // Try to store 5000 items of random size
    for ( $i = 0; $i < 5000; ++$i ) {
      $this->assertSame( true, $cache->set( 'foo'. $i, str_repeat( 'x', rand( 1, 1024 * 1024 ) ) ) );
    }

    // We expect the last 15 stored items are still available (not all of the
    // 16 MB of the cache is available for storage, which is why we don't
    // expect 16 values to be available).
    for ( $i = 4985; $i < 5000; ++$i ) {
      $this->assertSame( 0, strpos( $cache->get( 'foo'. $i ), 'xxxxxx' ), 'Could not read "foo'. $i .'"' );
    }

    $this->assertSame( true, $cache->destroy() );
  }

  function testRemoveOldestItemsWhenMaxItemCountIsReached() {

    // 16 MB cache
    $cache = @new \Crusse\ShmCache( 1024 * 1024 * 16 );
    $this->assertSame( true, $cache->flush() );

    $stats = $cache->getStats();
    $minSizePerItem = $stats->itemMetadataSize + $stats->minItemValueSize;

    // If we always hit the memory limit before the item count limit, we cannot
    // test exceeding the item count limit
    $this->assertSame( true, $minSizePerItem * $stats->maxItems < $stats->availableValueMemSize );

    $excess = min( 1000, $stats->maxItems );

    // Try to store more items than there are slots for
    for ( $i = 0; $i < $stats->maxItems + $excess; ++$i ) {
      // Store 1-byte values
      $this->assertSame( true, $cache->set( 'foo'. $i, 'x' ) );
    }

    for ( $i = $excess; $i < $stats->maxItems + $excess; ++$i ) {
      $this->assertSame( 'x', $cache->get( 'foo'. $i ) );
    }

    $this->assertSame( true, $cache->destroy() );
  }

  function testRemoveOldestItemsWhenMaxItemCountIsReachedWithRandomValueSizes() {

    // 16 MB cache
    $cache = @new \Crusse\ShmCache( 1024 * 1024 * 16 );
    $this->assertSame( true, $cache->flush() );

    $stats = $cache->getStats();
    $minSizePerItem = $stats->itemMetadataSize + $stats->minItemValueSize;

    // If we always hit the memory limit before the item count limit, we cannot
    // test exceeding the item count limit
    $this->assertSame( true, $minSizePerItem * $stats->maxItems < $stats->availableValueMemSize );

    $excess = min( 5000, $stats->maxItems );

    // Try to store more items than there are slots for
    for ( $i = 0; $i < $stats->maxItems + $excess; ++$i ) {
      // Store values with sizes that will either fit fully in
      // minItemValueSize, or exceed that by 1 byte, so that ShmCache will
      // have to do splitting and merging of items
      $this->assertSame( true, $cache->set( 'foo'. $i, str_repeat( 'x', $stats->minItemValueSize + rand( 0, 1 ) ) ) );
    }

    // We expects at least half of the last stored items to exist in the cache
    for ( $i = $stats->maxItems + $excess - 1, $j = 0; $j < $stats->maxItems / 2; --$i, ++$j ) {
      $this->assertSame( 0, strpos( $cache->get( 'foo'. $i ), str_repeat( 'x', $stats->minItemValueSize ) ) );
    }

    $this->assertSame( true, $cache->destroy() );
  }
}

