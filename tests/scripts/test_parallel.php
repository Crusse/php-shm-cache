<?php
/**
 * This file is intended to be called by multiple processes simultaneously, so
 * that we can test how slow ShmCache's locking is.
 */

require_once __DIR__ .'/../../vendor/autoload.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$cache = new Crusse\ShmCache();
$itemsToCreate = 50000;

echo 'Creating random object... ';

$randObj = new stdClass;
for ( $i = 0; $i < 20; ++$i ) {
  $key = '';
  for ( $j = 0; $j < 10; ++$j )
    $key .= chr( rand( ord( 'a' ), ord( 'z' ) ) );
  $randObj->$key = [ str_repeat( $key, 50 ) ];
}

echo 'done'. PHP_EOL;
echo 'Setting and getting '. $itemsToCreate .' cache items... ';
$start = microtime( true );

for ( $i = 0; $i < $itemsToCreate; ++$i ) {

  $value = ( $i % 2 )
    ? str_repeat( 'x', rand( 1, 1000 ) )
    : $randObj;

  if ( !$cache->set( 'foobar'. $i, $value ) )
    throw new \Exception( 'ERROR: Failed setting ShmCache value foobar'. $i );

  // Might not be found anymore, if some other process caused this item to be
  // flushed, which is why we don't check for the return value
  $cache->get( 'foobar'. $i, $value );
}

echo 'done after '. ( microtime( true ) - $start ) .' seconds'. PHP_EOL;
