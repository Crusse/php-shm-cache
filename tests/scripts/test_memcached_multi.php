<?php

$m = new Memcached;

// Multiple get()s
$m->flush();
for ( $i = 0; $i < 10000; ++$i ) {
  $m->set( 'foo'. $i, str_repeat( 'x', 1000 ) );
}
$start = microtime( true );
for ( $i = 0; $i < 10000; ++$i ) {
  $val = $m->get( 'foo'. $i );
}
echo 'Multiple get()s: '. ( microtime( true ) - $start ) . PHP_EOL;

// One multi-get()
$m->flush();
for ( $i = 0; $i < 10000; ++$i ) {
  $m->set( 'foo'. $i, str_repeat( 'x', 1000 ) );
}
$start = microtime( true );
$keys = [];
for ( $i = 0; $i < 10000; ++$i ) {
  $keys[] = 'foo'. $i;
}
$vals = $m->getMulti( $keys );
echo 'One getMulti():  '. ( microtime( true ) - $start ) . PHP_EOL;

