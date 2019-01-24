<?php

namespace FRDBackup\CliCommands;

use HaydenPierce\ClassFinder\ClassFinder;
use ReflectionClass;
use Fostam\GetOpts\Handler;

abstract class AbstractCommand {

    static $PROJECT_URL_PATTERN = 'https://%s.firebaseio.com';

    protected $command;
    protected $soft_description;

    protected $project_id;
    protected $project_url;
    protected $project_key;

    abstract function before_execute();
    abstract function after_execute();
    abstract function command_execution($opts);

    static function help() {
        $help =  '------------ Firebase Realtime Database Backup Tool ------------' . PHP_EOL .
            '- Available commands:' . PHP_EOL;

        foreach(AbstractCommand::get_available_commands() as $command => $class) {
            $command_instance = new $class();
            $help .= '--> ' . $command_instance->soft_description . PHP_EOL;
        }

        $help .=  '------------------------------------------------------------------' . PHP_EOL . PHP_EOL;
        return $help;
    }

    private static function get_command_classes() {
        try {
            $command_classes = ClassFinder::getClassesInNamespace(__NAMESPACE__);
        } catch (\Exception $e) {
            $command_classes = [];
        }

        return $command_classes;
    }

    static function get_available_commands() {
        $command_classes = AbstractCommand::get_command_classes();
        $available_commands = [];
        foreach($command_classes as $class) {
            $reflection_class = null;
            try {
                $reflection_class = new ReflectionClass($class);
            } catch (\Exception $e) {
                continue;
            }

            if ($reflection_class && $reflection_class->isAbstract()) {
                continue;
            }

            $command_instance = $reflection_class->newInstance();
            if (!is_subclass_of($command_instance, AbstractCommand::class)) {
                continue;
            }

            $class_command = $reflection_class->getDefaultProperties()['command'];
            if ($class_command) {
                $available_commands[$class_command] = $class;
            }
        }

        return $available_commands;
    }

    private function get_opts() {
        global $argv;
        $pargs = $argv;
        array_splice($pargs, 1, 1);

        $getopts = $this->command_opts();
        $getopts->parse($pargs);
        $options = $getopts->get();

        $this->project_id = isset($options['project_id']) ? $options['project_id'] : null;
        $this->project_key = isset($options['project_key']) ? $options['project_key'] : null;
        if(!$this->project_id || !$this->project_key) {
            die('Your have not specified project_id or project_key' . PHP_EOL);
        }

        $this->project_url = sprintf(AbstractCommand::$PROJECT_URL_PATTERN, $this->project_id);
        return $options;
    }
    
    protected function command_opts() {
        $getopts = new Handler();
        $not_empty_validator = function($value) { return !empty($value); };
        $getopts->addOption('project_id')
            ->short('p')
            ->long('project_id')
            ->argument('project_id')
            ->description('Firebase project ID')
            ->validator($not_empty_validator)
            ->required();
        $getopts->addOption('project_key')
            ->short('k')
            ->long('project_key')
            ->argument('project_key')
            ->description('Firebase private key to access real time database using rest api.')
            ->validator($not_empty_validator)
            ->required();

        return $getopts;
    }

    public function execute() {
        $this->before_execute();
        $this->command_execution($this->get_opts());
        $this->after_execute();
    }
}