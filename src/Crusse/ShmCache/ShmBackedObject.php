<?php

namespace Crusse\ShmCache;

/**
 * A C-struct-like class that maps a PHP object's properties to shared memory.
 */
class ShmBackedObject {

  public $_memory; // MemoryArea
  public $_startOffset; // Relative to MemoryArea start
  public $_size; // int
  public $_endOffset; // Relative to MemoryArea start
  public $_properties; // array

  // If true, illegal lock operations will throw exceptions. See LOCKING.md for
  // the locking rules. This should only be used in development as it's slow.
  private static $validateLockRules = false;
  private static $locks = null; // LockManager

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

    if ( getenv( 'SHMCACHE_VALIDATE_LOCK_RULES' ) || defined( 'SHMCACHE_VALIDATE_LOCK_RULES' ) ) {
      self::$validateLockRules = true;

      if ( self::$locks === null )
        self::$locks = LockManager::getInstance();
    }

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

    if ( self::$validateLockRules )
      $this->validateLockingRules( $name, false );

    return isset( $this->_properties[ $name ] );
  }

  function __get( $name ) {

    if ( self::$validateLockRules )
      $this->validateLockingRules( $name, false );

    return $this->readProperty( $name );
  }

  function __set( $name, $value ) {

    if ( self::$validateLockRules )
      $this->validateLockingRules( $name, true );

    return $this->writeProperty( $name, $value );
  }

  function __unset( $name ) {
    // We'll rely on __set()'s pack() to properly convert null into whatever bytes
    // 'packformat' defines (e.g. 4 NUL bytes, 255 whitespace-padded chars, ...)
    return $this->__set( $name, null );
  }

  private function readProperty( $propName ) {

    $prop = @$this->_properties[ $propName ];

    if ( !isset( $prop ) )
      throw new \Exception( $propName .' does not exist' );

    $data = $this->_memory->read( $this->_startOffset + $prop[ 'offset' ], $prop[ 'size' ] );

    if ( $data === false )
      return null;

    return unpack( $prop[ 'packformat' ], $data )[ 1 ];
  }

  private function writeProperty( $propName, $value ) {

    // TODO: buffer writes until someone tries to read the memory. you'll need
    // to be careful that the object's memory is always read using this class,
    // and not directly with shmop_read(); maybe it's safest to add
    // an explicit "bufferWrites" bool property and a flushBuffer() method to this class.

    $prop = @$this->_properties[ $propName ];
    
    if ( !isset( $prop ) )
      throw new \Exception( $propName .' does not exist' );

    $written = $this->_memory->write( $this->_startOffset + $prop[ 'offset' ], pack( $prop[ 'packformat' ], $value ) );

    if ( !$written )
      return false;

    return true;
  }

  private function validateLockingRules( $propName, $writing = false ) {

    // All read and write operations against shared memory require the
    // "everything" lock
    if ( !self::$locks::$everything->isLockedForRead() )
      throw new \Exception( 'All read and write operations require the "everything" lock' );

    if ( in_array( 'bucket', $this->_properties[ $propName ][ 'requiredlocks' ] ) ) {

      // This ShmBackedObject is a 'chunk' object
      if ( isset( $this->_properties[ 'key' ] ) ) {
        $key = $this->readProperty( 'key' );

        // Only require a bucket lock if the key exists, i.e. the chunk is not free
        if ( strlen( $key ) ) {
          $lock = self::$locks->getBucketLock( Memory::getBucketIndex( $key ) );
          $hasLock = ( $writing )
            ? $lock->isLockedForWrite()
            : $lock->isLockedForRead();

          if ( !$hasLock ) {
            throw new \Exception( 'The correct bucket lock is not held for "'. $key .'"->"'. $propName .'" (writing: '. var_export( $writing, true ) .')' );
          }
        }
      }
    }

    if ( in_array( 'zone', $this->_properties[ $propName ][ 'requiredlocks' ] ) ) {

      $zoneIndex = (int) floor( $this->_startOffset / Memory::ZONE_SIZE );
      $lock = self::$locks->getZoneLock( $zoneIndex );
      $hasLock = ( $writing )
        ? $lock->isLockedForWrite()
        : $lock->isLockedForRead();

      if ( !$hasLock ) {
        throw new \Exception( 'The correct zone lock is not held for "'. $propName .'" (writing: '. var_export( $writing, true ) .')' );
      }
    }

    if ( in_array( 'stats', $this->_properties[ $propName ][ 'requiredlocks' ] ) ) {
      $hasLock = false;

      if ( ( $writing && self::$locks::$stats->isLockedForWrite() ) ||
        ( !$writing && self::$locks::$stats->isLockedForRead() ) )
      {
        $hasLock = true;
      }

      if ( !$hasLock ) {
        throw new \Exception( 'The correct stats lock is not held for "'. $propName .'" (writing: '. var_export( $writing, true ) .')' );
      }
    }
  }
}

