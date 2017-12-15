<?php

class ShmCache {

  const MEM_BLOCK_SIZE = 134217728;
  const SEMAPHORE_LOCK_TIMEOUT = 2;
  const MAX_ITEMS = 3;
  const SAFE_AREA_BETWEEN_KEYS_VALUES = 64;
  const MIN_ITEM_VALUE_SPLIT_SIZE = 32;

  private $block;
  private $semaphore;

  static private $hasLock = false;

  function __construct() {

    // We define these constants here, as PHP<5.6 doesn't accept arithmetic
    // expressions in class constants

    define( 'SHM_CACHE_LONG_SIZE', strlen( pack( 'l', 1 ) ) ); // i.e. sizeof(long) in C
    define( 'SHM_CACHE_CHAR_SIZE', strlen( pack( 'c', 1 ) ) ); // i.e. sizeof(char) in C

    // Cache item's key is an MD5 and takes 16 bytes.
    // Cache item's key has a flag (sizeof(char)) for whether the item's space is free.
    // Cache item's next key offset is a long.
    // Cache item's value size is a long.
    // Cache item's value offset is a long.
    define( 'SHM_CACHE_ITEM_META_SIZE', 16 + SHM_CACHE_CHAR_SIZE + SHM_CACHE_LONG_SIZE + SHM_CACHE_LONG_SIZE + SHM_CACHE_LONG_SIZE );
    // Cache keys ring buffer pointer is a long. The ring buffer pointer is
    // always the offset of the oldest cache item key.
    define( 'SHM_CACHE_KEYS_START', SHM_CACHE_LONG_SIZE );
    define( 'SHM_CACHE_KEYS_SIZE', self::MAX_ITEMS * SHM_CACHE_ITEM_META_SIZE );
    define( 'SHM_CACHE_VALUES_START', SHM_CACHE_LONG_SIZE + self::MAX_ITEMS * SHM_CACHE_ITEM_META_SIZE + self::SAFE_AREA_BETWEEN_KEYS_VALUES );
    define( 'SHM_CACHE_VALUES_SIZE', self::MEM_BLOCK_SIZE - SHM_CACHE_VALUES_START );

    $this->block = $this->openMemBlock();
    $this->semaphore = $this->getSemaphore();
  }

  function __destruct() {

    if ( $this->block )
      shmop_close( $this->block );
    if ( self::$hasLock )
      sem_release( $this->semaphore );
  }

