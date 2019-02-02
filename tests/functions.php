<?php

require_once __DIR__ .'/../vendor/autoload.php';

function mutex_test_func( $arg ) {

  $cache = new \Crusse\ShmCache;
  $semaphore = sem_get( fileinode( __FILE__ ), 1, 0666, 1 );

  try {

    if ( !sem_acquire( $semaphore ) )
      throw new \Exception( 'Could not acquire semaphore lock' );

    if ( !$cache->set( 'parallel_test_val', $arg ) )
      throw new \Exception( 'Could not set value to '. $arg );

    $val = $cache->get( 'parallel_test_val' );
    if ( $val !== $arg )
      throw new \Exception( 'Value is not "'. $arg .'"' );

    if ( !$cache->delete( 'parallel_test_val' ) )
      throw new \Exception( 'Could not delete value' );
  }
  catch ( \Exception $e ) {
    sem_release( $semaphore );
    throw $e;
  }

  return $arg;
}

function set_random_cache_values( $key ) {

  $cache = new \Crusse\ShmCache;
  $value = str_repeat( 'x', rand( 1, 1024 * 768 ) );
  $cache->set( $key, $value );

  return $value;
}

function add_and_set_random_cache_values( $key ) {

  $cache = new \Crusse\ShmCache;
  $value = str_repeat( 'x', rand( 1, 1024 * 768 ) );
  $cache->add( $key, $value );
  $cache->set( $key, $value );

  return $value;
}

