<?php

class ShmCache {

  const MEM_BLOCK_SIZE = 134217728;
  const SEMAPHORE_LOCK_TIMEOUT = 2;
  const MAX_ITEMS = 20000;
  const SAFE_AREA_BETWEEN_KEYS_VALUES = 64;

  private $block;
  private $semaphore;

  static private $hasLock = false;

  function __construct() {

    // We define these constants here, as PHP<5.6 doesn't accept arithmetic
    // expressions in class constants

    define( 'SHM_CACHE_LONG_SIZE', strlen( pack( 'l', 1 ) ) ); // i.e. sizeof(long) in C
    define( 'SHM_CACHE_CHAR_SIZE', strlen( pack( 'c', 1 ) ) ); // i.e. sizeof(char) in C

    // Cache item's key has a flag (sizeof(char)) for whether the item's space is free.
    // Cache item's key is an MD5 and takes 16 bytes.
    // Cache item's next key pointer is a long.
    // Cache item's value's size is a long.
    // Cache item's value's pointer is a long.
    define( 'SHM_CACHE_ITEM_META_SIZE', SHM_CACHE_CHAR_SIZE + 16 + SHM_CACHE_LONG_SIZE + SHM_CACHE_LONG_SIZE + SHM_CACHE_LONG_SIZE );
    // Cache keys ring buffer pointer is a long. The ring buffer pointer always
    // points to the oldest cache item key.
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
      return false;

    $keyMd5 = md5( $key, true );
    $cacheWritten = false;

    $item = $this->getItemMetaByKeyMd5( $keyMd5 );
    $valueSize = strlen( $value );

    if ( $item ) {
      if ( !$this->freeItem( $item ) )
        return false;
    }

    // Make space for the new value by removing the oldest cache values one by
    // one, until we have enough space

    $oldestKeyPointer = $this->getRingBufferPointer();
    if ( !$oldestKeyPointer )
      return false;

    $newItem = $this->getItemMetaByOffset( $oldestKeyPointer );

    if ( !$newItem ) {
      trigger_error( 'Could not find the cache key pointed to by the ring buffer pointer' );
      return false;
    }

    while ( $newItem[ 'valsize' ] < $valueSize ) {

      $nextPtr = $newItem[ 'next' ];

      // Loop around if we reached the end of the cache keys memory area
      if ( !$nextPtr ) {
        $nextPtr = SHM_CACHE_KEYS_START;
        $newItem[ 'valsize' ] = 
      }

      $nextItem = $this->getItemMetaByOffset( $nextPtr );

      if ( !$nextItem || !$this->setItemFreeFlag( $nextItem, true ) )
        return false;

      $newItem[ 'valsize' ] += $nextItem[ 'valsize' ];
      $newItem[ 'next' ] = $nextItem[ 'next' ];
    }

    if ( $blockStart >= self::MEM_BLOCK_SIZE )
      trigger_error( 'Could not find a large enough space for the item "'. $key .'"' );

    $this->releaseLock();

    return $cacheWritten;
  }

  function get( $key ) {

    if ( !$this->lock() )
      return false;

    list( $blockStart, $blockSize ) = $this->getBlockStartAndSizeForItem( $key );

    if ( $blockStart === null )
      $ret = false;
    else
      $ret = shmop_read( $this->block, $blockStart + 40, $blockSize - 40 );

    $this->releaseLock();

    return $ret;
  }

  function delete( $key ) {

    $item = $this->getItemMetaByKeyMd5( $keyMd5 );

    if ( !$item )
      return false;

    return (bool) $this->freeItem( $item );
  }

  function deleteAll() {

    try {
      $this->initializeMemBlock( $this->block );
    }
    catch ( \Exception $e ) {
      trigger_error( $e->getMessage() );
      return false;
    }

    return true;
  }

  private function getRingBufferPointer() {

    $oldestKeyPointer = unpack( 'l', shmop_read( $this->block, 0, SHM_CACHE_LONG_SIZE ) );

    if ( $oldestKeyPointer < SHM_CACHE_KEYS_START || $oldestKeyPointer >= SHM_CACHE_KEYS_START + SHM_CACHE_KEYS_SIZE ) {
      trigger_error( 'Could not find the ring buffer pointer' );
      return null;
    }

    return $oldestKeyPointer;
  }

