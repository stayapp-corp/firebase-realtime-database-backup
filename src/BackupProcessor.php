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
        $preserveLastKey = false;
        do {
            $pageData = $this->getPathsPaginated($path, $firstKey, $preserveLastKey);
            $firstKey = $pageData['lastKey'];
            $isLastPage = isset($pageData['isLastPage']) ? $pageData['isLastPage'] : false;
            $preserveLastKey = false;

            if (isset($pageData['error']) && $pageData['error'] === 'go-deeper') {
                if (!isset($this->shallowTree[$path])) {
                    $shallowTries = 0;
                    do {
                        unset($shallowData);
                        $shallowData = json_decode($this->firebase->get($path, ['shallow' => 'true']), true);
                        $shallowTries++;
                        if ($shallowTries === 10) {
                            error_log('Could not get database shallow data', E_USER_ERROR);
                            die;
                        }
                    } while (empty($shallowData));

                    $this->shallowTree[$path] = array_keys($shallowData);
                    sort($this->shallowTree[$path]);
                    unset($shallowData);
                    unset($shallowTries);
                }

                if(!is_array($this->shallowTree[$path]) || (count($this->shallowTree[$path]) < 1 && $firstKey)) {
                    $isLastPage = true;
                } else {
                    $nextKeyIdx = null;
                    $shallowTreeCount = count($this->shallowTree[$path]);

                    if ($firstKey) {
                        $firstProcessedIdx = array_search($firstKey, $this->shallowTree[$path]);
                        if ($shallowTreeCount > ($firstProcessedIdx + 1)) {
                            $nextKeyIdx = $firstProcessedIdx + 1;
                        } else {
                            $isLastPage = true;
                        }
                    } else {
                        $nextKeyIdx = 0;
                    }

                    if ($nextKeyIdx !== null) {
                        $this->getData($path . '/' . $this->shallowTree[$path][$nextKeyIdx]);
                        if ($shallowTreeCount > ($nextKeyIdx + 1)) {
                            $firstKey = $this->shallowTree[$path][($nextKeyIdx+1)];
                            $preserveLastKey = true;
                        } else {
                            $isLastPage = true;
                        }
                        $processedCount += 1;
                    }

                    unset($nextKeyIdx);
                    unset($shallowTreeCount);
                }
            } else {
                $partData = $pageData['data'];
                $this->generateFile($path, $partData);
                $processedCount += count($partData);
            }

            echo 'Processed ' . $processedCount . ' entries.' . PHP_EOL;
            unset($pageData);
        } while (!$isLastPage);
        unset($processedCount);
        unset($firstKey);
        unset($preserveLastKey);
        unset($isLastPage);
    }

    /**
     * @param $path
     * @param $key
     * @param bool $preserveLastKey
     * @param int $itemsPerPage
     * @return mixed
     */
    private function getPathsPaginated($path, $key = null, $preserveLastKey = false, $itemsPerPage = 1000) {
        if (!isset($this->intelligentIPP[$path])) {
            $this->intelligentIPP[$path] = ["ipp" => min($itemsPerPage, $this->maxIpp), "success" => 0];
        } else {
            $itemsPerPage = $this->intelligentIPP[$path]['ipp'];
        }
        $newItemsPerPage = null;

        do {
            unset($partData);
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
            $partData = (isset($partData['error']) && $partData['error'] === 'Payload is too large') ? [] : $partData;
            $refreshSmallerPiece = empty($partData);
            if ($itemsPerPage === $this->minIpp && $refreshSmallerPiece) {
                return ['error' => 'go-deeper', 'lastKey' => $key];
            }
        } while($refreshSmallerPiece);

        $this->intelligentIPP[$path]['success'] += 1;
        if ($this->intelligentIPP[$path]['success'] > 5) {
            $this->intelligentIPP[$path]['success'] = 0;
            $this->intelligentIPP[$path]['ipp'] = min($this->maxIpp, ceil($itemsPerPage * 1.2));
        } else if ($itemsPerPage != $this->intelligentIPP[$path]['ipp']) {
            $this->intelligentIPP[$path]['success'] = 0;
            $this->intelligentIPP[$path]['ipp'] = $itemsPerPage;
        }

        $countData = count($partData);
        $lastKey = array_keys($partData)[($countData - 1)];
        $isLastPage = ($countData < $itemsPerPage || ($countData === 1 && $lastKey === $key));

        if (!empty($key) && $preserveLastKey !== true) {
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