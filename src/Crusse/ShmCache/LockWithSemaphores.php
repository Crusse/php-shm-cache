<?php

namespace Crusse\ShmCache;

/**
 * A multiple-readers/single-writer lock.
 *
 * See https://en.wikipedia.org/wiki/Readers%E2%80%93writer_lock#Implementation
 */
class LockWithSemaphores {

  const WRITE_LOCK_TIMEOUT = 2;

  static private $hasReadLock = false;
  static private $hasWriteLock = false;

  private $resourceMutex;
  private $metadataMutex;
  private $LONG_SIZE;
  /**
   * This shared memory segment contains this data:
   *
   * [readercount][lastreadlocktimestamp]
   */
  private $shm;

  function __construct() {

    $this->LONG_SIZE = strlen( pack( 'l', 1 ) );

    $this->initMutexes();
    $this->initMetadataMemory();
  }

  function __destruct() {

    if ( $this->shm )
      shmop_close( $this->shm );
  }

  function getWriteLock() {

    if ( self::$hasReadLock ) {
      trigger_error( 'Tried to acquire write lock even though a read lock is already acquired' );
      return false;
    }

    if ( self::$hasWriteLock ) {
      trigger_error( 'Tried to acquire write lock even though it is already acquired' );
      return false;
    }

    // Will block until the mutex is free
    $ret = sem_acquire( $this->resourceMutex );

    if ( $ret )
      self::$hasWriteLock = true;
    else
      trigger_error( 'Could not acquire resource mutex for writing' );

    return $ret;
  }

  function releaseWriteLock() {

    if ( !self::$hasWriteLock ) {
      trigger_error( 'Tried to release non-existent write lock' );
      return false;
    }

    $ret = sem_release( $this->resourceMutex );

    if ( $ret )
      self::$hasWriteLock = false;
    else
      trigger_error( 'Could not release resource mutex in write lock' );

    return $ret;
  }

  function getReadLock() {

    if ( self::$hasWriteLock ) {
      trigger_error( 'Tried to acquire read lock even though a write lock is already acquired' );
      return false;
    }

    if ( self::$hasReadLock ) {
      trigger_error( 'Tried to acquire read lock even though it is already acquired' );
      return false;
    }

    // Will block until the metadata mutex is free
    if ( !sem_acquire( $this->metadataMutex ) ) {
      trigger_error( 'Could not get a metadata lock' );
      return false;
    }

    $metadata = $this->getMetadata();
    $readerCount = $metadata[ 'readercount' ] + 1;


    if ( sem_acquire( $this->writeSemaphore ) ) {

      $ret = sem_acquire( $this->readSemaphore );
      sem_release( $this->writeSemaphore );

      if ( $ret )
        self::$hasReadLock = true;
      else
        trigger_error( 'Could not acquire read semaphore' );
    }


    return $ret;
  }

  private function initMutexes() {

    $resMutexFile = '/var/lock/php-shm-cache-87b1dcf602a-resource-mutex';
    $metaMutexFile = '/var/lock/php-shm-cache-87b1dcf602a-metadata-mutex';

    foreach ( [ $resMutexFile, $metaMutexFile ] as $tmpFile ) {
      if ( !file_exists( $tmpFile ) ) {
        if ( !touch( $tmpFile ) )
          throw new \Exception( 'Could not create '. $tmpFile );
        if ( !chmod( $tmpFile, 0777 ) )
          throw new \Exception( 'Could not change permissions of '. $tmpFile );
      }
    }

    $this->resourceMutex = sem_get( fileinode( $resMutexFile ), 1, 0777, 1 );
    if ( !$this->resourceMutex )
      throw new \Exception( 'Could not get a resource mutex' );

    $this->metadataMutex = sem_get( fileinode( $metaMutexFile ), 1, 0777, 1 );
    if ( !$this->metadataMutex )
      throw new \Exception( 'Could not get a metadata mutex' );
  }

  private function initMetadataMemory() {

    $tmpFile = '/var/lock/php-shm-cache-87b1dcf602a-metadata-shm';

    if ( !file_exists( $tmpFile ) ) {
      if ( !touch( $tmpFile ) )
        throw new \Exception( 'Could not create '. $tmpFile );
      if ( !chmod( $tmpFile, 0777 ) )
        throw new \Exception( 'Could not change permissions of '. $tmpFile );
    }

    // Reserve a larger memory segment than we need currently, so that we don't
    // have to resize it later if we modify the code to store more data in that
    // segment.
    //
    // The memory is initialized to zeroes. From Linux's shmget() man pages:
    // "When a new shared memory segment is created, its contents are
    // initialized to zero values"
    //
    $this->shm = shmop_open( fileinode( $tmpFile ), "c", 0777, 1024 );

    if ( !$this->shm )
      throw new \Exception( 'Could not get or create a shared memory segment' );
  }

  private function getMetadata() {

    $data = shmop_read( $this->shm, 0, $this->LONG_SIZE + $this->LONG_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not get the reader count' );
      return null;
    }

    return unpack( 'lreadercount/lreadercountlastupdated', $data )[ 1 ];
  }

  private function setMetadata( $readerCount ) {

    if ( $readerCount < 0 ) {
      trigger_error( 'Reader count must be 0 or larger' );
      return false;
    }

    if ( shmop_write( $this->shm, pack( 'l', $readerCount ), pack( 'l', time() ), 0 ) === false ) {
      trigger_error( 'Could not set the reader count' );
      return false;
    }

    return true;
  }
}

