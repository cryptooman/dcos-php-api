<?php
/*
Usage:
    php scripts/check_jobs.php --type=jobs        --dcos-host=<str> --auth-token-b64=<str>
    php scripts/check_jobs.php --type=deployments --dcos-host=<str> --auth-token-b64=<str>
    php scripts/check_jobs.php --type=queue       --dcos-host=<str> --auth-token-b64=<str>
*/

error_reporting(E_ALL);
ini_set('error_log', '/var/log/dcos_php_api_errors.log');
set_time_limit(0);

set_error_handler(function($errno, $errstr, $errfile, $errline, array $errcontext) {
    throw new \Exception("[$errno] $errstr at $errfile:$errline");
}, $error_types = E_ALL);

define('PC_BASE_DIR', dirname(__FILE__) . '/..');
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

try {
    $timeStart = microtime(true);

    $usage = "Usage: {$argv[0]} --type=<jobs|deployments|queue> [--dcos-host=<str>] [--auth-token-b64=<str>]\n";

    $args = getopt('', ['type:', 'dcos-host::', 'auth-token-b64::']);
    if (!isset($args['type'])) {
        exit($usage);
    }

    $checkType          = $args['type'];
    $dcosHost           = isset($args['dcos-host']) ? $args['dcos-host'] : getenv('DCOS_HOST');
    $authTokenBase64    = isset($args['auth-token-b64']) ? $args['auth-token-b64'] : getenv('DCOS_AUTH_TOKEN_BASE64');

    $dcos = new \DcosPhpApi\DcosApi($dcosHost, $authTokenBase64);

    if ($checkType == 'jobs') {
        echo date('Y-m-d H:i:s') . " Performing jobs checks\n";
        $dcos->getService()->checkJobs();
    }
    else if ($checkType == 'deployments') {
        echo date('Y-m-d H:i:s') . " Performing deployments checks\n";
        $dcos->getService()->checkJobsDeployment();
    }
    else if ($checkType == 'queue') {
        echo date('Y-m-d H:i:s') . " Performing queue checks\n";
        $dcos->getService()->checkJobsQueue();
    }
    else {
        throw new \Exception("Unknown check type [$checkType]");
    }

    echo date('Y-m-d H:i:s') . " All done\n";
    echo date('Y-m-d H:i:s') . " Time taken: " . sprintf('%0.2f', microtime(true) - $timeStart) . "\n";
}
catch (Exception $e) {
    $error = (new \DcosPhpApi\Error)->log($e->getMessage() . "\n" . $e->getTraceAsString());
    if (!\DcosPhpApi\Config::get('debug')) {
        echo $error;
    }
}
