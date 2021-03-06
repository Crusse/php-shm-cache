<?php

namespace Crusse;

/**
 * A shared memory cache for storing data that is persisted across multiple PHP
 * script runs.
 * 
 * Features:
 * 
 * - Stores the hash table and items' values in Unix shared memory
 * - FIFO queue: tries to evict the oldest items when the cache is full
 *
 * The same memory block is shared by all instances of ShmCache. This means the
 * maximum amount of memory used by ShmCache is always DEFAULT_CACHE_SIZE, or
 * $desiredSize, if defined.
 *
 * You can use the Unix programs `ipcs` and `ipcrm` to list and remove the
 * memory block created by this class, if something goes wrong.
 *
 * It is important that the first instantiation and any further uses of this
 * class are with the same Unix user (e.g. 'www-data'), because the shared
 * memory block cannot be deleted (e.g. in destroy()) by another user, at least
 * on Linux. If you have problems deleting the memory block created by this
 * class via $cache->destroy(), using `ipcrm` as root is your best bet.
 */
class ShmCache {

  private $memory; // Memory
  private $locks; // LockManager
  private $stats; // Stats

  /**
   * @param $desiredSize The size of the shared memory block, which will contain all ShmCache data. If a block already exists and its size is larger, the block's size will not be reduced. If its size is smaller, it will be enlarged.
   *
   * @throws \Exception
   */
  function __construct( $desiredSize = 0 ) {

    if ( !is_int( $desiredSize ) ) {
      throw new \InvalidArgumentException( '$desiredSize must be an integer' );
    }
    else if ( $desiredSize && $desiredSize < 1024 * 1024 * 16 ) {
      throw new \InvalidArgumentException( '$desiredSize must be at least 16 MiB, but you defined it as '.
        round( $desiredSize / 1024 / 1024, 5 ) .' MiB' );
    }

    $this->locks = ShmCache\LockManager::getInstance();

    if ( !$this->locks::$everything->lockForWrite() )
      throw new \Exception( 'Could not get a lock' );

    try {
      $this->memory = new ShmCache\Memory( $desiredSize );
      $this->stats = new ShmCache\Stats( $this->memory );
    }
    catch ( \Exception $e ) {
      $this->locks::$everything->releaseWrite();
      throw $e;
    }

    if ( !$this->locks::$everything->releaseWrite() )
      throw new \Exception( 'Could not release a lock' );
  }

  function __destruct() {

    if ( $this->memory ) {
      $this->stats->flushToShm();
      unset( $this->memory );
    }
  }

  function set( $key, $value ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    if ( !$this->locks::$everything->lockForRead() )
      return false;

    $bucketLock = $this->locks->getBucketLock( ShmCache\Memory::getBucketIndex( $key ) );
    if ( !$bucketLock->lockForWrite() ) {
      $this->locks::$everything->releaseRead();
      return false;
    }

    $ret = $this->_set( $key, $value, $retIsSerialized );

    $bucketLock->releaseWrite();
    $this->locks::$everything->releaseRead();

    return $ret;
  }

  function get( $key ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    if ( !$this->locks::$everything->lockForRead() )
      return false;

    $bucketLock = $this->locks->getBucketLock( ShmCache\Memory::getBucketIndex( $key ) );
    if ( !$bucketLock->lockForRead() ) {
      $this->locks::$everything->releaseRead();
      return false;
    }

    $ret = $this->_get( $key, $retIsSerialized, $retIsCacheHit );

    $bucketLock->releaseRead();
    $this->locks::$everything->releaseRead();

    if ( $ret && $retIsSerialized )
      $ret = unserialize( $ret );

    if ( $retIsCacheHit )
      ++$this->stats->getHits;
    else
      ++$this->stats->getMisses;

    return $ret;
  }

  function exists( $key ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    if ( !$this->locks::$everything->lockForRead() )
      return false;

    $bucketLock = $this->locks->getBucketLock( ShmCache\Memory::getBucketIndex( $key ) );
    if ( !$bucketLock->lockForRead() ) {
      $this->locks::$everything->releaseRead();
      return false;
    }

    $ret = (bool) $this->memory->getChunkByKey( $key );

    $bucketLock->releaseRead();
    $this->locks::$everything->releaseRead();

    return $ret;
  }

  function add( $key, $value ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    if ( !$this->locks::$everything->lockForRead() )
      return false;

    $bucketLock = $this->locks->getBucketLock( ShmCache\Memory::getBucketIndex( $key ) );
    if ( !$bucketLock->lockForWrite() ) {
      $this->locks::$everything->releaseRead();
      return false;
    }

    $ret = $this->_set( $key, $value, $retIsSerialized, true );

    $bucketLock->releaseWrite();
    $this->locks::$everything->releaseRead();

    return $ret;
  }

  function replace( $key, $value ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $value = $this->maybeSerialize( $value, $retIsSerialized );

    if ( !$this->locks::$everything->lockForRead() )
      return false;

    $bucketLock = $this->locks->getBucketLock( ShmCache\Memory::getBucketIndex( $key ) );
    if ( !$bucketLock->lockForWrite() ) {
      $this->locks::$everything->releaseRead();
      return false;
    }

    $ret = $this->_set( $key, $value, $retIsSerialized, false, true );

    $bucketLock->releaseWrite();
    $this->locks::$everything->releaseRead();

    return $ret;
  }