  function set( $key, $value ) {

    if ( !$this->lock() )
      goto error;

    $keyMd5 = md5( $key, true );
    $item = $this->getItemMetaForMd5Key( $keyMd5 );

    if ( $item ) {
      if ( !$this->freeItem( $item ) )
        goto error;
    }

    // Replace the oldest cache item with the new value

    $oldestKeyPointer = $this->getRingBufferPointer();
    if ( !$oldestKeyPointer )
      goto error;

    $replacedItem = $this->getItemMetaByKeyOffset( $oldestKeyPointer );
    if ( !$replacedItem )
      goto error;

    $newValueSize = strlen( $value );

    // The new value doesn't fit into an existing cache item. Make space for the
    // new value by merging next oldest cache items one by one into the current
    // cache item, until we have enough space.
    while ( $replacedItem[ 'valsize' ] < $newValueSize ) {

      // Loop around if we reached the end of the cache keys memory area
      if ( !$replacedItem[ 'nextkeyoffset' ] ) {
        $nextItem = $this->getItemMetaByKeyOffset( SHM_CACHE_KEYS_START );
        if ( !$nextItem )
          goto error;
        $replacedItem[ 'valoffset' ] = SHM_CACHE_KEYS_START;
        $replacedItem[ 'valsize' ] = 0;
      }
      else {
        $nextItem = $this->getItemMetaByKeyOffset( $replacedItem[ 'nextkeyoffset' ] );
        if ( !$nextItem )
          goto error;
      }

      // Don't merge self into self
      if ( $nextItem[ 'keyoffset' ] === $replacedItem[ 'keyoffset' ] )
        goto error;

      $replacedItem[ 'valsize' ] += $nextItem[ 'valsize' ];
      $replacedItem[ 'nextkeyoffset' ] = $nextItem[ 'nextkeyoffset' ];

      // Invalidate the cache key
      $nextItem[ 'free' ] = false;
      $nextItem[ 'key' ] = pack( 'x16' );
      $nextItem[ 'nextkeyoffset' ] = 0;
      $nextItem[ 'valsize' ] = 0;
      $nextItem[ 'valoffset' ] = 0;

      if ( !$this->updateCacheItemKey( $nextItem ) )
        goto error;
    }

    // Split the cache item into two, if there is enough space left over
    if ( $newValueSize <= $replacedItem[ 'valsize' ] - self::MIN_ITEM_VALUE_SPLIT_SIZE ) {

      $splitValueSize = $replacedItem[ 'valsize' ] - $newValueSize;
      $splitValueOffset = $replacedItem[ 'valoffset' ] + $newValueSize;

      // Find an unused cache key spot
      $splitKeyOffset = SHM_CACHE_KEYS_START;
      $splitMeta = $this->getItemMetaByKeyOffset( $splitKeyOffset );
      while ( $splitMeta && $splitMeta[ 'valoffset' ] != 0 && $splitKeyOffset < SHM_CACHE_KEYS_START + SHM_CACHE_KEYS_SIZE ) {
        $splitKeyOffset += SHM_CACHE_ITEM_META_SIZE;
        $splitMeta = $this->getItemMetaByKeyOffset( $splitKeyOffset );
      }

      if ( !$splitMeta || $splitMeta[ 'valoffset' ] != 0 ) {
        trigger_error( 'DEBUG: could not find a free cache key spot. Might have reached MAX_ITEMS.' );
        goto error;
      }

      if ( !$this->createCacheItem( $this->block, $splitMeta[ 'keyoffset' ], pack( 'x16' ), true, $replacedItem[ 'nextkeyoffset' ], $splitValueSize, $splitValueOffset ) )
        goto error;

      $replacedItem[ 'valsize' ] = $newValueSize;
      $replacedItem[ 'nextkeyoffset' ] = $splitMeta[ 'keyoffset' ];
    }

    $replacedItem[ 'free' ] = false;
    $replacedItem[ 'key' ] = $keyMd5;

    if ( !$this->updateCacheItemKey( $replacedItem ) )
      goto error;

    if ( shmop_write( $this->block, $value, $replacedItem[ 'valoffset' ] ) === false )
      goto error;

    $newBufferPtr = ( $replacedItem[ 'nextkeyoffset' ] )
      ? $replacedItem[ 'nextkeyoffset' ]
      : SHM_CACHE_KEYS_START;

    if ( !$this->setRingBufferPointer( $newBufferPtr ) )
      goto error;

    $this->releaseLock();
    return true;


    error:
    $this->releaseLock();
    return false;
  }

  function get( $key ) {

    if ( !$this->lock() )
      return false;

    $item = $this->getItemMetaForMd5Key( md5( $key, true ) );

    if ( !$item )
      $ret = false;
    else
      $ret = shmop_read( $this->block, $item[ 'valoffset' ], $item[ 'valsize' ] );

    $this->releaseLock();

    return $ret;
  }

  function delete( $key ) {

    if ( !$this->lock() )
      return false;

    $item = $this->getItemMetaForMd5Key( $keyMd5 );

    if ( !$item )
      $ret = false;
    else
      $ret = (bool) $this->freeItem( $item );

    $this->releaseLock();

    return $ret;
  }

  function deleteAll() {

    if ( !$this->lock() )
      return false;

    try {
      $this->initializeMemBlock( $this->block );
      $ret = true;
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
      $ret = false;
    }

    $this->releaseLock();

    return $ret;
  }

  private function getRingBufferPointer() {

    $oldestKeyPointer = unpack( 'l', shmop_read( $this->block, 0, SHM_CACHE_LONG_SIZE ) )[ 1 ];

    if ( !$oldestKeyPointer ) {
      trigger_error( 'Could not find the ring buffer pointer' );
      return null;
    }

    if ( $oldestKeyPointer < SHM_CACHE_KEYS_START || $oldestKeyPointer >= SHM_CACHE_KEYS_START + SHM_CACHE_KEYS_SIZE ) {
      trigger_error( 'The ring buffer pointer is out of bounds' );
      return null;
    }

    return $oldestKeyPointer;
  }

