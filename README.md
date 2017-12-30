A caching library for storing data in the operating system's shared memory.
Data is persisted across multiple runs of a PHP script.

The cached items have no expiration time. The cache has a FIFO queue which
starts removing oldest items once the cache gets full.

## Performance

I've measured that this is at least twice as fast as a local Memcached over TCP
over PHP, both on PHP 5 and PHP 7. The main performance hit is from PHP's
`serialize()` and `unserialize()` when storing non-strings. Strings are stored
as-is, so they don't have the overhead of `serialize()` and `unserialize()`.

Run `php tests/scripts/test_performance.php` on your own machine to test. Make
sure to have Memcached installed first.

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

## Running unit tests

Run `vendor/bin/phpunit`.

