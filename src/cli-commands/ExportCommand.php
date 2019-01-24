<?php

namespace FRDBackup\CliCommands;

use FRDBackup\BackupProcessor;

class ExportCommand extends AbstractCommand {

    protected $command = 'export';
    protected $soft_description = "export: This command exports (creates a backup) of the database.";

    function before_execute() {}
    function after_execute() {}

    protected function command_opts() {
        $getopts = parent::command_opts();

        // Here you can extend command parameters...

        return $getopts;
    }

    function command_execution($opts) {
        echo "Exporting " . $this->project_url . " reatime database..." . PHP_EOL;
        $backupProcessor = new BackupProcessor($this->project_url, $this->project_key);
        $backupProcessor->do_backup();
    }
}