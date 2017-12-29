<?php

require_once __DIR__ .'/../../vendor/autoload.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$cache = new Crusse\ShmCache();
$memcached = new Memcached();
$memcached->addServer( 'localhost', 11211 );

if ( ( @$argc > 1 && $argv[ 1 ] === 'clear' ) || isset( $_REQUEST[ 'clear' ] ) ) {
  if ( $memcached->flush() && $cache->flush() )
    echo 'Deleted all'. PHP_EOL;
  else
    echo 'ERROR: Failed to delete all'. PHP_EOL;
}

if ( ( @$argc > 1 && $argv[ 1 ] === 'destroy' ) || isset( $_REQUEST[ 'destroy' ] ) ) {
  if ( $memcached->flush() && $cache->destroy() ) {
    $cache = new Crusse\ShmCache();
    echo 'Destroyed memory block'. PHP_EOL;
  }
  else {
    echo 'ERROR: Failed to destroy memory block'. PHP_EOL;
  }
}

$itemsToCreate = 2000;
$totalSetTimeShm = 0;
$totalSetTimeMemcached = 0;

$randObj = new stdClass;
for ( $i = 0; $i < 200; ++$i ) {
  $key = '';
  for ( $j = 0; $j < 10; ++$j )
    $key .= chr( rand( ord( 'a' ), ord( 'z' ) ) );
  $randObj->$key = [ str_repeat( $key, 100 ) ];
}

for ( $i = 0; $i < $itemsToCreate; ++$i ) {

  $value = ( $i % 2 )
    ? str_repeat( 'x', rand( 1, Crusse\ShmCache::MAX_VALUE_SIZE - 20 ) )
    : $randObj;

  $start = microtime( true );
  if ( !$cache->set( 'foobar'. $i, $value ) ) {
    throw new \Exception( 'ERROR: Failed setting ShmCache value foobar'. $i );
  }
  $end = ( microtime( true ) - $start );
  echo $i .' ShmCache set took '. $end .' s'. PHP_EOL;
  $totalSetTimeShm += $end;

  $start2 = microtime( true );
  if ( !$memcached->set( 'foobar'. $i, $value ) ) {
    throw new \Exception( 'ERROR: Failed setting Memcached value foobar'. $i );
  }
  $end2 = ( microtime( true ) - $start2 );
  echo $i .' Memcached set took '. $end2 .' s'. PHP_EOL;
  $totalSetTimeMemcached += $end2;
}

/*
// Set a few items again with different sizes to test replacing
for ( $i = $itemsToCreate - 50; $i < $itemsToCreate; ++$i ) {
  $valuePre = '123 ';
  $valuePost = str_repeat( 'x', 1000000 );
  if ( !$cache->set( 'foobar'. $i, $valuePre .' '. $valuePost ) ) {
    throw new \Exception( 'ERROR: Failed setting ShmCache value foobar'. $i );
  }
}
*/

$totalGetTimeShm = 0;
$totalGetTimeMemcached = 0;

for ( $i = $itemsToCreate - 100; $i < $itemsToCreate; ++$i ) {

  $start = microtime( true );
  if ( !( $val = $cache->get( 'foobar'. $i ) ) ) {
    echo 'ERROR: Failed getting ShmCache value foobar'. $i . PHP_EOL;
    break;
  }
  $end = ( microtime( true ) - $start );
  echo $i .' ShmCache get took '. $end .' s ('. gettype( $val ) .')'. PHP_EOL;
  $totalGetTimeShm += $end;

  $start2 = microtime( true );
  if ( !( $val = $memcached->get( 'foobar'. $i ) ) ) {
    echo 'ERROR: Failed getting Memcached value foobar'. $i . PHP_EOL;
    break;
  }
  $end2 = ( microtime( true ) - $start2 );
  echo $i .' Memcached get took '. $end2 .' s'. PHP_EOL;
  $totalGetTimeMemcached += $end2;
}

if ( !$cache->set( 'foobar'. ( $itemsToCreate - 1 ), 'foo' ) )
  echo 'Failed setting value'. PHP_EOL;

echo PHP_EOL;
$cache->delete( "foo" );
echo 'increment("foo", 5, 3) == '. var_export( $cache->increment( 'foo', 5, 3 ), true ) . PHP_EOL;
$cache->delete( "foo" );
echo 'decrement("foo", 2, 3) == '. var_export( $cache->decrement( 'foo', 2, 3 ), true ) . PHP_EOL;
$cache->delete( "foo" );
echo 'decrement("foo", 5, 3) == '. var_export( $cache->decrement( 'foo', 5, 3 ), true ) . PHP_EOL;

echo PHP_EOL;
echo '----------------------------------------------'. PHP_EOL;
echo 'Stats:'. PHP_EOL;
print_r( $cache->getStats() );

echo '----------------------------------------------'. PHP_EOL;
echo 'Total set:'. PHP_EOL;
echo 'ShmCache:  '. $totalSetTimeShm .' s'. PHP_EOL;
echo 'Memcached: '. $totalSetTimeMemcached .' s'. PHP_EOL . PHP_EOL;

echo 'Total get:'. PHP_EOL;
echo 'ShmCache:  '. $totalGetTimeShm .' s'. PHP_EOL;
echo 'Memcached: '. $totalGetTimeMemcached .' s'. PHP_EOL;
echo '----------------------------------------------'. PHP_EOL . PHP_EOL;

