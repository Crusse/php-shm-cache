<?php

// Compare flock() and sem_acquire()

$totalFlockRead = 0;
$totalFlockExclusive = 0;
$totalSem = 0;

$sem = sem_get( ftok( __FILE__, 'p' ), 1, 0777, 1 );
$tmpFile = '/tmp/php-shm-flock-test.lock';
if ( !touch( $tmpFile ) )
  throw new \Exception( 'Could not create '. $tmpFile );
$file = fopen( $tmpFile, 'r+' );

for ( $i = 0; $i < 100000; ++$i ) {

  $start = microtime( true );
  if ( !flock( $file, LOCK_SH ) )
    throw new \Exception( 'Could not get do a flock(LOCK_SH)' );
  if ( !flock( $file, LOCK_UN ) )
    throw new \Exception( 'Could not get do a flock(LOCK_UN)' );
  $totalFlockRead = microtime( true ) - $start;

  $start = microtime( true );
  if ( !sem_acquire( $sem ) )
    throw new \Exception( 'Could not get do a sem_acquire()' );
  if ( !sem_release( $sem ) )
    throw new \Exception( 'Could not get do a sem_release()' );
  $totalSem = microtime( true ) - $start;

  $start = microtime( true );
  if ( !flock( $file, LOCK_EX ) )
    throw new \Exception( 'Could not get do a flock(LOCK_EX)' );
  if ( !flock( $file, LOCK_UN ) )
    throw new \Exception( 'Could not get do a flock(LOCK_UN)' );
  $totalFlockExclusive = microtime( true ) - $start;
}

sem_remove( $sem );
fclose( $file );
unlink( $tmpFile );

echo 'Semaphore:       '. $totalSem . PHP_EOL;
echo 'flock read:      '. $totalFlockRead . PHP_EOL;
echo 'flock exclusive: '. $totalFlockExclusive . PHP_EOL;

