<?php

$lockDirectory = dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing';

if (! is_dir($lockDirectory) && ! mkdir($lockDirectory, 0775, true) && ! is_dir($lockDirectory)) {
    throw new RuntimeException('Unable to create the PHPUnit lock directory.');
}

$lockPath = $lockDirectory.DIRECTORY_SEPARATOR.'phpunit.lock';
$lockHandle = fopen($lockPath, 'c+');

if ($lockHandle === false) {
    throw new RuntimeException('Unable to open the PHPUnit execution lock.');
}

if (! flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fclose($lockHandle);
    fwrite(STDERR, "Another PHPUnit process is already using this repository's shared test database.\n");
    exit(2);
}

ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) getmypid());
fflush($lockHandle);

register_shutdown_function(static function () use ($lockHandle): void {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

require dirname(__DIR__).'/vendor/autoload.php';
