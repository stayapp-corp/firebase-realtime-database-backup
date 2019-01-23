#! /usr/bin/php
<?php

require_once __DIR__ . "../vendor/autoload.php";

use Firebase\FirebaseLib;

// Get options from command line
$options = getopt(null,  ['url:', 'token:']);
if(!isset($options['url']) || !isset($options['token'])) {
    echo 'Fill token and url.';
    die;
}

if (!file_exists('backup')) {
    echo 'Backup not exists.';
    die;
}

$firebase = new FirebaseLib($options['url'], $options['token']);
$metadata = json_decode(file_get_contents('backup/metadata.json'), true);

foreach ($metadata as $path => $pathFb) {
    $data = json_decode(file_get_contents("backup/${path}.json"), true);

    if (is_array($pathFb)) {
        $keys = array_keys($data);

        foreach ($keys as $key) {
            $keyPath = $pathFb . '/' . $key;
            $firebase->set($keyPath, $data);
        }
    } else {
        $firebase->set($pathFb, $data);
    }
}

