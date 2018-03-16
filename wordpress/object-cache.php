<?php

/*
Plugin Name: Shared memory object cache
Description: ShmCache backend for the WP Object Cache.
Version: 1.0.0
Plugin URI: https://github.com/Crusse/php-shm-cache
Author: Crusse

Install this file to wp-content/object-cache.php
*/

if ( is_file( __DIR__ .'/../vendor/autoload.php' ) )
  require_once __DIR__ .'/../vendor/autoload.php' ;

if ( is_file( __DIR__ .'/vendor/autoload.php' ) )
  require_once __DIR__ .'/vendor/autoload.php' ;

// Each WP installation needs a unique cache key prefix. We assume each WP
// installation has its own object-cache.php.
if ( ! defined( 'WP_CACHE_KEY_SALT' ) ) {
  define( 'WP_CACHE_KEY_SALT', substr( md5( __FILE__ ), 8, 16 ) );
}

if ( ! defined( 'OBJECT_CACHE_SHM_SIZE' ) ) {
  define( 'OBJECT_CACHE_SHM_SIZE', 1024 * 1024 * 512 );
}

if ( ! defined( 'OBJECT_CACHE_MEMCACHED_FLUSH_BUCKETS' ) ) {
  define( 'OBJECT_CACHE_MEMCACHED_FLUSH_BUCKETS', 1024 * 10 );
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
  global $wp_object_cache;

  return $wp_object_cache->add( $key, $data, $group, $expire );
}

function wp_cache_incr( $key, $n = 1, $group = '' ) {
  global $wp_object_cache;

  return $wp_object_cache->incr( $key, $n, $group );
}

function wp_cache_decr( $key, $n = 1, $group = '' ) {
  global $wp_object_cache;

  return $wp_object_cache->decr( $key, $n, $group );
}

function wp_cache_close() {
  global $wp_object_cache;

  return $wp_object_cache->close();
}

function wp_cache_delete( $key, $group = '' ) {
  global $wp_object_cache;

  return $wp_object_cache->delete( $key, $group );
}

function wp_cache_flush() {
  global $wp_object_cache;

  return $wp_object_cache->flush();
}

function wp_cache_get( $key, $group = '', $force = false ) {
  global $wp_object_cache;

  return $wp_object_cache->get( $key, $group, $force );
}

/**
 * Retrieve multiple cache entries
 *
 * @param array $groups Array of arrays, of groups and keys to retrieve
 * @return mixed
 */
function wp_cache_get_multi( $groups ) {
  global $wp_object_cache;

  return $wp_object_cache->get_multi( $groups );
}

