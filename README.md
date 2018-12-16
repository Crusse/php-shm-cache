**This repo is undergoing a major refactoring in the `master` branch, as my
original implementation wasn't at all usable in real-world scenarios due to too
broad locking of the cache (not enough granularity). Stuff is therefore
currently broken.**

----------------------------------------------------------

A caching library for storing data in the operating system's shared memory.
Data is persisted across multiple runs of a PHP script.

The cached items have no expiration time. The cache has a FIFO queue which
starts removing oldest items once the cache gets full.

This library is intended as a hobby project for myself to learn about shared
memory and locking in a familiar context (PHP). (I'm essentially "_programming
FORTRAN in any language_" here.) Use at your own risk.

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

