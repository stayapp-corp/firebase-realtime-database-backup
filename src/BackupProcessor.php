<?php

namespace FRDBackup;

use Firebase\FirebaseLib;
use FRDBackup\Exceptions\BackupFailureException;

class BackupProcessor {

    private static $MIN_IPP = 2;

    private $firebase;
    private $metadata = [];
    private $intelligent_IPP = [];
    private $shallow_tree = [];
    private $max_ipp = 1000;
    private $temp_dir;
    private $backup_file;

    function __construct($firebase_url, $firebase_token, $temp_dir=null, $backup_file=null, $max_ipp=1000) {
        if (empty($temp_dir)) $temp_dir = __DIR__ . "/../temp";
        if (empty($backup_file)) {
            $project_id = explode(".", explode("//", $firebase_url)[1])[0];
            $backup_file = __DIR__ . "/../backups/" . $project_id . "-" . date(DATE_ATOM);
        }

        $this->temp_dir = $temp_dir;
        $this->backup_file = $backup_file;
        $this->max_ipp = $max_ipp;
        $this->firebase = new FirebaseLib($firebase_url, $firebase_token);
    }

    /**
     * @throws BackupFailureException
     */
    function do_backup() {
        $this->reset_backup_dir();
        $this->metadata = [];
        $this->getData('/');

        $metadataFile = fopen($this->temp_dir . '/metadata.json', 'w');
        fwrite($metadataFile, json_encode($this->metadata, JSON_PRETTY_PRINT));
        fclose($metadataFile);
        $this->generateCompressedBackup();
    }

    private function reset_backup_dir() {
        if (!file_exists($this->temp_dir)) {
            mkdir($this->temp_dir);
        }

        array_map('unlink', glob($this->temp_dir . "/*"));
    }

    /**
     * @param $path
     * @throws BackupFailureException
     */
    private function getData($path) {
        $processedCount = 0;
        $firstKey = null;
        $preserveLastKey = false;
        do {
            $pageData = $this->getPathsPaginated($path, $firstKey, $preserveLastKey);
            $firstKey = $pageData['lastKey'];
            $isLastPage = isset($pageData['isLastPage']) ? $pageData['isLastPage'] : false;
            $preserveLastKey = false;

            if (isset($pageData['error']) && $pageData['error'] === 'go-deeper') {
                if (!isset($this->shallow_tree[$path])) {
                    $shallowTries = 0;
                    do {
                        unset($shallowData);
                        $shallowData = json_decode($this->firebase->get($path, ['shallow' => 'true']), true);
                        $shallowTries++;
                        if ($shallowTries === 10) {
                            throw new BackupFailureException('Could not get database shallow data');
                        }
                    } while (empty($shallowData));

                    $this->shallow_tree[$path] = array_keys($shallowData);
                    sort($this->shallow_tree[$path]);
                    unset($shallowData);
                    unset($shallowTries);
                }

                if(!is_array($this->shallow_tree[$path]) || (count($this->shallow_tree[$path]) < 1 && $firstKey)) {
                    $isLastPage = true;
                } else {
                    $nextKeyIdx = null;
                    $shallowTreeCount = count($this->shallow_tree[$path]);

                    if ($firstKey) {
                        $firstProcessedIdx = array_search($firstKey, $this->shallow_tree[$path]);
                        if ($shallowTreeCount > ($firstProcessedIdx + 1)) {
                            $nextKeyIdx = $firstProcessedIdx + 1;
                        } else {
                            $isLastPage = true;
                        }
                    } else {
                        $nextKeyIdx = 0;
                    }

                    if ($nextKeyIdx !== null) {
                        $this->getData($path . '/' . $this->shallow_tree[$path][$nextKeyIdx]);
                        if ($shallowTreeCount > ($nextKeyIdx + 1)) {
                            $firstKey = $this->shallow_tree[$path][($nextKeyIdx+1)];
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
        if (!isset($this->intelligent_IPP[$path])) {
            $this->intelligent_IPP[$path] = ["ipp" => min($itemsPerPage, $this->max_ipp), "success" => 0];
        } else {
            $itemsPerPage = $this->intelligent_IPP[$path]['ipp'];
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

            $newItemsPerPage = max(self::$MIN_IPP, ceil($itemsPerPage / 2));
            $partData = json_decode($this->firebase->get($path, $query), true);
            $partData = (isset($partData['error']) && $partData['error'] === 'Payload is too large') ? [] : $partData;
            $refreshSmallerPiece = empty($partData);
            if ($itemsPerPage === self::$MIN_IPP && $refreshSmallerPiece) {
                return ['error' => 'go-deeper', 'lastKey' => $key];
            }
        } while($refreshSmallerPiece);

        $this->intelligent_IPP[$path]['success'] += 1;
        if ($this->intelligent_IPP[$path]['success'] > 5) {
            $this->intelligent_IPP[$path]['success'] = 0;
            $this->intelligent_IPP[$path]['ipp'] = min($this->max_ipp, ceil($itemsPerPage * 1.2));
        } else if ($itemsPerPage != $this->intelligent_IPP[$path]['ipp']) {
            $this->intelligent_IPP[$path]['success'] = 0;
            $this->intelligent_IPP[$path]['ipp'] = $itemsPerPage;
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
        $md5Pth = md5(uniqid(""));
        $filePath = $this->temp_dir . "/${md5Pth}.json";

        if (!isset($this->metadata[$path])) {
            $this->metadata[$path] = [];
        }

        $this->metadata[$path][] = $filePath;

        $file = fopen($filePath, 'w');
        $dataJson = json_encode($data);
        fwrite($file, $dataJson);
        fclose($file);

        unset($md5Pth);
        unset($filePath);
        unset($file);
        unset($dataJson);
        unset($data);
        unset($path);
    }

    private function generateCompressedBackup() {
        $backup_dir = dirname($this->backup_file);
        $file_name = preg_replace( '/[^a-zA-Z0-9]+/', '-', pathinfo($this->backup_file)['filename']);
        if (!file_exists($backup_dir)) {
            mkdir($backup_dir, 0777, true);
        }

        $tmp_backup_tar = $backup_dir . "/${file_name}.tar";
        $tarFile = new \PharData($tmp_backup_tar);
        $tarFile->buildFromDirectory($this->temp_dir);
        $tarFile->compress(\Phar::GZ);
        unset($tarFile);
        sleep(5);

        unlink($tmp_backup_tar);
        array_map('unlink', glob($this->temp_dir . "/*"));
        rmdir($this->temp_dir);
    }
}