function wp_cache_init() {
  global $wp_object_cache;

  $wp_object_cache = new WP_Object_Cache();
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
  global $wp_object_cache;

  return $wp_object_cache->replace( $key, $data, $group, $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
  global $wp_object_cache;

  if ( defined( 'WP_INSTALLING' ) == false ) {
    return $wp_object_cache->set( $key, $data, $group, $expire );
  } else {
    return $wp_object_cache->delete( $key, $group );
  }
}

function wp_cache_switch_to_blog( $blog_id ) {
  global $wp_object_cache;

  return $wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_add_global_groups( $groups ) {
  global $wp_object_cache;

  $wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
  global $wp_object_cache;

  $wp_object_cache->add_non_persistent_groups( $groups );
}

class WP_Object_Cache {

  var $global_groups = array( 'WP_Object_Cache_global' => 1 );

  var $no_shmcache_groups = array();

  var $cache     = array();
  var $mc        = null;
  var $mc_flush_buckets = [];
  var $shm_cache = null;
  var $stats     = array();
  var $group_ops = array();

  var $flush_number        = array();
  var $global_flush_number = null;

  var $cache_enabled      = true;
  var $default_expiration = 0;
  var $max_expiration     = 2592000; // 30 days

  var $stats_callback = null;

  var $connection_errors = array();

  function add( $id, $data, $group = 'default', $expire = 0 ) {
    $key = $this->key( $id, $group );

    if ( is_object( $data ) ) {
      $data = clone $data;
    }

    if ( isset( $this->no_shmcache_groups[ $group ] ) ) {
      $this->cache[ $key ] = $data;

      return true;
    } elseif ( isset( $this->cache[ $key ] ) && false !== $this->cache[ $key ] ) {
      return false;
    }

    $expire = intval( $expire );
    if ( 0 === $expire || $expire > $this->max_expiration ) {
      $expire = $this->default_expiration;
    }

    $result = $this->shm_cache->add( $key, $data, false, $expire );

    if ( false !== $result ) {
      //++$this->stats['add'];

      //$this->group_ops[ $group ][] = "add $id";
      $this->cache[ $key ]         = $data;
    } else if ( false === $result && true === isset( $this->cache[$key] ) && false === $this->cache[$key] ) {
      /*
       * Here we unset local cache if remote add failed and local cache value is equal to `false` in order
       * to update the local cache anytime we get a new information from remote server. This way, the next
       * cache get will go to remote server and will fetch recent data.
       */
      unset( $this->cache[$key] );
    }

    return $result;
  }

  function add_global_groups( $groups ) {
    if ( ! is_array( $groups ) ) {
      $groups = (array) $groups;
    }

    $groups = array_flip( $groups );

    $this->global_groups = $this->global_groups + $groups;
  }

  function add_non_persistent_groups( $groups ) {
    if ( ! is_array( $groups ) ) {
      $groups = (array) $groups;
    }

    $groups = array_flip( $groups );

    $this->no_shmcache_groups = $this->no_shmcache_groups + $groups;
  }

  function incr( $id, $n = 1, $group = 'default' ) {
    $key = $this->key( $id, $group );

    $this->cache[ $key ] = $this->shm_cache->increment( $key, $n );

    return $this->cache[ $key ];
  }

  function decr( $id, $n = 1, $group = 'default' ) {
    $key = $this->key( $id, $group );

    $this->cache[ $key ] = $this->shm_cache->decrement( $key, $n );

    return $this->cache[ $key ];
  }

  function close() {
    $this->mc->quit();
  }

  function delete( $id, $group = 'default' ) {
    $key = $this->key( $id, $group );

    if ( isset( $this->no_shmcache_groups[ $group ] ) ) {
      unset( $this->cache[ $key ] );

      return true;
    }

    $result = $this->shm_cache->delete( $key );

    //++$this->stats['delete'];

    //$this->group_ops[ $group ][] = "delete $id";

    if ( false !== $result ) {
      unset( $this->cache[ $key ] );
    }

    return $result;
  }

  function flush() {
    // Do not use the memcached flush method. It acts on an
    // entire memcached server, affecting all sites.
    // Flush is also unusable in some setups, e.g. twemproxy.
    // Instead, rotate the key prefix for the current site.
    // Global keys are rotated when flushing on the main site.
    $this->cache = array();

    $this->rotate_site_keys();

    if ( is_main_site() ) {
      $this->rotate_global_keys();
    }
  }

  function rotate_site_keys() {
    $this->add( 'flush_number', intval( microtime( true ) * 1e6 ), 'WP_Object_Cache' );

    $this->flush_number[ $this->blog_prefix ] = $this->incr( 'flush_number', 1, 'WP_Object_Cache' );
  }

  function rotate_global_keys() {
    $this->add( 'flush_number', intval( microtime( true ) * 1e6 ), 'WP_Object_Cache_global' );

    $this->global_flush_number = $this->incr( 'flush_number', 1, 'WP_Object_Cache_global' );
  }

  function get( $id, $group = 'default', $force = false ) {
    $key = $this->key( $id, $group );

    if ( isset( $this->cache[ $key ] ) && ( ! $force || isset( $this->no_shmcache_groups[ $group ] ) ) ) {
      if ( is_object( $this->cache[ $key ] ) ) {
        $value = clone $this->cache[ $key ];
      } else {
        $value = $this->cache[ $key ];
      }
    } else if ( isset( $this->no_shmcache_groups[ $group ] ) ) {
      $this->cache[ $key ] = $value = false;
    } else {
      $value = $this->shm_cache->get( $key );

      $this->cache[ $key ] = $value;
    }

    //++$this->stats['get'];

    //$this->group_ops[ $group ][] = "get $id";

    return $value;
  }

  function get_multi( $groups ) {
    /*
    format: $get['group-name'] = array( 'key1', 'key2' );
    */
    $return = array();

    foreach ( $groups as $group => $ids ) {

      foreach ( $ids as $id ) {
        $key = $this->key( $id, $group );

        if ( isset( $this->cache[ $key ] ) ) {
          if ( is_object( $this->cache[ $key ] ) ) {
            $return[ $key ] = clone $this->cache[ $key ];
          } else {
            $return[ $key ] = $this->cache[ $key ];
          }

          continue;
        } else if ( isset( $this->no_shmcache_groups[ $group ] ) ) {
          $return[ $key ] = false;

          continue;
        } else {
          //error_log( __FUNCTION__ .': SHMCACHE get '. $key );
          $return[ $key ] = $this->shm_cache->get( $key );
        }
      }
    }

    //++$this->stats['get_multi'];

    //$this->group_ops[ $group ][] = "get_multi $id";

    $this->cache = array_merge( $this->cache, $return );

    return $return;
  }

  function flush_prefix( $group ) {
    if ( 'WP_Object_Cache' === $group || 'WP_Object_Cache_global' === $group ) {
      // Never flush the flush numbers.
      $number = '_';
    } elseif ( isset( $this->global_groups[ $group ] ) ) {
      if ( ! isset( $this->global_flush_number ) ) {
        $this->global_flush_number = intval( $this->get( 'flush_number', 'WP_Object_Cache_global' ) );
      }

      if ( 0 === $this->global_flush_number ) {
        $this->rotate_global_keys();
      }

      $number = $this->global_flush_number;
    } else {
      if ( ! isset( $this->flush_number[ $this->blog_prefix ] ) ) {
        $this->flush_number[ $this->blog_prefix ] = intval( $this->get( 'flush_number', 'WP_Object_Cache' ) );
      }

      if ( 0 === $this->flush_number[ $this->blog_prefix ] ) {
        $this->rotate_site_keys();
      }

      $number = $this->flush_number[ $this->blog_prefix ];
    }

    return $number . ':';
  }

  function key( $key, $group ) {

    if ( empty( $group ) ) {
      $group = 'default';
    }

    $prefix = $this->key_salt . $this->flush_prefix( $group );

    if ( isset( $this->global_groups[ $group ] ) ) {
      $prefix .= $this->global_prefix;
    } else {
      $prefix .= $this->blog_prefix;
    }

    return str_replace( ' ', '', $prefix .':'. $group .':'. $key );
  }

  function replace( $id, $data, $group = 'default', $expire = 0 ) {
    $key    = $this->key( $id, $group );

    $expire = intval( $expire );
    if ( 0 === $expire || $expire > $this->max_expiration ) {
      $expire = $this->default_expiration;
    }

    if ( is_object( $data ) ) {
      $data = clone $data;
    }

    $result = $this->shm_cache->replace( $key, $data, false, $expire );

    if ( false !== $result ) {
      $this->cache[ $key ] = $data;
    }

    return $result;
  }

  function set( $id, $data, $group = 'default', $expire = 0 ) {
    $key = $this->key( $id, $group );

    if ( is_object( $data ) ) {
      $data = clone $data;
    }

    $this->cache[ $key ] = $data;

    if ( isset( $this->no_shmcache_groups[ $group ] ) ) {
      return true;
    }

    $expire = intval( $expire );
    if ( 0 === $expire || $expire > $this->max_expiration ) {
      $expire = $this->default_expiration;
    }

    $result = $this->shm_cache->set( $key, $data, false, $expire );

    //++$this->stats[ 'set' ];
    //$this->group_ops[$group][] = "set $id";

    return $result;
  }

  function switch_to_blog( $blog_id ) {
    global $table_prefix;

    $blog_id = (int) $blog_id;

    $this->blog_prefix = ( is_multisite() ? $blog_id : $table_prefix );
  }

  function colorize_debug_line( $line ) {
    $colors = array(
      'get' => 'green',
      'set' => 'purple',
      'add' => 'blue',
      'delete' => 'red',
    );

    $cmd = substr( $line, 0, strpos( $line, ' ' ) );

    $cmd2 = "<span style='color:{$colors[$cmd]}'>$cmd</span>";

    return $cmd2 . substr( $line, strlen( $cmd ) ) . "\n";
  }

  function stats() {
    if ( $this->stats_callback && is_callable( $this->stats_callback ) ) {
      return call_user_func( $this->stats_callback );
    }

    echo "<p>\n";

    foreach ( $this->stats as $stat => $n ) {
      echo "<strong>$stat</strong> $n";
      echo "<br/>\n";
    }

    echo "</p>\n";
    echo '<h3>Memcached:</h3>';

    foreach ( $this->group_ops as $group => $ops ) {
      if ( ! isset( $_GET['debug_queries'] ) && 500 < count( $ops ) ) {
        $ops = array_slice( $ops, 0, 500 );
        echo "<big>Too many to show! <a href='" . add_query_arg( 'debug_queries', 'true' ) . "'>Show them anyway</a>.</big>\n";
      }

      echo "<h4>$group commands</h4>";
      echo "<pre>\n";

      $lines = array();

      foreach ( $ops as $op ) {
        $lines[] = $this->colorize_debug_line( $op );
      }

      print_r( $lines );

      echo "</pre>\n";
    }
  }

  function failure_callback( $host, $port ) {
    $this->connection_errors[] = array(
      'host' => $host,
      'port' => $port,
    );
  }

  function salt_keys( $key_salt ) {
    if ( strlen( $key_salt ) ) {
      $this->key_salt = $key_salt . ':';
    } else {
      $this->key_salt = '';
    }
  }

  function __construct() {
    $this->stats = array(
      'get'        => 0,
      'get_multi'  => 0,
      'add'        => 0,
      'set'        => 0,
      'delete'     => 0,
    );

    $this->shm_cache = new \Crusse\ShmCache( OBJECT_CACHE_SHM_SIZE );

    $this->mc = new Memcached();

    $mcHost = ( getenv( 'MEMCACHED_HOST' ) )
      ? getenv( 'MEMCACHED_HOST' )
      : '127.0.0.1';

    if ( 'unix://' == substr( $mcHost, 0, 7 ) ) {
      $mcPort = 0;
    }
    else {

      $mcPort = getenv( 'MEMCACHED_PORT' );

      if ( ! $mcPort ) {
        $mcPort = ini_get( 'memcache.default_port' );
      }

      $mcPort = intval( $mcPort );

      if ( ! $mcPort ) {
        $mcPort = 11211;
      }
    }

    $this->mc->addServer( $mcHost, $mcPort );

    global $blog_id, $table_prefix;

    $this->global_prefix = '';
    $this->blog_prefix  = '';

    if ( function_exists( 'is_multisite' ) ) {
      $this->global_prefix = ( is_multisite() || defined( 'CUSTOM_USER_TABLE' ) && defined( 'CUSTOM_USER_META_TABLE' ) ) ? '' : $table_prefix;
      $this->blog_prefix   = ( is_multisite() ? $blog_id : $table_prefix );
    }

    $this->salt_keys( WP_CACHE_KEY_SALT );

    $this->cache_hits   =& $this->stats['get'];
    $this->cache_misses =& $this->stats['add'];
  }
}

