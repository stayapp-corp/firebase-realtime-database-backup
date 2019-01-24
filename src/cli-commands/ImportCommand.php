<?php

namespace FRDBackup\CliCommands;

class ImportCommand extends AbstractCommand {

    protected $command = 'import';
    protected $soft_description = "import: This command imports (restores) a exported backup into the database.";

    function before_execute() {}
    function after_execute() {}

    function command_execution($opts) {
        echo "Executando comando..." . PHP_EOL;

    }
}