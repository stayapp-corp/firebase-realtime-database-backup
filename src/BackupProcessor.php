<?php

namespace FRDBackup;

use Firebase\FirebaseLib;

class BackupProcessor {

    private $firebase;
    private $metadata = [];
    private $intelligentIPP = [];
    private $shallowTree = [];
    private $maxIpp = 1000;
    private $minIpp = 2;

    function __construct($firebase_url, $firebase_token) {
        $this->backup_dir = __DIR__ . "/../backups";
        $this->firebase = new FirebaseLib($firebase_url, $firebase_token);
        $this->reset_backup_dir();
    }

    function do_backup() {
        $this->metadata = [];
        $this->getData('/');

        $metadataFile = fopen($this->backup_dir . '/metadata.json', 'w');
        fwrite($metadataFile, json_encode($this->metadata, JSON_PRETTY_PRINT));
        fclose($metadataFile);
    }

    private function reset_backup_dir() {
        if (!file_exists($this->backup_dir)) {
            mkdir($this->backup_dir);
        }
        array_map('unlink', glob($this->backup_dir . "/*"));
    }

    function getData($path) {
        $processedCount = 0;
        $firstKey = null;
        do {
            $pageData = $this->getPathsPaginated($path, $firstKey);
            $firstKey = $pageData['lastKey'];

            if (isset($pageData['error']) && $pageData['error'] === 'go-deeper') {
                $shallowTries = 0;
                if (!isset($this->shallowTree[$path])) {
                    do {
                        $shallowData = json_decode($this->firebase->get($path, ['shallow' => 'true']), true);
                        $shallowTries++;

                        if ($shallowTries === 10) {
                            error_log('Could not get database shallow data', E_USER_ERROR);
                            die;
                        }
                    } while (empty($shallowData));

                    $this->shallowTree[$path] = array_keys($shallowData);
                    $shallowData = null;
                    $shallowTries = null;
                }

                $nextKey = null;
                if ($firstKey) {
                    $firstProcessedIdx = array_search($firstKey, $this->shallowTree[$path]);
                    if (count($this->shallowTree[$path]) > ($firstProcessedIdx + 1)) {
                        $nextKey = $this->shallowTree[$path][$firstProcessedIdx + 1];
                    } else {
                        $pageData['isLastPage'] = true;
                    }
                } else {
                    $nextKey = $this->shallowTree[$path][0];
                }

                if ($nextKey) {
                    $keyPath = $path . '/' . $nextKey;
                    $this->getData($keyPath);
                    $firstKey = $nextKey;
                    $processedCount += 1;
                }
            } else {
                $partData = $pageData['data'];

                $this->generateFile($path, $partData);
                $processedCount += count($partData);
            }

            echo 'Processed ' . $processedCount . ' entries.' . PHP_EOL;
        } while (!$pageData['isLastPage']);

        $pageData = null;
        $firstKey = null;
    }

    /**
     * @param $path
     * @param $key
     * @param $itemsPerPage
     * @return mixed
     */
    private function getPathsPaginated($path, $key = null, $itemsPerPage = 1000) {
        if (!isset($this->intelligentIPP[$path])) {
            $this->intelligentIPP[$path] = ["ipp" => min($itemsPerPage, $this->maxIpp), "success" => 0];
        } else {
            $itemsPerPage = $this->intelligentIPP[$path]['ipp'];
        }
        $newItemsPerPage = null;
        $partData = null;

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

            $newItemsPerPage = max($this->minIpp, ceil($itemsPerPage / 2));
            $partData = json_decode($this->firebase->get($path, $query), true);
            if (isset($partData['error'])) {
                print_r($partData);
            }
            $partData = (isset($partData['error']) && $partData['error'] === 'Payload is too large') ? [] : $partData;

            if ($itemsPerPage === $this->minIpp) {
                return ['error' => 'go-deeper', 'lastKey' => $key];
            }
        } while(empty($partData));

        $this->intelligentIPP[$path]['success'] += 1;
        if ($this->intelligentIPP[$path]['success'] > 5) {
            $this->intelligentIPP[$path]['success'] = 0;
            $this->intelligentIPP[$path]['ipp'] = min($this->maxIpp, floor($itemsPerPage * 1.2));
        } else if ($itemsPerPage != $this->intelligentIPP[$path]['ipp']) {
            $this->intelligentIPP[$path]['success'] = 0;
            $this->intelligentIPP[$path]['ipp'] = $itemsPerPage;
        }

        $countData = count($partData);
        $lastKey = array_keys($partData)[($countData - 1)];
        $isLastPage = ($countData < $itemsPerPage || ($countData === 1 && $itemsPerPage === 1 && $lastKey === $key));

        if (!empty($key)) {
            array_shift($partData);
        }

        return ['data' => $partData, 'isLastPage' => $isLastPage, 'lastKey' => $lastKey];
    }

    private function generateFile($path, $data) {
        $successfully = false;
        $splitSize = 1;

        do {
            try {
                $chuckedData = array_chunk($data, (1000/$splitSize), true);
                for($i = 0; $i < count($chuckedData); $i++) {
                    $md5Pth = md5(uniqid(""));
                    $filePath = $this->backup_dir . "/${md5Pth}.json";
                    $this->metadata[$filePath] = $path;

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
            } catch (\Exception $e) {
                $splitSize++;
            }
        } while (!$successfully);
    }
}