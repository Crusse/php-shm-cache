<?php

namespace Crusse\ShmCache\Tests;

class PublicInterfaceTest extends \PHPUnit\Framework\TestCase {

  private $cache;

  // Run before each test method
  function setUp() {
    $this->cache = new \Crusse\ShmCache( 16 * 1024 * 1024 );
    $this->assertSame( true, $this->cache->flush() );
  }

  function tearDown() {
    $this->assertSame( true, $this->cache->destroy() );
  }

  function testSetAndGet() {

    $this->assertSame( false, $this->cache->get( 'foo' ) );
    $this->assertSame( true, $this->cache->set( 'foo', 'bar' ) );
    $this->assertSame( 'bar', $this->cache->get( 'foo' ) );
  }

  function testExists() {

    $this->assertSame( false, $this->cache->exists( 'foo' ) );
    $this->assertSame( true, $this->cache->set( 'foo', 'bar' ) );
    $this->assertSame( true, $this->cache->exists( 'foo' ) );
  }

  function testAdd() {

    $this->assertSame( true, $this->cache->add( 'foo', 'bar' ) );
    $this->assertSame( 'bar', $this->cache->get( 'foo' ) );
    $this->assertSame( false, $this->cache->add( 'foo', 'baz' ) );
    $this->assertSame( 'bar', $this->cache->get( 'foo' ) );
  }

  function testReplace() {

    $this->assertSame( false, $this->cache->replace( 'foo', 'baz' ) );
    $this->assertSame( true, $this->cache->set( 'foo', 'bar' ) );
    $this->assertSame( true, $this->cache->replace( 'foo', 'baz' ) );
    $this->assertSame( 'baz', $this->cache->get( 'foo' ) );
  }

  function testDelete() {

    $this->assertSame( false, $this->cache->delete( 'foo' ) );
    $this->assertSame( true, $this->cache->set( 'foo', 'bar' ) );
    $this->assertSame( 'bar', $this->cache->get( 'foo' ) );
    $this->assertSame( true, $this->cache->delete( 'foo' ) );
    $this->assertSame( false, $this->cache->get( 'foo' ) );
  }

  function testIncrement() {

    $this->assertSame( 1, $this->cache->increment( 'foo' ) );
    $this->assertSame( true, $this->cache->set( 'foo', 'not numeric' ) );
    $this->assertSame( false, @$this->cache->increment( 'foo' ) );
    $this->assertSame( true, $this->cache->delete( 'foo' ) );
    $this->assertSame( 2, $this->cache->increment( 'foo', 2 ) );
    $this->assertSame( 5, $this->cache->increment( 'foo', 3 ) );
    $this->assertSame( true, $this->cache->delete( 'foo' ) );
    $this->assertSame( 4, $this->cache->increment( 'foo', 2, 2 ) );
    $this->assertSame( 6, $this->cache->increment( 'foo', 2, 2 ) );
  }

  function testDecrement() {

    $this->assertSame( 0, $this->cache->decrement( 'foo' ) );
    $this->assertSame( 0, $this->cache->decrement( 'foo', 5 ) );
    $this->assertSame( 0, $this->cache->decrement( 'foo', 5, 10 ) );
    $this->assertSame( true, $this->cache->set( 'foo', 'not numeric' ) );
    $this->assertSame( false, @$this->cache->decrement( 'foo' ) );
    $this->assertSame( true, $this->cache->delete( 'foo' ) );
    $this->assertSame( 4, $this->cache->decrement( 'foo', 1, 5 ) );
    $this->assertSame( 2, $this->cache->decrement( 'foo', 2 ) );
  }
}

