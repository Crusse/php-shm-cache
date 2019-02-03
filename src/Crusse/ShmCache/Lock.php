<?php

namespace Crusse\ShmCache;

/**
 * A multiple-readers/single-writer lock.
 *
 * See https://en.wikipedia.org/wiki/Readers%E2%80%93writer_lock#Implementation
 */
class Lock {

  private static $registeredTags = [];

  // If true, illegal lock operations will throw exceptions. See LOCKING.md for
  // the locking rules. This should only be used in development as it's slow.
  private static $validateLockRules = false;
  // This PHP process's lock count per lock tag. Used only if validateLockRules
  // is true.
  private static $lockCountPerTag = null;

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

    if ( getenv( 'SHMCACHE_VALIDATE_LOCK_RULES' ) || defined( 'SHMCACHE_VALIDATE_LOCK_RULES' ) ) {
      self::$validateLockRules = true;

      if ( self::$lockCountPerTag === null ) {
        self::$lockCountPerTag = [
          'everything' => 0,
          'bucket' => 0,
          'oldestzoneindex' => 0,
          'zone' => 0,
          'stats' => 0,
        ];
      }
    }

    $this->initLockFiles();
  }

  function __destruct() {

    if ( $this->lockFile )
      fclose( $this->lockFile );
  }

  function lockForWrite( $try = false ) {

    if ( self::$validateLockRules ) {
      if ( $this->readLockCount )
        throw new \Exception( 'Tried to acquire "'. $this->tag .'" write lock even though a read lock is already acquired' );
      $this->validateLockOperation( $try );
    }

    // Allow nested write locks
    if ( $this->writeLockCount ) {
      ++$this->writeLockCount;
      return true;
    }

    // This will block until there are no readers and writers with a lock,
    // unless $try is true, in which case returns immediately
    $ret = flock( $this->lockFile, LOCK_EX|( $try ? LOCK_NB : 0 ) );

    if ( $ret )
      ++$this->writeLockCount;
    else if ( !$try )
      trigger_error( 'Could not acquire exclusive "'. $this->tag .'" lock' );

    return $ret;
  }

  function releaseWrite() {

    if ( self::$validateLockRules ) {
      if ( $this->readLockCount )
        throw new \Exception( 'Tried to release a "'. $this->tag .'" write lock while having a read lock' );
      $this->validateReleaseOperation();
    }

    // Allow nested write locks
    if ( $this->writeLockCount > 1 ) {
      --$this->writeLockCount;
      return true;
    }
    else if ( self::$validateLockRules && $this->writeLockCount <= 0 ) {
      throw new \Exception( 'Tried to release non-existent "'. $this->tag .'" write lock' );
    }

    $ret = flock( $this->lockFile, LOCK_UN );

    if ( $ret )
      --$this->writeLockCount;
    else
      trigger_error( 'Could not unlock "'. $this->tag .'" resource lock file' );

    return $ret;
  }

  function lockForRead( $try = false ) {

    if ( self::$validateLockRules ) {
      if ( $this->writeLockCount )
        throw new \Exception( 'Tried to acquire "'. $this->tag .'" read lock even though a write lock is already acquired' );
      $this->validateLockOperation( $try );
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

    if ( self::$validateLockRules ) {
      if ( $this->writeLockCount )
        throw new \Exception( 'Tried to release a "'. $this->tag .'" read lock while having a write lock' );
      $this->validateReleaseOperation();
    }

    // Allow nested read locks
    if ( $this->readLockCount > 1 ) {
      --$this->readLockCount;
      return true;
    }
    else if ( self::$validateLockRules && $this->readLockCount <= 0 ) {
      throw new \Exception( 'Tried to release non-existent "'. $this->tag .'" read lock' );
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

  private function validateLockOperation( $tryLock ) {

    // Remove the individual zone's or bucket's index from the tag, so that we
    // can track how many zone or bucket locks we have
    $tag = preg_replace( '#^(bucket|zone)\d+$#', '$1', $this->tag );

    if ( $tag === 'everything' ) {

      // Must hold no locks on anything else, not even 'everything'
      if ( self::$lockCountPerTag[ 'everything' ] !== 0 ||
        self::$lockCountPerTag[ 'bucket' ] !== 0 ||
        self::$lockCountPerTag[ 'oldestzoneindex' ] !== 0 ||
        self::$lockCountPerTag[ 'zone' ] !== 0 )
      {
        $this->throwLockingRuleException( $tryLock );
      }
    }
    else if ( $tag === 'bucket' ) {

      // Rule 1
      if ( self::$lockCountPerTag[ 'everything' ] === 0 )
        $this->throwLockingRuleException( $tryLock );

      // Rule 6
      if ( self::$lockCountPerTag[ 'bucket' ] !== 0 && !$tryLock )
        $this->throwLockingRuleException( $tryLock );

      // Rules 4 and 7
      if ( ( self::$lockCountPerTag[ 'oldestzoneindex' ] !== 0 ||
        self::$lockCountPerTag[ 'zone' ] !== 0 ) &&
        !$tryLock )
      {
        $this->throwLockingRuleException( $tryLock );
      }
    }
    else if ( $tag === 'oldestzoneindex' ) {

      if ( self::$lockCountPerTag[ 'oldestzoneindex' ] !== 0 )
        $this->throwLockingRuleException( $tryLock );

      // Rule 1
      if ( self::$lockCountPerTag[ 'everything' ] === 0 )
        $this->throwLockingRuleException( $tryLock );

      // Rules 4 and 7
      if ( self::$lockCountPerTag[ 'zone' ] !== 0 )
        $this->throwLockingRuleException( $tryLock );
    }
    else if ( $tag === 'zone' ) {

      // Rule 1
      if ( self::$lockCountPerTag[ 'everything' ] === 0 )
        $this->throwLockingRuleException( $tryLock );

      // Rule 5
      if ( self::$lockCountPerTag[ 'zone' ] !== 0 )
        $this->throwLockingRuleException( $tryLock );
    }

    self::$lockCountPerTag[ $tag ]++;
  }

  private function validateReleaseOperation() {

    // Remove the individual zone's or bucket's index from the tag, so that we
    // can track how many zone or bucket locks we have
    $tag = preg_replace( '#^(bucket|zone)\d+$#', '', $this->tag );

    self::$lockCountPerTag[ $tag ]--;

    if ( self::$lockCountPerTag < 0 )
      throw new \Exception( 'Lock count for tag "'. $tag .'" is less than 0' );
  }

  private function throwLockingRuleException( $tryLock ) {

    throw new \Exception( 'Tried to lock "'. $this->tag .'" but this violates the locking rules. Try-lock: '. var_export( $tryLock, true ) .'. Locks already held: '. var_export( self::$lockCountPerTag, true ) );
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

    $path = $this->getLockFilePath();
    if ( file_exists( $path ) )
      unlink( $path );
  }
}