  private function freeItem( $item ) {
    return $this->setItemFreeFlag( $item, true );
  }

  private function setItemFreeFlag( $item, $isFree ) {

    $isFree = (bool) $isFree;

    if ( (bool) $item[ 'free' ] === $isFree )
      return true;

    if ( shmop_write( $this->block, pack( 'c', (int) $isFree ), $item[ 'keyOffset' ] ) === false ) {
      trigger_error( 'Could not set cache item\'s "free" flag' );
      return false;
    }

    return true;
  }

  private function setItemMd5Key( $item, $keyMd5 ) {

    if ( strlen( $keyMd5 ) !== 16 )
      throw new \InvalidArgumentException( 'Given MD5 key must be in binary, i.e. 16 bits wide' );

    if ( $item[ 'key' ] === $keyMd5 )
      return true;

    if ( shmop_write( $this->block, $keyMd5, $item[ 'keyOffset' ] + SHM_CACHE_CHAR_SIZE ) === false ) {
      trigger_error( 'Could not set cache item\'s key' );
      return false;
    }

    return true;
  }

  private function setItemNextPointer( $item, $nextOffset ) {

    if ( $item[ 'next' ] === $nextOffset )
      return true;

    if ( shmop_write( $this->block, pack( 'l', $nextOffset ), $item[ 'keyOffset' ] + SHM_CACHE_CHAR_SIZE + 16 ) === false ) {
      trigger_error( 'Could not set cache item\'s next pointer' );
      return false;
    }

    return true;
  }

  private function getItemMetaByKeyMd5( $keyMd5 ) {

    $keyOffset = SHM_CACHE_KEYS_START;

    while ( $keyOffset ) {

      $meta = $this->getItemMetaByOffset( $keyOffset );

      if ( !$meta )
        return null;

      if ( !$meta[ 'free' ] && $meta[ 'key' ] === $keyMd5 )
        return $meta;

      $keyOffset = $meta[ 'next' ];
    }

    return null;
  }

  private function getItemMetaByOffset( $keyOffset ) {

    if ( $keyOffset + SHM_CACHE_ITEM_META_SIZE > SHM_CACHE_KEYS_START + SHM_CACHE_KEYS_SIZE ) {
      trigger_error( 'Invalid offset "'. $keyOffset .'"' );
      return null;
    }

    $data = shmop_read( $this->block, $keyOffset, SHM_CACHE_ITEM_META_SIZE );

    if ( $data === false ) {
      trigger_error( 'Could not read item metadata from the memory block' );
      return null;
    }

    $unpacked = unpack( 'c1free/c16key/lnext/lvalsize/lvaloffset', $data );

    if ( !$unpacked[ 'valsize' ] ) {
      trigger_error( 'Could not determine the size of an item in the memory block. This should not happen.' );
      return null;
    }

    if ( !$unpacked[ 'valoffset' ] ) {
      trigger_error( 'Could not determine the offset of an item in the memory block. This should not happen.' );
      return null;
    }

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
    $ringBufferPointer = pack( 'l', SHM_CACHE_KEYS_START );

    $firstKeyIsFree = pack( 'c', 1 );
    $firstKeyMd5 = pack( 'x16' );
    $firstKeyNextPtr = pack( 'l', 0 ); // Points to nothing, as there are no other keys
    $firstValueSize = pack( 'l', SHM_CACHE_VALUES_SIZE );
    $firstValueOffset = pack( 'l', SHM_CACHE_VALUES_START );

    $firstKey = $firstKeyIsFree . $firstKeyMd5 . $firstValueSize . $firstValueOffset;

    $initialData = $ringBufferPointer . $firstKey;

    if ( shmop_write( $block, $initialData, 0 ) === false )
      throw new \Exception( 'Could not write initial cache key to the memory block' );
  }
}


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$cache = new ShmCache();
$cache->deleteAll();
$key = 'blaa';
$value = $cache->get( $key );
$num = ( $value ) ? intval( $value ) : 0;

echo 'Old value: '. $value . PHP_EOL;

if ( !$cache->set( $key, ( $num + 1 ) .' foo' ) )
  throw new \Exception( 'Could not write the shared memory variable "'. $key .'"' );