  function increment( $key, $offset = 1, $initialValue = 0 ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );
    $offset = (int) $offset;
    $initialValue = (int) $initialValue;

    if ( !$this->locks::$everything->lockForRead() )
      return false;

    $bucketLock = $this->locks->getBucketLock( ShmCache\Memory::getBucketIndex( $key ) );
    if ( !$bucketLock->lockForWrite() ) {
      $this->locks::$everything->releaseRead();
      return false;
    }

    $value = $this->_get( $key, $retIsSerialized, $retIsCacheHit );
    if ( $retIsSerialized )
      $value = unserialize( $value );

    if ( $value === false ) {
      $value = $initialValue;
    }
    else if ( !is_numeric( $value ) ) {
      trigger_error( 'Item "'. $key .'" value is not numeric' );
      $bucketLock->releaseWrite();
      $this->locks::$everything->releaseRead();
      return false;
    }

    $value = max( $value + $offset, 0 );
    $valueSerialized = $this->maybeSerialize( $value, $retIsSerialized );
    $success = $this->_set( $key, $valueSerialized, $retIsSerialized );

    $bucketLock->releaseWrite();
    $this->locks::$everything->releaseRead();

    if ( $success )
      return $value;

    return false;
  }

  function decrement( $key, $offset = 1, $initialValue = 0 ) {

    $offset = (int) $offset;
    $initialValue = (int) $initialValue;

    return $this->increment( $key, -$offset, $initialValue );
  }

  function delete( $key ) {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    $key = $this->sanitizeKey( $key );

    if ( !$this->locks::$everything->lockForRead() )
      return false;

    $bucketLock = $this->locks->getBucketLock( ShmCache\Memory::getBucketIndex( $key ) );
    if ( !$bucketLock->lockForWrite() ) {
      $this->locks::$everything->releaseRead();
      return false;
    }

    $ret = false;
    $chunk = $this->memory->getChunkByKey( $key );

    if ( $chunk )
      $ret = $this->memory->removeChunk( $chunk );

    $bucketLock->releaseWrite();
    $this->locks::$everything->releaseRead();

    return $ret;
  }

  function flush() {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    if ( !$this->locks::$everything->lockForWrite() )
      return false;

    try {
      $this->memory->flush();
      $ret = true;
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
      $ret = false;
    }

    $this->locks::$everything->releaseWrite();

    return $ret;
  }

  /**
   * Deletes the shared memory block created by this class. This will only
   * work if the block was created by the same Unix user or group that is
   * currently running this PHP script.
   */
  function destroy() {

    if ( !$this->memory )
      throw new \Exception( 'Tried to use a destroyed cache. Please create a new instance of '. __CLASS__ .'.' );

    if ( !$this->locks::$everything->lockForWrite() )
      return false;

    try {
      $this->memory->destroy();
      $this->memory = null;
      $ret = true;
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
      $ret = false;
    }

    $this->locks::$everything->releaseWrite();

    return $ret;
  }

  function getStats() {
    return $this->stats->getStats();
  }

  private function maybeSerialize( $value, &$retIsSerialized ) {

    $retIsSerialized = false;

    if ( !is_string( $value ) ) {
      $value = serialize( $value );
      $retIsSerialized = true;
    }

    return $value;
  }

  private function _get( $key, &$retIsSerialized, &$retIsCacheHit ) {

    $ret = false;
    $retIsCacheHit = false;
    $retIsSerialized = false;
    $chunk = $this->memory->getChunkByKey( $key );

    if ( $chunk ) {

      $data = $this->memory->getChunkValue( $chunk, $retIsSerialized );

      if ( $data === false ) {
        trigger_error( 'Could not read value for item "'. rawurlencode( $key ) .'"' );
      }
      else {
        $retIsCacheHit = true;
        $ret = $data;
      }
    }

    return $ret;
  }

  private function _set( $key, $value, $valueIsSerialized, $mustNotExist = false, $mustExist = false ) {

    $valueSize = strlen( $value );
    $existingChunk = $this->memory->getChunkByKey( $key );

    if ( $existingChunk ) {

      if ( $mustNotExist )
        return false;

      if ( $this->memory->replaceChunkValue( $existingChunk, $value, $valueSize, $valueIsSerialized ) ) {
        return true;
      }
      else {
        // The new value is probably too large to fit into the existing chunk, and
        // would overwrite 1 or more chunks to the right of it. We'll instead
        // remove the existing chunk, and handle this as a new value.
        //
        // Note: whenever we cannot store the value to the cache, we remove any
        // existing item with the same key. This emulates Memcached:
        // https://github.com/memcached/memcached/wiki/Performance#how-it-handles-set-failures
        if ( !$this->memory->removeChunk( $existingChunk ) )
          return false;
      }
    }
    else {
      if ( $mustExist )
        return false;
    }

    if ( !$this->memory->addChunk( $key, $value, $valueSize, $valueIsSerialized ) )
      return false;

    return true;
  }

  private function sanitizeKey( $key ) {
    return substr( $key, 0, ShmCache\Memory::MAX_KEY_LENGTH );
  }
}

