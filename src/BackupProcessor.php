<?php

namespace FRDBackup;

use Firebase\FirebaseLib;

class BackupProcessor {

    private $firebase;
    private $metadata;

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

    private function getData($path) {
        $data = json_decode($this->firebase->get($path), true);
        $data = (isset($data['error']) && $data['error'] === 'Payload is too large') ? [] : $data;

        if (empty($data)) {
            do {
                $data = json_decode($this->firebase->get($path, ['shallow' => 'true']), true);
                $data = (isset($data['error']) && $data['error'] === 'Payload is too large') ? [] : $data;
            } while(empty($data));

            $this->getDataChucked($path, $data, 1000);
        } else {
            $this->generateFile($path, $data);
        }

        $data = null;
    }

    private function getDataChucked($path, &$data, $size) {
        $size = $size > count($data) ? count($data) : $size;
        $chuckedArray = array_chunk($data, $size, true);
        $data = null;

        for($i = 0; $i < count($chuckedArray); $i++){
            $chucked = $chuckedArray[$i];
            $chuckedArray[$i] = null;
            $keys = array_keys($chucked);
            $partData = $this->getPaths($path, $keys);

            if (empty($partData)) {
                $chuckedSize = ($size > 100) ? 100 : ($size > 10) ? 10 : 1;
                if ($chuckedSize === 1) {
                    foreach ($keys as $key) {
                        $keyPath = $path . '/' . $key;
                        $this->getData($keyPath);
                    }
                    $keys = null;
                } else {
                    $this->getDataChucked($path, $chucked, $chuckedSize);
                }
            } else {
                $this->generateFile($path, $partData);
            }

            $partData = null;
        }

        $chuckedArray = null;
    }

    private function getPaths($path, $keys) {
        $query = [
            'orderBy' => '"$key"',
            'startAt' => '"' . $keys[0] . '"',
            'endAt' => '"' . $keys[(count($keys) - 1)] . '"'
        ];

        $partData = json_decode($this->firebase->get($path, $query), true);
        $partData = (isset($partData['error']) && $partData['error'] === 'Payload is too large') ? [] : $partData;
        return $partData;
    }

    private function generateFile($path, $data) {
        $successfully = false;
        $splitSize = 1;

        do {
            try {
                $chuckedData = array_chunk($data, (1000/$splitSize));
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
            } catch (Exception $e) {
                $splitSize++;
            }
        } while (!$successfully);
    }
}