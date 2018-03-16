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
  private $tag;

  /**
   * @param string $tag An arbitrary tag for the lock. Only locks with the same tag are synchronized.
   */
  function __construct( $tag ) {

    if ( !is_string( $tag ) )
      throw new \InvalidArgumentException( '$tag is not a string' );

    $this->tag = $tag;
    $this->initLockFiles();
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

    // Allow nested write locks
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

    if ( self::$hasReadLock ) {
      trigger_error( 'Tried to release a write lock while having a read lock' );
      return false;
    }

    // Allow nested write locks
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

    if ( self::$hasWriteLock ) {
      trigger_error( 'Tried to acquire read lock even though a write lock is already acquired' );
      return false;
    }

    // Allow nested read locks
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

    if ( self::$hasWriteLock ) {
      trigger_error( 'Tried to release a write lock while having a read lock' );
      return false;
    }

    // Allow nested read locks
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

  private function getLockFilePath() {
    return '/var/lock/php-shm-cache-lock-'. md5( $this->tag );
  }

  private function initLockFiles() {

    $lockFile = $this->getLockFilePath();

    if ( !file_exists( $lockFile ) ) {
      if ( !touch( $lockFile ) )
        throw new \Exception( 'Could not create '. $lockFile );
      if ( !chmod( $lockFile, 0777 ) )
        throw new \Exception( 'Could not change permissions of '. $lockFile );
    }

    // In PHP a process cannot sem_release() a semaphore created by another
    // process, which is required when implementing
    // a multiple-readers/single-writer lock. Therefore we use a lock file
    // instead. See the "global" lock at
    // https://en.wikipedia.org/wiki/Readers%E2%80%93writer_lock#Implementation
    $this->lockFile = fopen( $lockFile, 'r+' );
    if ( !$this->lockFile )
      throw new \Exception( 'Could not open '. $lockFile );
  }

  private function destroy() {

    if ( $this->lockFile )
      fclose( $this->lockFile );

    $lockFile = $this->getLockFilePath();
    unlink( $lockFile );
  }
}