  private function setRingBufferPointer( $keyOffset ) {

    if ( shmop_write( $this->block, pack( 'l', $keyOffset ), 0 ) === false ) {
      trigger_error( 'Could not write the ring buffer pointer' );
      return false;
    }

    return true;
  }

  private function freeItem( $item ) {
    $item[ 'free' ] = true;
    return $this->updateCacheItemKey( $item );
  }

  private function updateCacheItemKey( $item ) {

    $data = $item[ 'key' ] . pack( 'c', (int) $item[ 'free' ] ) . pack( 'l', $item[ 'nextkeyoffset' ] ) . pack( 'l', $item[ 'valsize' ] ) . pack( 'l', $item[ 'valoffset' ] );

    if ( shmop_write( $this->block, $data, $item[ 'keyoffset' ] ) === false ) {
      trigger_error( 'Could not update cache item' );
      return false;
    }

    return true;
  }

  private function createCacheItem( $block, $keyOffset, $keyMd5, $isFree, $nextKeyOffset, $valueSize, $valueOffset ) {

    if ( strlen( $keyMd5 ) !== 16 )
      throw new \InvalidArgumentException( 'Given MD5 key must be in binary, i.e. 16 bits wide' );

    $data = $keyMd5 . pack( 'c', (int) $isFree ) . pack( 'l', $nextKeyOffset ) . pack( 'l', $valueSize ) . pack( 'l', $valueOffset );

    return ( shmop_write( $block, $data, $keyOffset ) !== false );
  }

  private function getItemMetaForMd5Key( $keyMd5 ) {

    if ( strlen( $keyMd5 ) !== 16 )
      throw new \InvalidArgumentException( 'Given MD5 key must be in binary, i.e. 16 bits wide' );

    $keyOffset = SHM_CACHE_KEYS_START;

    while ( $keyOffset ) {

      $meta = $this->getItemMetaByKeyOffset( $keyOffset );

      if ( !$meta )
        return null;

      if ( !$meta[ 'free' ] && $meta[ 'key' ] === $keyMd5 ) {

        if ( !$meta[ 'valsize' ] ) {
          trigger_error( 'Could not determine the size of an item in the memory block. This should not happen.' );
          return null;
        }

        if ( !$meta[ 'valoffset' ] ) {
          trigger_error( 'Could not determine the offset of an item in the memory block. This should not happen.' );
          return null;
        }

        return $meta;
      }

      $keyOffset = $meta[ 'nextkeyoffset' ];
    }

    return null;
  }

  private function getItemMetaByKeyOffset( $keyOffset ) {

    if ( $keyOffset + SHM_CACHE_ITEM_META_SIZE > SHM_CACHE_KEYS_START + SHM_CACHE_KEYS_SIZE ) {
      trigger_error( 'Invalid offset "'. $keyOffset .'"' );
      return null;
    }

    $data = shmop_read( $this->block, $keyOffset, SHM_CACHE_ITEM_META_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not read item metadata from the memory block' );
      return null;
    }

    $unpacked = unpack( 'cfree/lnextkeyoffset/lvalsize/lvaloffset', substr( $data, 16 ) );
    $unpacked[ 'key' ] = substr( $data, 0, 16 );
    $unpacked[ 'keyoffset' ] = $keyOffset;

    return $unpacked;
  }

  private function lock() {

    if ( self::$hasLock ) {
      trigger_error( 'Tried to acquire lock even though lock is already acquired' );
      return true;
    }

    $start = time();

    while ( !sem_acquire( $this->semaphore, true ) ) {

      if ( time() - $start >= self::SEMAPHORE_LOCK_TIMEOUT ) {
        trigger_error( 'Could not acquire semaphore lock before timeout' );
        $this->releaseLock();
        return false;
      }

      usleep( 500 );
    }

    self::$hasLock = true;

    return true;
  }

  private function releaseLock() {

    $ret = false;

    if ( sem_release( $this->semaphore ) ) {
      $ret = true;
      self::$hasLock = false;
    }
    else {
      trigger_error( 'Could not release semaphore lock' );
    }

    return $ret;
  }

  private function getSemaphore() {

    $tmpFile = sys_get_temp_dir() .'/php-shm-cache-87b1dcf602a-semaphore.lock';
    touch( $tmpFile );

    return sem_get( fileinode( $tmpFile ), 1, 0666, 1 );
  }

