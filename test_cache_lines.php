<?php

// How much do cache lines and other CPU optimizations affect shared memory
// access from PHP?

$memSize = 1024 * 1024 * 128;
$elemSize = 64; // Common cache lines are 64 bytes long
$block1 = shmop_open( ftok( __FILE__, 'a' ), "c", 0666, $memSize );
$block2 = shmop_open( ftok( __DIR__ .'/cache.php', 'a' ), "c", 0666, $memSize );
$block3 = shmop_open( ftok( __DIR__ .'/README.md', 'a' ), "c", 0666, $memSize );

for ( $i = 0; $i < floor( $memSize / $elemSize ); ++$i ) {
  foreach ( [ $block1, $block2, $block3 ] as $block ) {
    if ( !shmop_write( $block, pack( "x12l", $i ), $i * $elemSize ) )
      die( 'Error' );
  }
}

$time = 0;
for ( $i = 0; $i < $memSize; $i += $elemSize ) {
  $start = microtime( true );
  (int) ( shmop_read( $block1, $i, $elemSize ) );
  $time += ( microtime( true ) - $start );
}
echo 'Forwards: '. $time .' s'. PHP_EOL;
shmop_delete( $block1 );

$randOffsets = [];
for ( $i = 0; $i < floor( $memSize / $elemSize ); ++$i )
  $randOffsets[] = rand( 0, floor( $memSize / $elemSize ) - 1 ) * $elemSize;
$time = 0;
foreach ( $randOffsets as $i ) {
  $start = microtime( true );
  (int) ( shmop_read( $block3, $i, $elemSize ) );
  $time += ( microtime( true ) - $start );
}
echo 'Random: '. $time .' s'. PHP_EOL;
shmop_delete( $block3 );

$time = 0;
for ( $i = $memSize - $elemSize; $i >= 0; $i -= $elemSize ) {
  $start = microtime( true );
  (int) ( shmop_read( $block2, $i, $elemSize ) );
  $time += ( microtime( true ) - $start );
}
echo 'Backwards: '. $time .' s'. PHP_EOL;
shmop_delete( $block2 );


