<?php

namespace FRDBackup\CliCommands;

abstract class Options {
    const REQUIRED = 'REQUIRED';
    const NOT_REQUIRED = 'NOT_REQUIRED';
}

abstract class AbstractCommand {

    static $PROJECT_URL_PATTERN = 'https://%s.firebaseio.com';

    protected $project_id;
    protected $project_url;
    protected $project_key;

    abstract function before_execute();
    abstract function after_execute();
    abstract function command_execution($opts);

    static function help() {
        echo "------------ Firebase Realtime Database Backup Tool ------------" . PHP_EOL .
            "- Available commands:" . PHP_EOL .
            "--> export: This command exports (creates a backup) of the database." . PHP_EOL .
            "--> import: This command imports (restores) a exported backup into the database." . PHP_EOL .
            "-----------------------------------------------------------------" . PHP_EOL;
    }

    protected function get_parameters() {
        return ['s_opts' => [], 'l_opts' => []];
    }
    
    private function get_opts() {
        $default_parameters = ['project_id:' => Options::REQUIRED, 'project_key:' => Options::REQUIRED];
        $command_parameters = $this->get_parameters();
        $s_opts = is_array($command_parameters['s_opts']) ? array_keys($command_parameters['s_opts']) : null;
        $l_opts = is_array($command_parameters['l_opts']) ? array_keys($command_parameters['l_opts']) : [];
        $l_opts = array_merge(array_keys($default_parameters), $l_opts);

        $all_opts = array_merge($command_parameters['s_opts'], $command_parameters['l_opts'], $default_parameters);
        $options = getopt($s_opts, $l_opts);
        
        // Validate required options
        foreach(array_merge($s_opts, $l_opts) as $opt) {
            $opt_key = str_replace(':', '', $opt);
            if(!isset($options[$opt_key]) && $all_opts[$opt] == Options::REQUIRED) {
                die('Required option: \'' . $opt_key . '\' was not informed');
            }
        }
        
        $this->project_id = $options['project_id'];
        $this->project_key = $options['project_key'];
        $this->project_url = sprintf(AbstractCommand::$PROJECT_URL_PATTERN, $this->project_id);
        return $options;
    }

    public function execute() {
        $this->before_execute();
        $this->command_execution($this->get_opts());
        $this->after_execute();
    }
}