  private function openMemBlock() {

    $tmpFile = sys_get_temp_dir() .'/php-shm-cache-87b1dcf602a.lock';
    touch( $tmpFile );
    $blockKey = fileinode( $tmpFile );

    if ( !$blockKey )
      throw new \InvalidArgumentException( 'Invalid shared memory block key' );

    $mode = 0666;
    $block = @shmop_open( $blockKey, "w", $mode, self::MEM_BLOCK_SIZE );

    if ( !$block ) {
      $block = shmop_open( $blockKey, "n", $mode, self::MEM_BLOCK_SIZE );
      if ( $block )
        $this->initializeMemBlock( $block );
    }

    if ( !$block )
      throw new \Exception( 'Could not create a shared memory block' );

    return $block;
  }

  /**
   * Initialize the memory block with a single, large cache key to represent
   * free space.
   */
  private function initializeMemBlock( $block ) {

    // Clear all bytes in the shared memory block
    $memoryWriteChunk = 1024 * 1024 * 8;
    for ( $i = 0; $i < self::MEM_BLOCK_SIZE; $i += $memoryWriteChunk ) {

      // Last chunk might have to be smaller
      if ( $i + $memoryWriteChunk > self::MEM_BLOCK_SIZE )
        $memoryWriteChunk = self::MEM_BLOCK_SIZE - $i;

      if ( shmop_write( $block, pack( 'x'. $memoryWriteChunk ), $i ) === false )
        throw new \Exception( 'Could not write NUL bytes to the memory block' );
    }

    // The ring buffer pointer always points to the oldest cache item. In this
    // case it's the first key of the new memory block, which itself points to
    // free space of the values area.
    $this->setRingBufferPointer( SHM_CACHE_KEYS_START );

    // Initialize first cache item
    $isFree = true;
    $keyMd5 = pack( 'x16' );
    $nextKeyOffset = 0;
    $valueSize = SHM_CACHE_VALUES_SIZE;
    $valueOffset = SHM_CACHE_VALUES_START;

    if ( !$this->createCacheItem( $block, SHM_CACHE_KEYS_START, $keyMd5, $isFree, $nextKeyOffset, $valueSize, $valueOffset ) )
      trigger_error( 'Could not create initial cache item' );
  }

  function dumpKeyAreaDebug() {

    echo 'Ring buffer pointer: '. unpack( 'l', shmop_read( $this->block, 0, SHM_CACHE_KEYS_START ) )[ 1 ] . PHP_EOL;
    echo PHP_EOL;
    echo 'Items in cache:'. PHP_EOL . PHP_EOL;

    for ( $i = SHM_CACHE_KEYS_START; $i < SHM_CACHE_KEYS_START + SHM_CACHE_KEYS_SIZE; $i += SHM_CACHE_ITEM_META_SIZE ) {

      $item = $this->getItemMetaByKeyOffset( $i );

      if ( $item && $item[ 'valsize' ] ) {
        echo 'Item (keyoffset: '. $item[ 'keyoffset' ] .', nextkeyoffset: '. $item[ 'nextkeyoffset' ] .', valoffset: '. $item[ 'valoffset' ] .', size: '. $item[ 'valsize' ] .')'. PHP_EOL;
        if ( $item[ 'free' ] )
          echo '    [Free space]';
        else
          echo '    "'. shmop_read( $this->block, $item[ 'valoffset' ], $item[ 'valsize' ] ) .'"';
        echo PHP_EOL;
      }
    }

    echo PHP_EOL;
  }
}


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$cache = new ShmCache();

if ( $argc > 1 && $argv[ 1 ] === 'clear' ) {
  $cache->deleteAll();
  echo 'Deleted all'. PHP_EOL;
}

$key = 'blaa';
$value = $cache->get( $key );
echo 'Old value: '. var_export( $value, true ) . PHP_EOL;
$num = ( $value ) ? intval( $value ) : 0;

if ( !$cache->set( $key, ( $num + 1 ) .' foo' ) )
  echo 'Failed setting value'. PHP_EOL;

echo '---------------------------------------'. PHP_EOL;
echo 'Debug:'. PHP_EOL;
$cache->dumpKeyAreaDebug();
