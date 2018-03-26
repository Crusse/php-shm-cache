<?php

namespace Crusse\ShmCache;

/**
 * "You Can Write FORTRAN in any Language". This is a C-struct-like class that
 * maps the PHP object's properties to shared memory.
 */
class ShmBackedObject {

  public $_shmBlock;
  public $_startOffset;
  public $_properties;

  /**
   * @param array $propertiesSpec E.g. ['offset' => 8, 'size' => 4, 'packformat' => 'l']
   */
  static function createPrototype( $shmBlock, array $propertiesSpec ) {

    $proto = new static;
    $proto->_shmBlock = $shmBlock;
    $proto->_properties = $propertiesSpec;
    $proto->_startOffset = 0;

    return $proto;
  }

  function createInstance( $startOffset ) {

    $ret = clone $this;
    $ret->_startOffset = $startOffset;

    return $ret;
  }

  function __isset( $name ) {
    return isset( $this->_properties[ $name ] );
  }

  function __get( $name ) {

    $prop = @$this->_properties[ $name ];
    
    if ( !isset( $prop ) )
      throw new \Exception( $name .' does not exist' );

    $data = shmop_read(
      $this->_shmBlock,
      $this->_startOffset + $prop[ 'offset' ],
      $prop[ 'size' ]
    );

    if ( !$data )
      return null;

    return unpack( $prop[ 'packformat' ], $data )[ 0 ];
  }

  function __set( $name, $value ) {

    // TODO: buffer writes until someone tries to read the memory. you'll need
    // to be careful that the object's memory is always read using this class,
    // and not directly with shmop_read(); maybe it's safest to add
    // an explicit "bufferWrites" bool property and a flushBuffer() method to this class.

    $prop = @$this->_properties[ $name ];
    
    if ( !isset( $prop ) )
      throw new \Exception( $name .' does not exist' );

    $wrote = shmop_write(
      $this->_shmBlock,
      pack( $prop[ 'packformat' ], $value ),
      $this->_startOffset + $prop[ 'offset' ]
    );

    if ( !$wrote )
      return false;

    return true;
  }

  function __unset( $name ) {
    // We'll rely on __set()'s pack() to properly convert null into whatever bytes
    // 'packformat' defines (e.g. 4 NUL bytes, 255 whitespace-padded chars, ...)
    return $this->__set( $name, null );
  }
}

