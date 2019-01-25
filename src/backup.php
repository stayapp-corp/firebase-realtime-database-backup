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
$intelligentIPP = [];
$shallowTree = [];
$maxIpp = 1000;
$minIpp = 2;

// Removing old backup
if (!file_exists('backup')) {
    mkdir('backup');
}

array_map('unlink', glob("backup/*"));

/**
 * @param $path
 */
function getData($path) {
    global $firebase, $shallowTree;
    $processedCount = 0;
    $firstKey = null;
    do {
        $pageData = getPathsPaginated($path, $firstKey);
        $firstKey = $pageData['lastKey'];

        if (isset($pageData['error']) && $pageData['error'] === 'go-deeper') {
            $shallowTries = 0;
            if (!isset($shallowTree[$path])) {
                do {
                    $shallowData = json_decode($firebase->get($path, ['shallow' => 'true']), true);
                    $shallowTries++;

                    if ($shallowTries === 10) {
                        error_log('Could not get database shallow data', E_USER_ERROR);
                        die;
                    }
                } while (empty($shallowData));

                $shallowTree[$path] = array_keys($shallowData);
            }

            $nextKey = null;
            if ($firstKey) {
                $firstProcessedIdx = array_search($firstKey, $shallowTree[$path]);
                if (count($shallowTree[$path]) > ($firstProcessedIdx + 1)) {
                    $nextKey = $shallowTree[$path][$firstProcessedIdx + 1];
                } else {
                    $pageData['isLastPage'] = true;
                }
            } else {
                $nextKey = $shallowTree[$path][0];
            }

            if ($nextKey) {
                $keyPath = $path . '/' . $nextKey;
                getData($keyPath);
                $firstKey = $nextKey;
                $processedCount += 1;
            }
        } else {
            $partData = $pageData['data'];

            generateFile($path, $partData);
            $processedCount += count($partData);
        }

        echo 'Processed ' . $processedCount . ' entries.' . PHP_EOL;
    } while (!$pageData['isLastPage']);
}

/**
 * @param $path
 * @param $key
 * @param $itemsPerPage
 * @return mixed
 */
function getPathsPaginated($path, $key = null, $itemsPerPage = 1000) {
    global $firebase, $intelligentIPP, $maxIpp, $minIpp;

    if (!isset($intelligentIPP[$path])) {
        $intelligentIPP[$path] = ["ipp" => min($itemsPerPage, $maxIpp), "success" => 0];
    } else {
        $itemsPerPage = $intelligentIPP[$path]['ipp'];
    }
    $newItemsPerPage = null;

    do {
        $itemsPerPage = ($newItemsPerPage ? $newItemsPerPage : $itemsPerPage);
        echo 'Getting ' . $path . ' with key ' . $key . ' and items per page: ' . $itemsPerPage . PHP_EOL;
        $query = [
            'orderBy' => '"$key"',
            'limitToFirst' => $itemsPerPage
        ];

        if (!empty($key)) {
            $query['startAt'] = '"' . $key . '"';
        }

        $newItemsPerPage = max($minIpp, ceil($itemsPerPage / 2));
        $partData = json_decode($firebase->get($path, $query), true);
        if (isset($partData['error'])) {
            print_r($partData);
        }
        $partData = (isset($partData['error']) && $partData['error'] === 'Payload is too large') ? [] : $partData;

        if ($itemsPerPage === $minIpp) {
            return ['error' => 'go-deeper', 'lastKey' => $key];
        }
    } while(empty($partData));

    $intelligentIPP[$path]['success'] += 1;
    if ($intelligentIPP[$path]['success'] > 5) {
        $intelligentIPP[$path]['success'] = 0;
        $intelligentIPP[$path]['ipp'] = min($maxIpp, floor($itemsPerPage * 1.2));
    } else if ($itemsPerPage != $intelligentIPP[$path]['ipp']) {
        $intelligentIPP[$path]['success'] = 0;
        $intelligentIPP[$path]['ipp'] = $itemsPerPage;
    }

    $countData = count($partData);
    $lastKey = array_keys($partData)[($countData - 1)];
    $isLastPage = ($countData < $itemsPerPage || ($countData === 1 && $itemsPerPage === 1 && $lastKey === $key));

    if (!empty($key)) {
        array_shift($partData);
    }

    return ['data' => $partData, 'isLastPage' => $isLastPage, 'lastKey' => $lastKey];
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

getData('/');

$metadataFile = fopen('backup/metadata.json', 'w');
fwrite($metadataFile, json_encode($metadata));
fclose($metadataFile);
