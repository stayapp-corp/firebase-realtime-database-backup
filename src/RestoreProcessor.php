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
        unlink($gzFile->getPath());
    }

    function do_restore() {
        $this->reset_backup_dir();
        $this->decompressedBackup();

        $metadata = json_decode(file_get_contents($this->temp_dir . '/metadata.json'), true);

        foreach ($metadata as $pathFb => $paths) {
            echo 'Restoring ' . $pathFb . ' firebase path';
            foreach ($paths as $path) {
                $data = json_decode(file_get_contents("backup/${$path}.json"), true);
                $this->firebase->update($pathFb, $data);
            }
        }
    }
}