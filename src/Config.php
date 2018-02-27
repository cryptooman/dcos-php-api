<?php

namespace DcosPhpApi;

const APP_MODE_PROD                 = 'prod';
const APP_MODE_DEV                  = 'dev';

const JOB_DEFAULT_CPUS              = 1;
const JOB_DEFAULT_MEM               = 32;
const JOB_DEFAULT_DISK              = 0;
// NOTE: PHP5 version only: On change manually update this value where it used
$JOB_DEFAULT_CONTAINER_PATHS        = [['/tmp/', '/tmp/']]; // Container path <-> Host path

const JOB_STATUS_QUEUED             = 'queued';
const JOB_STATUS_RUNNING            = 'running';
const JOB_STATUS_SUCCESS            = 'success';
const JOB_STATUS_FAILED             = 'failed';

const JOB_OUTPUT_STDOUT             = 'stdout';
const JOB_OUTPUT_STDERR             = 'stderr';

define('DCOS_PHP_API_CONFIG_BASE_DIR', dirname(__FILE__));
class Config
{
    // Config options
    static private $params = [
        'appMode'           => APP_MODE_PROD,
        'errorsLogFile'     => '/var/log/dcos_php_api_errors.log',
        'debug'             => false,
    ];

    static private $inited = false;

    static public function init()
    {
        self::$params['appMode'] = getenv('APPLICATION_ENV') == 'test' ? APP_MODE_DEV : APP_MODE_PROD;
        self::$params['debug'] = getenv('DCOS_PHP_API_DEBUG') ? true : false;

        self::$inited = true;
    }

    static public function get($name)
    {
        if (!self::$inited) {
            self::init();
        }
        if (!isset(self::$params[$name])) {
            throw new \Exception("Undefined config param [$name]");
        }
        return self::$params[$name];
    }

    static public function set($name, $value)
    {
        if (!self::$inited) {
            self::init();
        }
        self::$params[$name] = $value;
    }
}
