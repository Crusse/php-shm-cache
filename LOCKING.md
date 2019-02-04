The locking implementation
--------------------------

## TODO

- Fix locking bugs in the PHP code
- Create ShmBackedObject::validateLockRules($lockManager) so that we can make sure that the correct locks have been acquired when the ShmBackedObject's `__isset()`, `__get()` and `__set()` are called.


## Memory block structure

#### Metadata area:

    [oldestzoneindex]

The 'oldestzoneindex' points to the oldest zone in the zones area.
When the cache is full, the oldest zone is evicted.

#### Stats area:

    [gethits][getmisses]

#### Hash table bucket area:

    [chunkoffset,chunkoffset,...]

Our hash table uses "separate chaining" (look it up). The 'chunkoffset'
points to a chunk in a zone's 'chunksarea'.

#### Zones area:

    [[usedspace,chunksarea],[usedspace,chunksarea],...]

The zones area is a ring buffer. The 'oldestzoneindex' points to
the oldest zone. Each zone is a stack of chunks.

A zone's 'usedspace' can be used to calculate the first free chunk in
that zone. All chunks up to that point is memory in use; all chunks
after that point is free space. 'usedspace' is therefore essentially
a stack pointer.

Each zone is roughly in the order in which the zones were created, so
that we can easily find the oldest zones for eviction, to make space for
new cache items.

Each chunk contains a single cache item. A 'chunksarea' looks like this:

#### Chunks area:

    [[key,hashnext,valallocsize,valsize,flags,value],...]

'key' is the hash table key as a string.

'hashnext' is the offset (in the zone area) of the next chunk in
the current hash table bucket. If 0, it's the last entry in the bucket.
This is used to traverse the entries in a hash table bucket, which is
a linked list.

If 'valsize' is 0, that value slot is free. This doesn't mean that all
the next chunks in this zone are free as well -- only the zone's usedspace
(i.e. its stack pointer) tells where the zone's free area starts.

'valallocsize' is how big the allocated size is. This is usually the
same as 'valsize', but can be larger if the value was replaced with
a smaller value later, or if `valsize < MIN_VALUE_ALLOC_SIZE`.

To traverse a single zone's chunks from left to right, keep incrementing
your offset by chunkSize.

`chunkSize = CHUNK_META_SIZE + valallocsize`


## The locks

- __Everything lock:__
  locks all of the shared memory
- __Stats lock:__
  locks all access to the stats memory region
- __Bucket locks:__
  locks a hash table bucket, and the hashNext property of all entries in that bucket
- __Zone locks:__
  locks a single zone and all chunks in it
- __Zones area ring buffer lock:__
  locks the 'oldestzoneindex' ring buffer pointer

#### Resources locked by locks

- E: Everything lock
- B: Bucket lock
- Z: Zone lock
- R: Ring buffer point (oldestzoneindex) lock

    E B Z R |
            | [Bucket]
    x x     | chunkoffset
            |
            | [Zone]
    x   x   | usedspace
            |
            | [Chunk]
    x x x   | key
    x x x   | hashnext
    x   x   | valallocsize
    x   x   | valsize
    x   x   | flags
    x   x   | value
            |
            | [Metadata]
    x     x | oldestzoneindex


## Locking rules

- __RULE 1:__
  for any read or write of the shared memory block, you must always first lock
  the __everything__ lock.

  If you want to write to a small portion of the memory, e.g. a chunk, acquire
  a read lock on __everything__ and a write lock on the chunk.

  If you want to write to the whole shared memory block (e.g. when first
  initializing the shm block) you must acquire a write lock on __everything__.

- __RULE 2:__
  to modify anything in a chunk, you must hold the chunk's associated cache key
  bucket's lock

- __RULE 3:__ to modify _anything_ in a zone, you must hold that zone's lock

- __RULE 4:__ the lock order between a bucket and a zone lock must always be
  bucket -> zone, not zone -> bucket. For ringBufferPtr it's always
  ringBufferPtr -> zone. This makes the full lock order be:

  Everything -> bucket -> ringBufferPtr -> zone

  This doesn't mean you should always get all of these 3 locks when you want to
  lock a zone, for example. But if you acquire 2 or more of these different
  locks, make sure the order is as shown here (e.g. bucket -> zone).

- __RULE 5:__ multiple zones can't be locked at the same time by a single process

- __RULE 6:__ multiple buckets can be locked simultaneously by a single process,
  but _only_ by using a try-lock when trying to lock the 2nd..Nth bucket (see
  **RULE 7**). If the try-lock fails, the process must drop its zone lock and
  ringBufferPtr lock before trying again.

- __RULE 7:__ you can violate **RULE 4's** bucket -> zone order and acquire a lock
  in zone -> bucket order, but only if you use a try-lock when locking the bucket
  after having locked the zone with a normal lock (not a try-lock). For example:

        P1: LOCK bucket A
                               P2: LOCK bucket B
        P1: LOCK RBP
        P1: LOCK zone Y
                               P2: LOCK RBP
        P1: TRYLOCK bucket B
            (fail)
        P1: UNLOCK zone Y
        P1: UNLOCK RBP
                               P2: LOCK zone Y
                               P2: TRYLOCK bucket B
                                   (success: P2 already has it)
                               P2: Do some operation on zone Y
                               P2: UNLOCK zone Y
                               P2: UNLOCK RBP
        P1: LOCK RBP
        P1: LOCK zone Y
        P1: TRYLOCK bucket A
            (success: P1 already has it)
        P1: Do some operation on zone Y
        P1: UNLOCK zone Y

- __RULE 8:__ unlocks can be done in any order without fear of deadlocks, as
  long as the _locking_ order rules are followed. See
  `https://yarchive.net/comp/linux/lock_ordering.html` for an example from
  Torvalds: "The FACT is, that unlocks do not have to nest cleanly. That's
  a rock solid *FACT*. The locking order matters, and the unlocking order does not."


