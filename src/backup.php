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

ini_set('memory_limit', '4G');
$firebase = new FirebaseLib($options['url'], $options['token']);
$metadata = [];

// Removing old backup
if (!file_exists('backup')) {
    mkdir('backup');
}

array_map('unlink', glob("backup/*"));

function getData($path) {
    global $firebase;

    $data = json_decode($firebase->get($path), true);
    $data = (isset($data['error']) && $data['error'] === 'Payload is too large') ? [] : $data;

    if (empty($data)) {
        do {
            $data = json_decode($firebase->get($path, ['shallow' => 'true']), true);
            $data = (isset($data['error']) && $data['error'] === 'Payload is too large') ? [] : $data;
        } while(empty($data));

        getDataChucked($path, $data, 1000);
    } else {
        generateFile($path, $data);
    }

    $data = null;
}

/**
 * @param $path
 * @param $data
 * @param $size
 */
function getDataChucked($path, &$data, $size) {
    $size = $size > count($data) ? count($data) : $size;
    $chuckedArray = array_chunk($data, $size, true);
    $data = null;

    for($i = 0; $i < count($chuckedArray); $i++){
        $chucked = $chuckedArray[$i];
        $chuckedArray[$i] = null;
        $keys = array_keys($chucked);
        $partData = getPaths($path, $keys);

        if (empty($partData)) {
            $chuckedSize = ($size > 100) ? 100 : ($size > 10) ? 10 : 1;
            if ($chuckedSize === 1) {
                foreach ($keys as $key) {
                    $keyPath = $path . '/' . $key;
                    getData($keyPath);
                }
                $keys = null;
            } else {
                getDataChucked($path, $chucked, $chuckedSize);
            }
        } else {
            generateFile($path, $partData);
        }

        $partData = null;
    }

    $chuckedArray = null;
}

/**
 * @param $path
 * @param $keys
 * @return mixed
 */
function getPaths($path, $keys) {
    global $firebase;
    $query = [
        'orderBy' => '"$key"',
        'startAt' => '"' . $keys[0] . '"',
        'endAt' => '"' . $keys[(count($keys) - 1)] . '"'
    ];

    $partData = json_decode($firebase->get($path, $query), true);
    $partData = (isset($partData['error']) && $partData['error'] === 'Payload is too large') ? [] : $partData;
    return $partData;
}

/**
 * @param $path
 * @param $data
 */
function generateFile($path, $data)
{
    global $metadata;

    $successfully = false;
    $splitSize = 1;

    do {
        try {
            $chuckedData = array_chunk($data, (1000/$splitSize));
            for($i = 0; $i < count($chuckedData); $i++) {
                $md5Pth = md5(uniqid(""));
                $filePath = "backup/${md5Pth}.json";
                $metadata[$filePath] = $path;

                $file = fopen($filePath, 'w');
                $chucked = $chuckedData[$i];
                $chuckedData[$i] = null;
                fwrite($file, json_encode($chucked));

                fclose($file);
                $md5Pth = null;
                $filePath = null;
                $file = null;
            }

            $successfully = true;
        } catch (Exception $e) {
            $splitSize++;
        }
    } while (!$successfully);
}

getData('/');

$metadataFile = fopen('backup/metadata.json', 'w');
fwrite($metadataFile, json_encode($metadata));
fclose($metadataFile);
