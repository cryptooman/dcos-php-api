<?php
/*
Usage:
    php test_jobs.php --jobs=1000 --concurrent=100 [--debug=1 2>/tmp/debug.log] | tee /tmp/result.log

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
                "--jobs=<int> " .
                "--concurrent=<int> " .
                "[--tests=<1[,2,...]>] " .
                "[--container=<image>] " .
                "[--dcos-host=<str>] ".
                "[--auth-token-b64=<str>] ".
                "[--debug=<0|1>]\n";

    $args = getopt('', ['jobs:', 'concurrent:', 'tests::', 'container::', 'dcos-host::', 'auth-token-b64::', 'debug::']);
    if (!isset($args['jobs']) || !isset($args['concurrent'])) {
        exit($usage);
    }

    $jobsTotal              = (int) $args['jobs'];
    $concurrentJobsBulkSize = (int) $args['concurrent'];
    $runTests               = isset($args['tests']) ? array_flip(explode(',', $args['tests'])) : [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1];
    $containerImage         = isset($args['container']) ? $args['container'] : DEFAULT_CONTAINER_IMAGE;
    $dcosHost               = isset($args['dcos-host']) ? $args['dcos-host'] : getenv('DCOS_HOST');
    $authTokenBase64        = isset($args['auth-token-b64']) ? $args['auth-token-b64'] : getenv('DCOS_AUTH_TOKEN_BASE64');
    $debug                  = isset($args['debug']) ? (bool) $args['debug'] : \DcosPhpApi\Config::get('debug');

    if ($jobsTotal <= 0 || $concurrentJobsBulkSize <= 0 || $jobsTotal < $concurrentJobsBulkSize) {
        exit($usage);
    }
    else if (($jobsTotal % $concurrentJobsBulkSize) != 0) {
        exit("Jobs total must be a multiple of jobs concurrent\n");
    }

    \DcosPhpApi\Config::set('debug', $debug);

    echo "\n";
    echo date('Y-m-d H:i:s') . " DCOS host: $dcosHost\n";
    echo "\n";

    $dcos = new \DcosPhpApi\DcosApi($dcosHost, $authTokenBase64);

    echo date('Y-m-d H:i:s') . " Removing running test jobs\n";
    $dcos->getService()->removeJobsByTokenId(TEST_JOB_TOKEN_ID);

    $attempts = 30;
    while ($attempts--) {
        echo date('Y-m-d H:i:s') . " Checking for no stuck deployments ... [$attempts]\n";
        $jobsDeployments = $dcos->getService()->getJobsDeployment();
        if (!$jobsDeployments) {
            break;
        }
        sleep(1);
    }
    if ($jobsDeployments) {
        throw new \Exception("Jobs deployments found");
    }

    $attempts = 30;
    while ($attempts--) {
        echo date('Y-m-d H:i:s') . " Checking for no stuck queue ... [$attempts]\n";
        $jobsQueue = $dcos->getService()->getJobsQueue();
        if (!$jobsQueue) {
            break;
        }
        sleep(1);
    }
    if ($jobsQueue) {
        throw new \Exception("Jobs in queue found");
    }

    $testNum = 1;

    #
    # Test 1
    #

    if (isset($runTests[1])) {

        echo "\n";
        echo date('Y-m-d H:i:s') . " Test #1: Testing jobs [class_name] types\n";
        echo "\n";

        $testId = TEST_JOB_TOKEN_ID . ($testNum++);
        $jobId = $dcos->addJob(
            $testId,
            '<bash-command>',
            $containerImage, 0.1, 128, 4, $JOB_DEFAULT_CONTAINER_PATHS, false
        );
        $jobValidated = false;
        $attempts = VALIDATE_JOB_MAX_ATTEMPTS;
        while ($attempts--) {
            echo date('Y-m-d H:i:s') . " Validating job [$testId] ... [$attempts]\n";
            $job = $dcos->getJob($jobId);
            $stdout = $dcos->getJobOutput($jobId);
            if ($stdout) {
                $dcos->removeJob($jobId);
                $job = $dcos->getJob($jobId);
                if (!$job) {
                    $jobValidated = true;
                    break;
                }
            }
            sleep(VALIDATE_JOB_ATTEMPT_DELAY_SEC);
        }
        if (!$jobValidated) {
            throw new \Exception("Failed to validate job [$jobId]");
        }
        echo date('Y-m-d H:i:s') . " Job [$testId] validated\n";

        $testId = TEST_JOB_TOKEN_ID . ($testNum++);
        $jobId = $dcos->addJob(
            $testId,
            '<bash-command>',
            $containerImage, 0.1, 128, 4, $JOB_DEFAULT_CONTAINER_PATHS, false
        );
        $jobValidated = false;
        $attempts = VALIDATE_JOB_MAX_ATTEMPTS;
        while ($attempts--) {
            echo date('Y-m-d H:i:s') . " Validating job [$testId] ... [$attempts]\n";
            $job = $dcos->getJob($jobId);
            $stdout = $dcos->getJobOutput($jobId);
            if ($stdout) {
                $dcos->removeJob($jobId);
                $job = $dcos->getJob($jobId);
                if (!$job) {
                    $jobValidated = true;
                    break;
                }
            }
            sleep(VALIDATE_JOB_ATTEMPT_DELAY_SEC);
        }
        if (!$jobValidated) {
            throw new \Exception("Failed to validate job [$jobId]");
        }
        echo date('Y-m-d H:i:s') . " Job [$testId] validated\n";

    }

    #
    # Test 2
    #

    if (isset($runTests[2])) {

        echo "\n";
        echo date('Y-m-d H:i:s') . " Test #2: Testing jobs queue (cpu)\n";
        echo "\n";

        $jobsPerTest    = NODE_CPU_CORES_MAX * 2;
        $cpusPerJob     = 1;

        $jobIds = [];
        for ($j = 0; $j < $jobsPerTest; $j++) {
            $testId = TEST_JOB_TOKEN_ID . ($testNum++);
            $jobId = $dcos->addJob(
                $testId,
                '<bash-command>',
                $containerImage, $cpusPerJob, 128, 4, $JOB_DEFAULT_CONTAINER_PATHS, false
            );
            $jobIds[$jobId] = ['testId' => $testId, 'step' => 'in-progress'];
            echo date('Y-m-d H:i:s') . " Created job [$jobId]\n";
        }

        $jobsValidated = 0;

        $attempts = VALIDATE_JOB_MAX_ATTEMPTS;
        while ($attempts--) {
            echo date('Y-m-d H:i:s') . " Jobs validated [$jobsValidated / $jobsPerTest] ... [$attempts]\n";
            foreach ($jobIds as $jobId => &$jobParams) {
                if ($jobParams['step'] == 'validated') {
                    continue;
                }

                $job = $dcos->getJob($jobId);
                if ($job) {
                    if (!isset($job['id']) || $job['id'] != $jobId) {
                        throw new \Exception("Job has bad 'id' [{$job['id']} != $jobId]");
                    }
                    if ($job['status'] == \DcosPhpApi\JOB_STATUS_FAILED) {
                        throw new \Exception("Job [$jobId] has failed status");
                    }
                    if ($job['status'] == \DcosPhpApi\JOB_STATUS_SUCCESS) {
                        $stdout = $dcos->getJobOutput($jobId);
                        if (!$stdout) {
                            continue;   // Output can ve flushed to logs with delay
                        }
                        $dcos->removeJob($jobId);
                        $jobParams['step'] = 'removed';
                    }
                }

                if ($jobParams['step'] == 'removed') {
                    $job = $dcos->getJob($jobId);
                    if (!$job) {
                        $jobParams['step'] = 'validated';
                        $jobsValidated++;
                        echo date('Y-m-d H:i:s') . " Job [$jobId] validated\n";
                    }
                }
            }
            unset($jobParams);

            if ($jobsValidated == $jobsPerTest) {
                break;
            }
            sleep(VALIDATE_JOB_ATTEMPT_DELAY_SEC);
        }

        if ($jobsValidated != $jobsPerTest) {
            $failedJobs = [];
            foreach ($jobIds as $jobId => $job) {
                if ($job['step'] != 'validated') {
                    $failedJobs[] = $jobId;
                }
            }
            throw new \Exception("Failed to validate jobs [" . ($jobsPerTest - $jobsValidated) . "]\n" . print_r($failedJobs, 1));
        }

        echo date('Y-m-d H:i:s') . " Performing jobs checks\n";
        $dcos->getService()->checkJobs();
        $dcos->getService()->checkJobsDeployment();
        $dcos->getService()->checkJobsQueue();

        echo date('Y-m-d H:i:s') . " Total jobs validated [$jobsValidated / $jobsPerTest]\n";

    }

    #
    # Test 3
    #

    if (isset($runTests[3])) {

        echo "\n";
        echo date('Y-m-d H:i:s') . " Test #3: Testing jobs queue (mem)\n";
        echo "\n";

        $jobsPerTest    = 20;
        $memPerJob      = round(NODE_MEM_RAM_MBYTES_MAX / 10);

        $jobIds = [];
        for ($j = 0; $j < $jobsPerTest; $j++) {
            $testId = TEST_JOB_TOKEN_ID . ($testNum++);
            $jobId = $dcos->addJob(
                $testId,
                '<bash-command>',
                $containerImage, 0.1, $memPerJob, 4, $JOB_DEFAULT_CONTAINER_PATHS, false
            );
            $jobIds[$jobId] = ['testId' => $testId, 'step' => 'in-progress'];
            echo date('Y-m-d H:i:s') . " Created job [$jobId]\n";
        }

        $jobsValidated = 0;

        $attempts = VALIDATE_JOB_MAX_ATTEMPTS;
        while ($attempts--) {
            echo date('Y-m-d H:i:s') . " Jobs validated [$jobsValidated / $jobsPerTest] ... [$attempts]\n";
            foreach ($jobIds as $jobId => &$jobParams) {
                if ($jobParams['step'] == 'validated') {
                    continue;
                }

                $job = $dcos->getJob($jobId);
                if ($job) {
                    if (!isset($job['id']) || $job['id'] != $jobId) {
                        throw new \Exception("Job has bad 'id' [{$job['id']} != $jobId]");
                    }
                    if ($job['status'] == \DcosPhpApi\JOB_STATUS_FAILED) {
                        throw new \Exception("Job [$jobId] has failed status");
                    }
                    if ($job['status'] == \DcosPhpApi\JOB_STATUS_SUCCESS) {
                        $stdout = $dcos->getJobOutput($jobId);
                        if (!$stdout) {
                            continue;   // Output can ve flushed to logs with delay
                        }
                        $dcos->removeJob($jobId);
                        $jobParams['step'] = 'removed';
                    }
                }

                if ($jobParams['step'] == 'removed') {
                    $job = $dcos->getJob($jobId);
                    if (!$job) {
                        $jobParams['step'] = 'validated';
                        $jobsValidated++;
                        echo date('Y-m-d H:i:s') . " Job [$jobId] validated\n";
                    }
                }
            }
            unset($jobParams);

            if ($jobsValidated == $jobsPerTest) {
                break;
            }
            sleep(VALIDATE_JOB_ATTEMPT_DELAY_SEC);
        }

        if ($jobsValidated != $jobsPerTest) {
            $failedJobs = [];
            foreach ($jobIds as $jobId => $job) {
                if ($job['step'] != 'validated') {
                    $failedJobs[] = $jobId;
                }
            }
            throw new \Exception("Failed to validate jobs [" . ($jobsPerTest - $jobsValidated) . "]\n" . print_r($failedJobs, 1));
        }

        echo date('Y-m-d H:i:s') . " Performing jobs checks\n";
        $dcos->getService()->checkJobs();
        $dcos->getService()->checkJobsDeployment();
        $dcos->getService()->checkJobsQueue();

        echo date('Y-m-d H:i:s') . " Total jobs validated [$jobsValidated / $jobsPerTest]\n";

    }

    #
    # Test 4
    #

    if (isset($runTests[4])) {

        echo "\n";
        echo date('Y-m-d H:i:s') . " Test #4: Testing jobs with auto resources allocation (no queue)\n";
        echo "\n";

        $jobsValidatedTotal = 0;

        for ($i = 0; $i < ceil($jobsTotal / $concurrentJobsBulkSize); $i++) {

            $jobIds = [];
            for ($j = 0; $j < $concurrentJobsBulkSize; $j++) {
                $testId = TEST_JOB_TOKEN_ID . ($testNum++);
                $jobId = $dcos->addJob(
                    $testId,
                    '<bash-command>',
                    $containerImage, 0, 0, 0, $JOB_DEFAULT_CONTAINER_PATHS, false
                );
                $jobIds[$jobId] = ['testId' => $testId, 'step' => 'in-progress'];
                echo date('Y-m-d H:i:s') . " Created job [$jobId]\n";
            }

            $jobsBulkSize = count($jobIds);
            $jobsValidated = 0;

            $attempts = VALIDATE_JOB_MAX_ATTEMPTS;
            while ($attempts--) {
                echo date('Y-m-d H:i:s') . " Jobs validated [$jobsValidated / $jobsBulkSize] ... [$attempts]\n";
                foreach ($jobIds as $jobId => &$jobParams) {
                    if ($jobParams['step'] == 'validated') {
                        continue;
                    }

                    $job = $dcos->getJob($jobId);
                    if ($job) {
                        if (!isset($job['id']) || $job['id'] != $jobId) {
                            throw new \Exception("Job has bad 'id' [{$job['id']} != $jobId]");
                        }
                        if ($job['status'] == \DcosPhpApi\JOB_STATUS_FAILED) {
                            throw new \Exception("Job [$jobId] has failed status");
                        }
                        if ($job['status'] == \DcosPhpApi\JOB_STATUS_SUCCESS) {
                            $stdout = $dcos->getJobOutput($jobId);
                            if (!$stdout) {
                                continue;   // Output can ve flushed to logs with delay
                            }
                            $dcos->removeJob($jobId);
                            $jobParams['step'] = 'removed';
                        }
                    }

                    if ($jobParams['step'] == 'removed') {
                        $job = $dcos->getJob($jobId);
                        if (!$job) {
                            $jobParams['step'] = 'validated';
                            $jobsValidated++;
                            echo date('Y-m-d H:i:s') . " Job [$jobId] validated\n";
                        }
                    }
                }
                unset($jobParams);

                if ($jobsValidated == $jobsBulkSize) {
                    break;
                }
                sleep(VALIDATE_JOB_ATTEMPT_DELAY_SEC);
            }

            if ($jobsValidated != $jobsBulkSize) {
                $failedJobs = [];
                foreach ($jobIds as $jobId => $job) {
                    if ($job['step'] != 'validated') {
                        $failedJobs[] = $jobId;
                    }
                }
                throw new \Exception("Failed to validate jobs [" . ($jobsBulkSize - $jobsValidated) . "]\n" . print_r($failedJobs, 1));
            }

            echo date('Y-m-d H:i:s') . " Performing jobs checks\n";
            $dcos->getService()->checkJobs();
            $dcos->getService()->checkJobsDeployment();
            $dcos->getService()->checkJobsQueue();

            $jobsValidatedTotal += $jobsValidated;

            echo date('Y-m-d H:i:s') . " Total jobs validated [$jobsValidatedTotal / $jobsTotal]\n";
        }

        if ($jobsTotal != $jobsValidatedTotal) {
            throw new \Exception("Not all jobs were validated [$jobsValidatedTotal / $jobsTotal]");
        }

    }

    #
    # Test 5
    #

    if (isset($runTests[5])) {

        echo "\n";
        echo date('Y-m-d H:i:s') . " Test #5: Testing jobs with auto remove on complete\n";
        echo "\n";

        $jobsValidatedTotal = 0;

        for ($i = 0; $i < ceil($jobsTotal / $concurrentJobsBulkSize); $i++) {

            $jobIds = [];
            for ($j = 0; $j < $concurrentJobsBulkSize; $j++) {
                $testId = TEST_JOB_TOKEN_ID . ($testNum++);
                $jobId = $dcos->addJob(
                    $testId,
                    '<bash-command> && sleep 30',
                    $containerImage, 0.1, 128, 4, $JOB_DEFAULT_CONTAINER_PATHS, true
                );
                $jobIds[$jobId] = ['testId' => $testId, 'step' => 'in-progress'];
                echo date('Y-m-d H:i:s') . " Created job [$jobId]\n";
            }

            $jobsBulkSize = count($jobIds);
            $jobsValidated = 0;

            $attempts = VALIDATE_JOB_MAX_ATTEMPTS;
            while ($attempts--) {
                echo date('Y-m-d H:i:s') . " Jobs validated [$jobsValidated / $jobsBulkSize] ... [$attempts]\n";
                foreach ($jobIds as $jobId => &$jobParams) {
                    if ($jobParams['step'] == 'validated') {
                        continue;
                    }

                    $job = $dcos->getJob($jobId);
                    if ($job) {
                        if (!isset($job['id']) || $job['id'] != $jobId) {
                            throw new \Exception("Job has bad 'id' [{$job['id']} != $jobId]");
                        }
                        if ($job['status'] == \DcosPhpApi\JOB_STATUS_FAILED) {
                            throw new \Exception("Job [$jobId] has failed status");
                        }

                        // NOTE: Status "success" is not available until job command completely ended without errors
                        $stdout = $dcos->getJobOutput($jobId);
                        if (!$stdout) {
                            continue;   // Output can ve flushed to logs with delay
                        }
                        $jobParams['step'] = 'success';
                    }

                    if ($jobParams['step'] == 'success') {
                        $job = $dcos->getJob($jobId);
                        if (!$job) {
                            $jobParams['step'] = 'validated';
                            $jobsValidated++;
                            echo date('Y-m-d H:i:s') . " Job [$jobId] validated\n";
                        }
                    }
                }
                unset($jobParams);

                if ($jobsValidated == $jobsBulkSize) {
                    break;
                }
                sleep(VALIDATE_JOB_ATTEMPT_DELAY_SEC);
            }

            if ($jobsValidated != $jobsBulkSize) {
                $failedJobs = [];
                foreach ($jobIds as $jobId => $job) {
                    if ($job['step'] != 'validated') {
                        $failedJobs[] = $jobId;
                    }
                }
                throw new \Exception("Failed to validate jobs [" . ($jobsBulkSize - $jobsValidated) . "]\n" . print_r($failedJobs, 1));
            }

            echo date('Y-m-d H:i:s') . " Performing jobs checks\n";
            $dcos->getService()->checkJobs();
            $dcos->getService()->checkJobsDeployment();
            $dcos->getService()->checkJobsQueue();

            $jobsValidatedTotal += $jobsValidated;

            echo date('Y-m-d H:i:s') . " Total jobs validated [$jobsValidatedTotal / $jobsTotal]\n";
        }

        if ($jobsTotal != $jobsValidatedTotal) {
            throw new \Exception("Not all jobs were validated [$jobsValidatedTotal / $jobsTotal]");
        }

    }

    #
    # Test 6
    #

    if (isset($runTests[6])) {

        echo "\n";
        echo date('Y-m-d H:i:s') . " Test #6: Testing jobs with manual remove on complete\n";
        echo "\n";

        $jobsValidatedTotal = 0;

        for ($i = 0; $i < ceil($jobsTotal / $concurrentJobsBulkSize); $i++) {

            $jobIds = [];
            for ($j = 0; $j < $concurrentJobsBulkSize; $j++) {
                $testId = TEST_JOB_TOKEN_ID . ($testNum++);
                $jobId = $dcos->addJob(
                    $testId,
                    '<bash-command>',
                    $containerImage, 0.1, 128, 4, $JOB_DEFAULT_CONTAINER_PATHS, false
                );
                $jobIds[$jobId] = ['testId' => $testId, 'step' => 'in-progress'];
                echo date('Y-m-d H:i:s') . " Created job [$jobId]\n";
            }

            $jobsBulkSize = count($jobIds);
            $jobsValidated = 0;

            $attempts = VALIDATE_JOB_MAX_ATTEMPTS;
            while ($attempts--) {
                echo date('Y-m-d H:i:s') . " Jobs validated [$jobsValidated / $jobsBulkSize] ... [$attempts]\n";
                foreach ($jobIds as $jobId => &$jobParams) {
                    if ($jobParams['step'] == 'validated') {
                        continue;
                    }

                    $job = $dcos->getJob($jobId);
                    if ($job) {
                        if (!isset($job['id']) || $job['id'] != $jobId) {
                            throw new \Exception("Job has bad 'id' [{$job['id']} != $jobId]");
                        }
                        if ($job['status'] == \DcosPhpApi\JOB_STATUS_FAILED) {
                            throw new \Exception("Job [$jobId] has failed status");
                        }
                        if ($job['status'] == \DcosPhpApi\JOB_STATUS_SUCCESS) {
                            $stdout = $dcos->getJobOutput($jobId);
                            if (!$stdout) {
                                continue;   // Output can ve flushed to logs with delay
                            }
                            $dcos->removeJob($jobId);
                            $jobParams['step'] = 'removed';
                        }
                    }

                    if ($jobParams['step'] == 'removed') {
                        $job = $dcos->getJob($jobId);
                        if (!$job) {
                            $jobParams['step'] = 'validated';
                            $jobsValidated++;
                            echo date('Y-m-d H:i:s') . " Job [$jobId] validated\n";
                        }
                    }
                }
                unset($jobParams);

                if ($jobsValidated == $jobsBulkSize) {
                    break;
                }
                sleep(VALIDATE_JOB_ATTEMPT_DELAY_SEC);
            }

            if ($jobsValidated != $jobsBulkSize) {
                $failedJobs = [];
                foreach ($jobIds as $jobId => $job) {
                    if ($job['step'] != 'validated') {
                        $failedJobs[] = $jobId;
                    }
                }
                throw new \Exception("Failed to validate jobs [" . ($jobsBulkSize - $jobsValidated) . "]\n" . print_r($failedJobs, 1));
            }

            echo date('Y-m-d H:i:s') . " Performing jobs checks\n";
            $dcos->getService()->checkJobs();
            $dcos->getService()->checkJobsDeployment();
            $dcos->getService()->checkJobsQueue();

            $jobsValidatedTotal += $jobsValidated;

            echo date('Y-m-d H:i:s') . " Total jobs validated [$jobsValidatedTotal / $jobsTotal]\n";
        }

        if ($jobsTotal != $jobsValidatedTotal) {
            throw new \Exception("Not all jobs were validated [$jobsValidatedTotal / $jobsTotal]");
        }

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
