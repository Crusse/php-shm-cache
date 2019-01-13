<?php

namespace Crusse\ShmCache;

/**
 * A C-struct-like class that maps a PHP object's properties to shared memory.
 */
class ShmBackedObject {

  public $_memory;
  public $_startOffset; // Relative to MemoryArea start
  public $_size;
  public $_endOffset; // Relative to MemoryArea start
  public $_properties;

  // Prevent direct instantiation
  final private function __construct() {}

  /**
   * @param array $propertiesSpec E.g. ['size' => 4, 'packformat' => 'l']
   */
  static function createPrototype( MemoryArea $memory, array $propertiesSpec ) {

    $offsetTotal = 0;

    foreach ( $propertiesSpec as $propName => $spec ) {
      $propertiesSpec[ $propName ][ 'offset' ] = $offsetTotal;
      $offsetTotal += $spec[ 'size' ];
    }

    $proto = new static;
    $proto->_memory = $memory;
    $proto->_properties = $propertiesSpec;
    $proto->_startOffset = 0;
    $proto->_size = $offsetTotal;
    $proto->_endOffset = $proto->_startOffset + $proto->_size;

    return $proto;
  }

  function createInstance( $startOffset ) {

    $ret = clone $this;
    $ret->_startOffset = $startOffset;
    $ret->_endOffset = $ret->_startOffset + $ret->_size;

    return $ret;
  }

  function toArray() {

    $ret = [
      '_startOffset' => $this->_startOffset,
      '_size' => $this->_size,
    ];

    foreach ( $this->_properties as $name => $prop ) {
      $ret[ $name ] = $this->$name;
    }

    return $ret;
  }

  function __isset( $name ) {
    return isset( $this->_properties[ $name ] );
  }

  function __get( $name ) {

    $prop = @$this->_properties[ $name ];

    if ( !isset( $prop ) )
      throw new \Exception( $name .' does not exist' );

    $data = $this->_memory->read( $this->_startOffset + $prop[ 'offset' ], $prop[ 'size' ] );

    if ( $data === false )
      return null;

    return unpack( $prop[ 'packformat' ], $data )[ 1 ];
  }

  function __set( $name, $value ) {

    // TODO: buffer writes until someone tries to read the memory. you'll need
    // to be careful that the object's memory is always read using this class,
    // and not directly with shmop_read(); maybe it's safest to add
    // an explicit "bufferWrites" bool property and a flushBuffer() method to this class.

    $prop = @$this->_properties[ $name ];
    
    if ( !isset( $prop ) )
      throw new \Exception( $name .' does not exist' );

    $written = $this->_memory->write( $this->_startOffset + $prop[ 'offset' ], pack( $prop[ 'packformat' ], $value ) );

    if ( !$written )
      return false;

    return true;
  }

  function __unset( $name ) {
    // We'll rely on __set()'s pack() to properly convert null into whatever bytes
    // 'packformat' defines (e.g. 4 NUL bytes, 255 whitespace-padded chars, ...)
    return $this->__set( $name, null );
  }
}

