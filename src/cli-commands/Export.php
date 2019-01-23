<?php

namespace FRDBackup\CliCommands;

abstract class ExportCommand extends AbstractCommand {
    
    function command_execution($opts) {
        echo "Executando comando..." . PHP_EOL;
        
    }
}