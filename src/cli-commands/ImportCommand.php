<?php

namespace FRDBackup\CliCommands;

use FRDBackup\Exceptions\RestoreFailureException;
use FRDBackup\RestoreProcessor;

class ImportCommand extends AbstractCommand {

    protected $command = 'import';
    protected $soft_description = "import: This command imports (restores) a exported backup into the database.";

    function before_execute() {}
    function after_execute() {}

    protected function command_opts() {
        $getopts = parent::command_opts();
        $not_empty_validator = function($value) { return !empty($value); };

        // Here you can extend command parameters...
        $getopts->addOption('backup_file')
            ->short('f')
            ->long('backup_file')
            ->argument('backup_file')
            ->description('Backup file path.')
            ->validator($not_empty_validator)
            ->required();

        return $getopts;
    }

    function command_execution($opts) {
        echo "\033[1;33m------------------------- WARNING -------------------------" . PHP_EOL .
            "- Be sure that you have un-deploy your cloud functions    -" . PHP_EOL .
            "- before import database.                                 -" . PHP_EOL .
            "- Otherwise your functions will be triggered.             -" . PHP_EOL .
            "-----------------------------------------------------------\033[0m" . PHP_EOL;

        echo 'Do you want to proceed? (yes/no) ';
        $line = trim(substr(fgets(STDIN), 0, (PHP_OS == 'WINNT' ? 4 : 3)));

        if ($line !== 'yes') {
            die;
        }

        echo "Importing " . $this->project_url . " realtime database..." . PHP_EOL;
        try {
            $restoreProcessor = new RestoreProcessor($this->project_url, $this->project_key, $opts['backup_file']);
            $restoreProcessor->do_restore();
        } catch (RestoreFailureException $e) {
            error_log($e->getMessage(), E_USER_ERROR);
        }
    }
}