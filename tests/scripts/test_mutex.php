<?php

require_once __DIR__ .'/../../vendor/autoload.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$cache = new Crusse\ShmCache();

try {
  for ( $j = 0; $j < 100; $j++ ) {
    $server = new Crusse\JobServer\Server( 4 );
    $server->addWorkerInclude( __DIR__ .'/../functions.php' );
    $server->setWorkerTimeout( 2 );
    for ( $i = 0; $i < 40; $i++ ) {
      $server->addJob( 'mutex_test_func', 'Job '. $i );
    }
    $res = $server->getOrderedResults();
    $lastSetVal = $cache->get( 'parallel_test_val' );
    if ( $lastSetVal )
      throw new \Exception( 'The cached value has not been deleted. There was a race condition between a set() and a delete().' );
  }
  echo 'Success'. PHP_EOL;
}
catch ( \Exception $e ) {
  echo 'ERROR: '. $e->getMessage() . PHP_EOL;
}

