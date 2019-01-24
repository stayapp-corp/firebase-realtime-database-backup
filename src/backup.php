#! /usr/bin/php
<?php

require_once __DIR__ . "./vendor/autoload.php";

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

function getData($path, $showLog=false) {
    global $firebase;
    if ($showLog) echo PHP_EOL . 'Searching path: ' . $path . PHP_EOL;

    $data = json_decode($firebase->get($path), true);
    $data = (isset($data['error']) && $data['error'] === 'Payload is too large') ? [] : $data;

    if (empty($data)) {
        $tryCount = 0;
        do {
            $data = json_decode($firebase->get($path, ['shallow' => 'true']), true);
            $data = (isset($data['error']) && $data['error'] === 'Payload is too large') ? [] : $data;
            $tryCount++;

            if ($tryCount === 10) {
                trigger_error(($path . " not exists."), E_USER_WARNING);
                return;
            }
        } while(empty($data));

        getDataChucked($path, $data, 1000, true);
    } else {
        generateFile($path, $data);
    }

    $data = null;
}

/**
 * @param $path
 * @param $data
 * @param $size
 * @param $showLog
 */
function getDataChucked($path, &$data, $size, $showLog = false) {
    $size = $size > count($data) ? count($data) : $size;
    $chuckedArray = array_chunk($data, $size, true);
    $countData = count($data);
    $data = null;
    if ($showLog) echo 'Processing ' . $countData . ' path ' . $path . PHP_EOL;

    for($i = 0; $i < count($chuckedArray); $i++){
        $chucked = $chuckedArray[$i];
        $chuckedArray[$i] = null;
        $keys = array_keys($chucked);
        $partData = getPaths($path, $keys);

        if (empty($partData)) {
            $partData = getPaths($path, $keys);
        }

        if (empty($partData)) {
            $lessNumbers = array_filter([1000, 500, 200, 100, 50, 10, 5, 1], function ($x) use ($size) { return $x < $size; });
            $chuckedSize = max($lessNumbers);
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
        $countData -= $size;
        if ($showLog) echo 'Remains ' . $countData . PHP_EOL;
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
            $chuckedData = array_chunk($data, (1000/$splitSize), true);
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

getData('/users_datas', true);

$metadataFile = fopen('backup/metadata.json', 'w');
fwrite($metadataFile, json_encode($metadata));
fclose($metadataFile);
