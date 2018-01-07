<?php

namespace Crusse\ShmCache;

/**
 * A multiple-readers/single-writer lock.
 *
 * See https://en.wikipedia.org/wiki/Readers%E2%80%93writer_lock#Implementation
 */
class Lock {

  static private $hasReadLock = false;
  static private $hasWriteLock = false;

  private $lockFile;
  private $readTryMutex;

  function __construct() {

    $this->initMutexes();
  }

  function __destruct() {

    if ( $this->lockFile )
      fclose( $this->lockFile );

    if ( $this->readTryMutex )
      @sem_release( $this->readTryMutex );
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

    if ( !sem_acquire( $this->readTryMutex ) ) {
      trigger_error( 'Could not acquire read-try mutex' );
      return false;
    }

    // This will block until there are no readers and writers with a lock
    $ret = flock( $this->lockFile, LOCK_EX );

    if ( $ret )
      self::$hasWriteLock = true;
    else
      trigger_error( 'Could not acquire exclusive lock' );

    return $ret;
  }

  function releaseWriteLock() {

    if ( !self::$hasWriteLock ) {
      trigger_error( 'Tried to release non-existent write lock' );
      return false;
    }

    $ret = flock( $this->lockFile, LOCK_UN );

    if ( $ret )
      self::$hasWriteLock = false;
    else
      trigger_error( 'Could not unlock resource lock file' );

    if ( !sem_release( $this->readTryMutex ) ) {
      trigger_error( 'Could not release read-try mutex' );
      $ret = false;
    }

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

    if ( !sem_acquire( $this->readTryMutex ) ) {
      trigger_error( 'Could not acquire read-try mutex' );
      return false;
    }

    $ret = flock( $this->lockFile, LOCK_SH );

    if ( !sem_release( $this->readTryMutex ) ) {
      trigger_error( 'Could not release read-try mutex' );
      $ret = false;
    }

    if ( $ret )
      self::$hasReadLock = true;
    else
      trigger_error( 'Could not acquire read lock' );

    return $ret;
  }

  function releaseReadLock() {

    if ( !self::$hasReadLock ) {
      trigger_error( 'Tried to release non-existent read lock' );
      return false;
    }

    $ret = flock( $this->lockFile, LOCK_UN );

    if ( $ret )
      self::$hasReadLock = false;
    else
      trigger_error( 'Could not unlock resource lock file' );

    return $ret;
  }

  private function initMutexes() {

    $resourceLockFile = '/var/lock/php-shm-cache-87b1dcf602a-resource-mutex';
    $readTryMutexFile = '/var/lock/php-shm-cache-87b1dcf602a-readtry-mutex';

    foreach ( [ $resourceLockFile, $readTryMutexFile ] as $tmpFile ) {
      if ( !file_exists( $tmpFile ) ) {
        if ( !touch( $tmpFile ) )
          throw new \Exception( 'Could not create '. $tmpFile );
        if ( !chmod( $tmpFile, 0777 ) )
          throw new \Exception( 'Could not change permissions of '. $tmpFile );
      }
    }

    // In PHP a process cannot sem_release() a semaphore created by another
    // process, which is required when implementing
    // a multiple-readers/single-writer lock. Therefore we use a lock file
    // instead. See the "global" lock at
    // https://en.wikipedia.org/wiki/Readers%E2%80%93writer_lock#Implementation
    $this->lockFile = fopen( $resourceLockFile, 'r+' );
    if ( !$this->lockFile )
      throw new \Exception( 'Could not open '. $resourceLockFile );

    $this->readTryMutex = sem_get( fileinode( $readTryMutexFile ), 1, 0777, 1 );
    if ( !$this->readTryMutex )
      throw new \Exception( 'Could not get a read-try mutex' );
  }
}

