<?php

namespace Crusse\ShmCache;

/**
 * A multiple-readers/single-writer lock.
 *
 * See https://en.wikipedia.org/wiki/Readers%E2%80%93writer_lock#Implementation
 */
class Lock {

  private static $registeredTags = [];

  private $readLockCount = 0;
  private $writeLockCount = 0;
  private $tag;
  private $lockFile;

  /**
   * @param string $tag An arbitrary tag for the lock. Only locks with the same tag are synchronized.
   */
  function __construct( $tag ) {

    if ( !is_string( $tag ) )
      throw new \InvalidArgumentException( '$tag is not a string' );

    // $readLockCount and $writeLockCount are instance variables. If we let
    // multiple Lock instances use the same tag (and therefore the same lock),
    // one PHP process can try to acquire a lock that it already has, ending up
    // deadlocking itself.
    if ( isset( self::$registeredTags[ $tag ] ) ) {
      throw new \InvalidArgumentException( 'A '. __CLASS__ .' with the tag "'. $tag .'" has already been instantiated; you can only register the same tag once' );
    }

    self::$registeredTags[ $tag ] = true;
    $this->tag = $tag;

    $this->initLockFiles();
  }

  function __destruct() {

    for ( $i = 0; $i < $this->readLockCount; $i++ )
      $this->releaseRead();

    for ( $i = 0; $i < $this->writeLockCount; $i++ )
      $this->releaseWrite();

    if ( $this->lockFile )
      fclose( $this->lockFile );
  }

  function lockForWrite( $try = false ) {

    if ( $this->readLockCount ) {
      trigger_error( 'Tried to acquire "'. $this->tag .'" write lock even though a read lock is already acquired' );
      return false;
    }

    // Allow nested write locks
    if ( $this->writeLockCount ) {
      ++$this->writeLockCount;
      return true;
    }

    // This will block until there are no readers and writers with a lock
    $ret = flock( $this->lockFile, LOCK_EX|( $try ? LOCK_NB : 0 ) );

    if ( $ret )
      ++$this->writeLockCount;
    else if ( !$try )
      trigger_error( 'Could not acquire exclusive "'. $this->tag .'" lock' );

    return $ret;
  }

  function releaseWrite() {

    if ( $this->readLockCount ) {
      trigger_error( 'Tried to release a "'. $this->tag .'" write lock while having a read lock' );
      return false;
    }

    // Allow nested write locks
    if ( $this->writeLockCount > 1 ) {
      --$this->writeLockCount;
      return true;
    }
    else if ( $this->writeLockCount <= 0 ) {
      trigger_error( 'Tried to release non-existent "'. $this->tag .'" write lock' );
      return false;
    }

    $ret = flock( $this->lockFile, LOCK_UN );

    if ( $ret )
      --$this->writeLockCount;
    else
      trigger_error( 'Could not unlock "'. $this->tag .'" resource lock file' );

    return $ret;
  }

  function lockForRead( $try = false ) {

    if ( $this->writeLockCount ) {
      trigger_error( 'Tried to acquire "'. $this->tag .'" read lock even though a write lock is already acquired' );
      return false;
    }

    // Allow nested read locks
    if ( $this->readLockCount ) {
      ++$this->readLockCount;
      return true;
    }

    $ret = flock( $this->lockFile, LOCK_SH|( $try ? LOCK_NB : 0 ) );

    if ( $ret )
      ++$this->readLockCount;
    else if ( !$try )
      trigger_error( 'Could not acquire "'. $this->tag .'" read lock' );

    return $ret;
  }

  function releaseRead() {

    if ( $this->writeLockCount ) {
      trigger_error( 'Tried to release "'. $this->tag .'" write lock while having a read lock' );
      return false;
    }

    // Allow nested read locks
    if ( $this->readLockCount > 1 ) {
      --$this->readLockCount;
      return true;
    }
    else if ( $this->readLockCount <= 0 ) {
      trigger_error( 'Tried to release non-existent "'. $this->tag .'" read lock' );
      return false;
    }

    $ret = flock( $this->lockFile, LOCK_UN );

    if ( $ret )
      --$this->readLockCount;
    else
      trigger_error( 'Could not unlock "'. $this->tag .'" resource lock file' );

    // TODO: do performance requirements allow us to fclose() here? Wouldn't
    // hit max open files limits so easily...

    return $ret;
  }

  function isLocked() {
    return (bool) ( $this->readLockCount + $this->writeLockCount );
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

