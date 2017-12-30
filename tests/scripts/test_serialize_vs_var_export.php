<?php

$randObj = new stdClass;
for ( $i = 0; $i < 200; ++$i ) {
  $key = '';
  for ( $j = 0; $j < 10; ++$j )
    $key .= chr( rand( ord( 'a' ), ord( 'z' ) ) );
  $randObj->$key = array_fill( 0, 100, 'x' );
}

$totalSerialize = 0;
$totalVarExport = 0;
$totalUnserialize = 0;
$totalEval = 0;

for ( $i = 0; $i < 1000; ++$i ) {

  $start = microtime( true );
  $valueSerialized = serialize( $randObj );
  $totalSerialize += ( microtime( true ) - $start );

  $start = microtime( true );
  $valueUnserialized = unserialize( $valueSerialized );
  $totalUnserialize += ( microtime( true ) - $start );

  $start = microtime( true );
  $valueVarExported = var_export( $randObj, true );
  $valueVarExported = '(object)'. substr( $valueVarExported, 21 );
  $totalVarExport += ( microtime( true ) - $start );

  $start = microtime( true );
  eval( '$valueEvaled = '. $valueVarExported .';' );
  $totalEval += ( microtime( true ) - $start );
}

echo 'serialize(): '. $totalSerialize . PHP_EOL;
echo 'unserialize(): '. $totalUnserialize . PHP_EOL;
echo 'var_export(): '. $totalVarExport . PHP_EOL;
echo 'eval() var_export()ed obj: '. $totalEval . PHP_EOL;

