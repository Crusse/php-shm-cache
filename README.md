A caching library for storing data in the operating system's shared memory.
Data is persisted across multiple runs of a PHP script.

The cached items have no expiration time. The cache has a FIFO queue which
starts removing oldest items once the cache gets full.

*NOTE:*

When there are parallel reads and writes (as there are on websites), locking
is a major bottleneck, so this library is currently not really usable.
TODO: learn more about lock granularities, so that we only do minimal work
inside a lock.

## Installing

Add this to your composer.json:

```
"require": {
  "crusse/shm-cache": "dev-master",
},
"repositories": [
  {
    "url": "https://github.com/Crusse/php-shm-cache.git",
    "type": "vcs"
  }
]
```

and run `composer install`.

## Supported platforms

Only tested on Debian-based Linux distributions, but should also work on other
Linuxes.

MacOS is not supported, but adding support for it would probably be quite easy.
At least the lock file location of /var/lock has to change for MacOS.

Windows is not supported.

## Usage

```
$cache = new \Crusse\ShmCache;
$cache->set( 'foo', 'bar' );
$value = $cache->get( 'foo' );
$cache->delete( 'foo' );
```

Or if you need to define the shared memory block's size:

```
// 512 MiB cache
$cache = new \Crusse\ShmCache( 1024 * 1024 * 512 );
```

See the ShmCache class's file for details.

## Running unit tests

Run `vendor/bin/phpunit`.

## Performance

I've measured that this is faster than a local Memcached over TCP called from PHP,
both on PHP 5 and PHP 7, when there are no parallel reads and writes going on.
The main performance hit is from PHP's `serialize()` and `unserialize()` when
storing non-strings. Strings are stored as-is, so they don't have the overhead
of `serialize()` and `unserialize()`.

Run `php tests/scripts/test_performance.php` on your own machine to test. Make
sure to have Memcached installed first.

Here is an example from my machine:

```
Total set:
ShmCache:  0.39592123031616 s
Memcached: 1.8979852199554 s
FileCache: 2.4393043518066 s

Total get:
ShmCache:  0.021393299102783 s
Memcached: 0.083987236022949 s
FileCache: 0.032261610031128 s
```

