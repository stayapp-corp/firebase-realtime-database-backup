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
        $getopts->addOption('temp_dir')
            ->short('t')
            ->long('temp_dir')
            ->argument('temp_dir')
            ->description('Path to write temporary files.')
            ->defaultValue(__DIR__ . "/../../temp");

        $getopts->addOption('max_ipp')
            ->short('i')
            ->long('max_ipp')
            ->argument('max_ipp')
            ->description('Max items per firebase path')
            ->defaultValue(1000);

        $getopts->addOption('output_file')
            ->short('o')
            ->long('output_file')
            ->argument('output_file')
            ->description('Path to save the compressed backup file.')
            ->defaultValue(__DIR__ . "/../../backups/BACKUP-" . date(DATE_ATOM));

        $getopts->addOption('root_start_ipp')
            ->short('r')
            ->long('root_start_ipp')
            ->argument('root_start_ipp')
            ->description('Start IPP of root ("/") path.')
            ->defaultValue(1000);

        return $getopts;
    }

    function command_execution($opts) {
        echo "Exporting " . $this->project_url . " realtime database..." . PHP_EOL;
        $backupProcessor = new BackupProcessor($this->project_url, $this->project_key, $opts['temp_dir'],
            $opts['output_file'], $opts['max_ipp']);

        try {
            $backupProcessor->do_backup($opts['root_start_ipp']);
        } catch (\Exception $e) {
            error_log($e->getMessage(), E_USER_ERROR);
        }
    }
}