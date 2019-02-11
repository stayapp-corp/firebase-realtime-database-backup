<?php
/**
 * User: Geovany
 * Date: 28/01/2019
 * Time: 14:06
 */

namespace FRDBackup;

use Firebase\FirebaseLib;
use FRDBackup\Exceptions\RestoreFailureException;

class RestoreProcessor
{

    private $firebase;
    private $max_ipp = 1000;

    /**
     * RestoreProcessor constructor.
     * @param $firebase_url
     * @param $firebase_token
     * @param $backup_file
     * @param null $temp_dir
     * @throws RestoreFailureException
     */
    function __construct($firebase_url, $firebase_token, $backup_file, $temp_dir=null) {
        if (empty($temp_dir)) $temp_dir = __DIR__ . "/../temp";
        $this->temp_dir = $temp_dir;
        $this->backup_file = $backup_file;

        $this->firebase = new FirebaseLib($firebase_url, $firebase_token);
        if (!file_exists($backup_file)) {
            throw new RestoreFailureException('File not exists.');
        }
    }

    private function reset_backup_dir() {
        if (!file_exists($this->temp_dir)) {
            mkdir($this->temp_dir);
        }
        array_map('unlink', glob($this->temp_dir . "/*"));
    }

    private function decompressedBackup() {
        $gzFile = new \PharData($this->backup_file);
        $tarFile = $gzFile->decompress(\Phar::GZ);
        $tarFile->extractTo($this->temp_dir);
        $path = $tarFile->getPath();
        unset($gzFile);
        unset($tarFile);
        sleep(5);
        unlink($path);
    }

    function do_restore() {
        $this->reset_backup_dir();
        $this->decompressedBackup();

        $metadata = json_decode(file_get_contents($this->temp_dir . '/metadata.json'), true);

        foreach ($metadata as $pathFb => $paths) {
            foreach ($paths as $path) {
                $data = json_decode(file_get_contents($this->temp_dir . "/" . $path), true);
                $this->save_path($pathFb, $data);
            }
        }
    }

    private function save_path($pathFb, &$data, $itemsPerPage = 1000) {
        $newItemsPerPage = null;
        $countSuccess = 0;

        do {
            $itemsPerPage = min($itemsPerPage, count($data));
            $itemsPerPage = ($newItemsPerPage ? $newItemsPerPage : $itemsPerPage);
            $splitData = array_slice($data, 0, $itemsPerPage, true);

            echo 'Restoring ' . $pathFb . ' firebase path by step ' . $itemsPerPage . PHP_EOL;
            $result = $this->firebase->update($pathFb, $splitData);

            if ($result === false) {
                $newItemsPerPage = max(1, ceil($itemsPerPage / 2));
                $countSuccess = 0;

                if ($itemsPerPage === 1) {
                    echo 'Error updating firebase path ' . $pathFb . ' deeper.' . PHP_EOL;

                    $keys = array_keys($splitData);
                    foreach ($keys as $key) {
                        $this->save_path(($pathFb . '/' . $key), $splitData[$key]);
                    }
                    $result = true;
                }
            }

            if ($result !== false) {
                $countSuccess++;
                $data = array_diff_key($data, $splitData);
                if ($countSuccess === 5) {
                    $countSuccess = 0;
                    $newItemsPerPage = min($this->max_ipp, ceil($itemsPerPage * 1.2));
                }
            }
        } while(!empty($data));
    }
}
