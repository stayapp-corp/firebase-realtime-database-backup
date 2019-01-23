<?php

function decamelize($string) {
    return strtolower(preg_replace(['/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'], '$1_$2', $string));
}

spl_autoload_register(function ($class) {
    $file = explode('\\', $class);
    foreach($file as $i => $file_part) {
        $file[$i] = ($i+1) >= count($file) ? $file_part : str_replace('_', '-', decamelize($file_part));
        if($file[$i] === 'frd-backup') {
            $file[$i] = 'src';
        }
    }

    $file_path = implode(DIRECTORY_SEPARATOR, $file).'.php';
    if (file_exists($file_path)) {
        require $file_path;
        return true;
    }
    return false;
});