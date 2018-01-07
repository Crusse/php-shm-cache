<?php

namespace Crusse\ShmCache;

/**
 * A multiple-readers/single-writer lock.
 *
 * See https://en.wikipedia.org/wiki/Readers%E2%80%93writer_lock#Implementation
 */
class Lock {

  static private $hasReadLock = 0;
  static private $hasWriteLock = 0;

  private $lockFile;

  function __construct() {

    $this->initLockFile();
  }

  function __destruct() {

    if ( $this->lockFile )
      fclose( $this->lockFile );
  }

  function getWriteLock() {

    if ( self::$hasReadLock ) {
      trigger_error( 'Tried to acquire write lock even though a read lock is already acquired' );
      return false;
    }

    if ( self::$hasWriteLock ) {
      ++self::$hasWriteLock;
      return true;
    }

    // This will block until there are no readers and writers with a lock
    $ret = flock( $this->lockFile, LOCK_EX );

    if ( $ret )
      ++self::$hasWriteLock;
    else
      trigger_error( 'Could not acquire exclusive lock' );

    return $ret;
  }

  function releaseWriteLock() {

    if ( self::$hasWriteLock > 1 ) {
      --self::$hasWriteLock;
      return true;
    }
    else if ( self::$hasWriteLock <= 0 ) {
      trigger_error( 'Tried to release non-existent write lock' );
      return false;
    }

    $ret = flock( $this->lockFile, LOCK_UN );

    if ( $ret )
      --self::$hasWriteLock;
    else
      trigger_error( 'Could not unlock resource lock file' );

    return $ret;
  }

  function getReadLock() {

    // Already have an exclusive write lock, so we'll allow this silently
    if ( self::$hasWriteLock )
      return true;

    if ( self::$hasReadLock ) {
      ++self::$hasReadLock;
      return true;
    }

    $ret = flock( $this->lockFile, LOCK_SH );

    if ( $ret )
      ++self::$hasReadLock;
    else
      trigger_error( 'Could not acquire read lock' );

    return $ret;
  }

  function releaseReadLock() {

    // Already have an exclusive write lock, so we'll allow this silently
    if ( self::$hasWriteLock )
      return true;

    if ( self::$hasReadLock > 1 ) {
      --self::$hasReadLock;
      return true;
    }
    else if ( self::$hasReadLock <= 0 ) {
      trigger_error( 'Tried to release non-existent read lock' );
      return false;
    }

    $ret = flock( $this->lockFile, LOCK_UN );

    if ( $ret )
      --self::$hasReadLock;
    else
      trigger_error( 'Could not unlock resource lock file' );

    return $ret;
  }

  private function initLockFile() {

    $tmpFile = '/var/lock/php-shm-cache-87b1dcf602a-resource-mutex';

    if ( !file_exists( $tmpFile ) ) {
      if ( !touch( $tmpFile ) )
        throw new \Exception( 'Could not create '. $tmpFile );
      if ( !chmod( $tmpFile, 0777 ) )
        throw new \Exception( 'Could not change permissions of '. $tmpFile );
    }

    // In PHP a process cannot sem_release() a semaphore created by another
    // process, which is required when implementing
    // a multiple-readers/single-writer lock. Therefore we use a lock file
    // instead. See the "global" lock at
    // https://en.wikipedia.org/wiki/Readers%E2%80%93writer_lock#Implementation
    $this->lockFile = fopen( $tmpFile, 'r+' );

    if ( !$this->lockFile )
      throw new \Exception( 'Could not open '. $tmpFile );
  }
}

