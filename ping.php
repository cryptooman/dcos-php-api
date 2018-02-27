<?php
/*
Usage:
    php test_jobs.php --jobs=100 --concurrent=10 [--debug=1 2>/tmp/debug.log] | tee /tmp/result.log

Tested on dcos version 1.8.8
*/

error_reporting(E_ALL);
ini_set('error_log', '/var/log/dcos_php_api_errors.log');
set_time_limit(0);

set_error_handler(function($errno, $errstr, $errfile, $errline, array $errcontext) {
    throw new \Exception("[$errno] $errstr at $errfile:$errline");
}, $error_types = E_ALL);

define('PC_BASE_DIR', dirname(__FILE__));
require(PC_BASE_DIR . '/src/Config.php');
require(PC_BASE_DIR . '/src/Debug.php');
require(PC_BASE_DIR . '/src/Error.php');
require(PC_BASE_DIR . '/src/HttpRequest.php');
require(PC_BASE_DIR . '/src/HttpRequestException.php');
require(PC_BASE_DIR . '/src/ServiceInterfaceApi.php');
require(PC_BASE_DIR . '/src/ServiceAbstractApi.php');
require(PC_BASE_DIR . '/src/ServiceMarathonApi.php');
require(PC_BASE_DIR . '/src/DcosApi.php');
require(PC_BASE_DIR . '/src/DcosApiException.php');

const DEFAULT_CONTAINER_IMAGE           = '<docker-registry>/<docker-image>';
const VALIDATE_JOB_MAX_ATTEMPTS         = 200;
const VALIDATE_JOB_ATTEMPT_DELAY_SEC    = 1;
const TEST_JOB_TOKEN_ID                 = 'test-job-dcos-api-';
const NODE_CPU_CORES_MAX                = 8;
const NODE_MEM_RAM_MBYTES_MAX           = 1024 * 32;

try {
    $timeStart = microtime(true);

    \DcosPhpApi\Config::set('appMode', \DcosPhpApi\APP_MODE_DEV);

    $usage = "Usage: {$argv[0]} " .
        "[--dcos-host=<str>] " .
        "[--auth-token-b64=<str>]\n";

    $args = getopt('', ['dcos-host::', 'auth-token-b64::']);

    $dcosHost = isset($args['dcos-host']) ? $args['dcos-host'] : getenv('DCOS_HOST');
    $authTokenBase64 = isset($args['auth-token-b64']) ? $args['auth-token-b64'] : getenv('DCOS_AUTH_TOKEN_BASE64');

    echo date('Y-m-d H:i:s') . " DCOS host: $dcosHost\n";

    $dcos = new \DcosPhpApi\DcosApi($dcosHost, $authTokenBase64);
    while (1) {
        $dcos->getJob('__');
        echo date('Y-m-d H:i:s') . " success\n";
        sleep(1);
    }
}
catch (Exception $e) {
    $error = (new \DcosPhpApi\Error)->log($e->getMessage() . "\n" . $e->getTraceAsString());
    if (!\DcosPhpApi\Config::get('debug')) {
        echo $error;
    }
}