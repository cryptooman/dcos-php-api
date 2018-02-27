<?php

namespace DcosPhpApi;

class ServiceMarathonApi extends ServiceAbstractApi implements ServiceInterfaceApi
{
    const REQUEST_BASE_PATH     = '/service/marathon/v2';
    const JOB_COMPLETED_LABEL   = 'JOB_COMPLETED__JOB_ID[[%jobId%]]__STATUS[[%status%]]__TS[[%timestamp%]]';

    function __construct($dcosHost, $authToken)
    {
        parent::__construct($dcosHost, $authToken);

        $this->mapGetJob = [
            'id'            => function($job) {
                                if (!isset($job['id']) || !$job['id']) {
                                    throw new DcosApiException("Invalid job 'id' [" . print_r($job, 1) . "]", self::E_INVALID_JOB_DATA);
                                }
                                return substr($job['id'], 1); // Cut first "/"
                            },

            'cmd'           => 'cmd',

            'timeCreated'   => function($job) {
                                if (!isset($job['version']) || !$job['version'] || !$jobStartTs = strtotime($job['version'])) {
                                    throw new DcosApiException("Invalid job 'version' [" . print_r($job, 1) . "]", self::E_INVALID_JOB_DATA);
                                }
                                return strftime('%Y-%m-%d %H:%M:%S', $jobStartTs);
                            },

            'status'        => function($job) {
                                if (
                                    !isset($job['tasksRunning']) ||
                                    !isset($job['tasksUnhealthy']) ||
                                    !isset($job['deployments'])
                                ) {
                                    throw new DcosApiException("Invalid job data [" . print_r($job, 1) . "]", self::E_INVALID_JOB_DATA);
                                }

                                $jobId = $this->mapGetJob['id']($job);
                                $jobStderr = $this->getJobOutput($jobId, JOB_OUTPUT_STDERR);

                                $jobCompletedLabel = preg_quote(self::JOB_COMPLETED_LABEL);
                                $jobCompletedLabel = str_replace(
                                    ['%jobId%', '%status%', '%timestamp%'],
                                    [preg_quote($jobId), '(?<status>(' . JOB_STATUS_SUCCESS . ')|(' . JOB_STATUS_FAILED . '))', '\d{10}'],
                                    $jobCompletedLabel
                                );

                                if ($jobStderr && preg_match('![^\S]?' . $jobCompletedLabel . '[^\S]?!', $jobStderr, $match)) {
                                    return $match['status'];
                                }
                                if ($job['tasksUnhealthy'] || (isset($job['lastTaskFailure']) && $job['lastTaskFailure'])) {
                                    return JOB_STATUS_FAILED;
                                }
                                if ($job['tasksRunning']) {
                                    return JOB_STATUS_RUNNING;
                                }

                                return JOB_STATUS_QUEUED;
                            },
        ];

        $this->mapGetJobsList = [
            'id'            => $this->mapGetJob['id'],
            'cmd'           => $this->mapGetJob['cmd'],
            'timeCreated'   => $this->mapGetJob['timeCreated'],
        ];
    }

    public function getJobsList()
    {
        $response = $this->request(self::REQUEST_BASE_PATH . '/apps', HttpRequest::HTTP_GET);
        if ($response['code'] == self::RES_CODE_200 && isset($response['content']['apps'])) {
            return $response['content']['apps'];
        }
        throw new DcosApiException('Failed to get jobs list', self::E_FAILED_REQUEST);
    }

    public function getJob($jobId)
    {
        $response = $this->request(self::REQUEST_BASE_PATH . "/apps/$jobId", HttpRequest::HTTP_GET);
        if ($response['code'] == self::RES_CODE_200 && isset($response['content']['app'])) {
            return $response['content']['app'];
        }
        if ($response['code'] == self::RES_CODE_404) {
            return [];
        }
        throw new DcosApiException("Failed to get job [$jobId]", self::E_FAILED_REQUEST);
    }

    public function getJobOutput($jobId, $outputType = JOB_OUTPUT_STDOUT)
    {
        $job = $this->getJob($jobId);
        if (!$job) {
            return '';
        }
        if (!isset($job['tasks']) || !$job['tasks']) {
            return '';
        }

        // Staging (deploying) tasks are not expected to be if one task is already exists
        if (count($job['tasks']) > 1) {
            throw new DcosApiException("Job has more than one task [" . print_r($job, 1) . "]", self::E_INVALID_JOB_DATA);
        }
        if (
            !isset($job['tasks'][0]['id']) || !$job['tasks'][0]['id'] ||
            !isset($job['tasks'][0]['slaveId']) || !$job['tasks'][0]['slaveId']
        ) {
            throw new DcosApiException("Invalid job 'tasks' [" . print_r($job, 1) . "]", self::E_INVALID_JOB_DATA);
        }
        $jobTaskId = $job['tasks'][0]['id'];
        $jobSlaveId = $job['tasks'][0]['slaveId'];

        $slaveState = $this->getSlaveState($jobSlaveId);
        if (!isset($slaveState['frameworks']) || !$slaveState['frameworks']) {
            return '';
        }
        foreach ($slaveState['frameworks'] as $framework) {
            if (
                !isset($framework['id']) || !$framework['id'] ||
                !isset($framework['executors']) || !$framework['executors']
            ) {
                throw new DcosApiException("Invalid slave framework data [" . print_r($framework, 1) . "]", self::E_INVALID_SLAVE_STATE);
            }
            foreach ($framework['executors'] as $executor) {
                if (
                    !isset($executor['id']) || !$executor['id'] ||
                    !isset($executor['container']) || !$executor['container']
                ) {
                    throw new DcosApiException("Invalid slave executor data [" . print_r($executor, 1) . "]", self::E_INVALID_SLAVE_STATE);
                }
                if ($executor['id'] != $jobTaskId) {
                    continue;
                }

                return $this->getTaskLogs($jobSlaveId, $framework['id'], $executor['id'], $executor['container'], $outputType, 0, self::JOB_GET_OUTPUT_MAX_LEN);
            }
        }
        return '';
    }

    public function getJobVersions($jobId)
    {
        $response = $this->request(self::REQUEST_BASE_PATH . "/apps/$jobId/versions", HttpRequest::HTTP_GET);
        if ($response['code'] == self::RES_CODE_200 && isset($response['content']['versions'])) {
            return $response['content']['versions'];
        }
        if ($response['code'] == self::RES_CODE_404) {
            return [];
        }
        throw new DcosApiException("Failed to get job [$jobId] versions", self::E_FAILED_REQUEST);
    }

    public function getJobsQueue()
    {
        $response = $this->request(self::REQUEST_BASE_PATH . "/queue", HttpRequest::HTTP_GET);
        if ($response['code'] == self::RES_CODE_200 && isset($response['content']['queue'])) {
            return $response['content']['queue'];
        }
        if ($response['code'] == self::RES_CODE_404) {
            return [];
        }
        throw new DcosApiException("Failed to get jobs queue", self::E_FAILED_REQUEST);
    }

    public function getJobsDeployment()
    {
        $response = $this->request(self::REQUEST_BASE_PATH . "/deployments", HttpRequest::HTTP_GET);
        if ($response['code'] == self::RES_CODE_200 && isset($response['content'])) {
            return $response['content'];
        }
        if ($response['code'] == self::RES_CODE_404) {
            return [];
        }
        throw new DcosApiException("Failed to get jobs queue", self::E_FAILED_REQUEST);
    }

    public function addJob(
        $jobTokenId,
        $command,
        $containerImage,
        $cpus                   = JOB_DEFAULT_CPUS,
        $mem                    = JOB_DEFAULT_MEM,
        $disk                   = JOB_DEFAULT_DISK,
        array $containerPaths   = [['/tmp/', '/tmp/']], // Container path <-> Host path
        $removeCompleted        = true
    )
    {
        if (!$jobTokenId || strlen($jobTokenId) > self::JOB_TOKEN_ID_MAX_LEN) {
            throw new DcosApiException("Invalid job token id [$jobTokenId]", self::E_BAD_REQUEST);
        }

        $jobId = $this->makeJobId($jobTokenId);

        if (!$command || strlen($command) > self::JOB_CMD_STR_MAX_LENGTH) {
            throw new DcosApiException("Invalid job command [$command]", self::E_BAD_REQUEST);
        }

        if (!$containerImage || strlen($containerImage) > self::JOB_CONTAINER_IMAGE_NAME_MAX_LEN) {
            throw new DcosApiException("Invalid job container image [$containerImage]", self::E_BAD_REQUEST);
        }

        if ($cpus < 0 || $cpus > self::HARDWARE_CPUS_MAX) {
            throw new DcosApiException("Invalid job cpus [$cpus]", self::E_BAD_REQUEST);
        }

        if ($mem < 0 || $mem > self::HARDWARE_MEM_MAX) {
            throw new DcosApiException("Invalid job memory [$mem]", self::E_BAD_REQUEST);
        }

        if ($disk < 0 || $disk > self::HARDWARE_DISK_MAX) {
            throw new DcosApiException("Invalid job disk [$mem]", self::E_BAD_REQUEST);
        }

        $cmdCompletedSuccess = '(>&2 echo "' .
            str_replace(['%jobId%', '%status%', '%timestamp%'], [$jobId, JOB_STATUS_SUCCESS, '`date +%s`'], self::JOB_COMPLETED_LABEL) . '")';

        $cmdCompletedFail = '(>&2 echo "' .
            str_replace(['%jobId%', '%status%', '%timestamp%'], [$jobId, JOB_STATUS_FAILED, '`date +%s`'], self::JOB_COMPLETED_LABEL) . '")';

        $command = "($command) && $cmdCompletedSuccess || $cmdCompletedFail";

        if ($removeCompleted) {
            $cmdDelete = $this->request(self::REQUEST_BASE_PATH . "/apps/$jobId", HttpRequest::HTTP_DELETE, [], true);
            $command .= '; while [ 1 ]; do ' . $cmdDelete . '; sleep 5; done';
        }

        $cmdPermanentSleep = 'while [ 1 ]; do sleep 1; done;';
        $command .= '; ' . $cmdPermanentSleep;

        // HACK: DNS name resolving
        // Container dir /var/tmp/etc/ binds to external dir /etc/
        // So container /var/tmp/etc/hosts is actually an external /etc/hosts
        // cat /var/tmp/etc/hosts >> /etc/hosts - external hosts records being added to container hosts records
        $command = 'cat /var/tmp/etc/hosts >> /etc/hosts; ' . $command;
        $containerPaths[] = ['/var/tmp/etc/', '/etc/'];

        $volumes = [];
        foreach ($containerPaths as $path) {
            if (!isset($path[0]) || !isset($path[1])) {
                throw new DcosApiException("Invalid container path [" . print_r($path, 1) . "]", self::E_BAD_REQUEST);
            }
            $volumes[] = ['containerPath' => $path[0], 'hostPath' => $path[1], 'mode' => 'RW'];
        }

        $jobParams = [
            'id'                            => $jobId,
            'cmd'                           => $command,
            'cpus'                          => $cpus,
            'gpus'                          => 0,
            'mem'                           => $mem,
            'disk'                          => $disk,
            'instances'                     => 1,
            'user'                          => 'root',
            'maxLaunchDelaySeconds'         => 0,
            'taskKillGracePeriodSeconds'    => 5,
            'container' => [
                'type'                      => 'DOCKER',
                'docker' => [
                    'image'                 => $containerImage,
                    'forcePullImage'        => false,
                    'network'               => 'BRIDGE',
                ],
                'volumes'                   => $volumes
            ]
        ];

        $response = $this->request(self::REQUEST_BASE_PATH . '/apps', HttpRequest::HTTP_POST, $jobParams);
        if ($response['code'] != self::RES_CODE_201) {
            throw new DcosApiException("Failed to add new job", self::E_FAILED_REQUEST);
        }

        $this->debug("Created job [$jobId]", $jobParams);

        return $jobId;
    }

    // NOTE: On update job will be redeployed and run again
    public function updateJob($jobId, array $data)
    {
        $response = $this->request(self::REQUEST_BASE_PATH . "/apps/$jobId", HttpRequest::HTTP_PUT, $data);
        if ($response['code'] == self::RES_CODE_200) {
            return true;
        }
        if ($response['code'] == self::RES_CODE_404) {
            throw new DcosApiException("Job [$jobId] not found", self::E_NOT_FOUND);
        }
        throw new DcosApiException("Failed to update job [$jobId] wit data [" . print_r($data, 1) . "]", self::E_FAILED_REQUEST);
    }

    public function removeJob($jobId)
    {
        $response = $this->request(self::REQUEST_BASE_PATH . "/apps/$jobId", HttpRequest::HTTP_DELETE);
        if ($response['code'] == self::RES_CODE_200) {
            return true;
        }
        if ($response['code'] == self::RES_CODE_404) {
            return false;
        }
        throw new DcosApiException("Failed to remove job [$jobId]", self::E_FAILED_REQUEST);
    }

    public function removeJobsByTokenId($jobIdToken)
    {
        $removedJobs = 0;
        $jobs = $this->getJobsList();
        if ($jobs) {
            foreach ($jobs as $job) {
                if (!isset($job['id']) || !$job['id']) {
                    throw new DcosApiException("Invalid job 'id' [" . print_r($job, 1) . "]", self::E_INVALID_JOB_DATA);
                }
                if (!strstr($job['id'], $jobIdToken)) {
                    continue;
                }
                $this->removeJob($job['id']);
                $this->debug("Removed job [{$job['id']}]");
                $removedJobs++;
            }
        }
        return $removedJobs;
    }

    public function checkJobs()
    {
        $jobs = $this->getJobsList();
        foreach ($jobs as $job) {
            if (!isset($job['id']) || !$job['id']) {
                throw new DcosApiException("Invalid job 'id' [" . print_r($job, 1) . "]", self::E_INVALID_JOB_DATA);
            }

            $job = $this->getJob($job['id']);
            if (!$job) {
                throw new DcosApiException("Failed to get job [{$job['id']}]", self::E_INVALID_JOB_DATA);
            }
            if (
                !isset($job['tasks']) ||
                !isset($job['tasksRunning']) ||
                !isset($job['tasksUnhealthy'])
            ) {
                throw new DcosApiException("Invalid job data [" . print_r($job, 1) . "]", self::E_INVALID_JOB_DATA);
            }

            // Staging (deploying) tasks are not expected to be if one task is already exists
            if ($job['tasks'] && count($job['tasks']) > 1) {
                throw new DcosApiException("Job has more than one task [" . print_r($job, 1) . "]", self::E_INVALID_JOB_DATA);
            }

            if ($job['tasksRunning'] > 1) {
                throw new DcosApiException("Job has more than one running instances [" . print_r($job, 1) . "]", self::E_FAILED_JOB);
            }
            if ($job['tasksUnhealthy'] || (isset($job['lastTaskFailure']) && $job['lastTaskFailure'])) {
                throw new DcosApiException("Job has unhealthy task [" . print_r($job, 1) . "]", self::E_FAILED_JOB);
            }

            $versions = $this->getJobVersions($job['id']);
            if (!$versions) {
                throw new DcosApiException("Failed to get job [{$job['id']}] versions", self::E_INVALID_JOB_DATA);
            }

            if (!$jobStartTs = strtotime($versions[count($versions) - 1])) {
                throw new DcosApiException("Invalid job [{$job['id']}] versions [" . print_r($versions, 1) . "]", self::E_INVALID_JOB_DATA);
            }
            if (time() - $jobStartTs > self::JOB_MAX_RUNNING_SEC) {
                throw new DcosApiException("Job is running more time than allowed [" . print_r($job, 1) . "]", self::E_FAILED_JOB);
            }

            usleep(10000);
        }
    }

    public function checkJobsDeployment()
    {
        $jobsDeployment = $this->getJobsDeployment();
        foreach ($jobsDeployment as $deployment) {
            if (
                !isset($deployment['id']) || !$deployment['id'] ||
                !isset($deployment['version'])
            ) {
                throw new DcosApiException("Invalid job deployment data [" . print_r($deployment, 1) . "]", self::E_INVALID_JOB_DATA);
            }
            if (!$jobStartTs = strtotime($deployment['version'])) {
                throw new DcosApiException("Invalid job $deployment 'version' [" . print_r($deployment, 1) . "]", self::E_INVALID_JOB_DATA);
            }
            if (time() - $jobStartTs > self::JOB_MAX_DEPLOYMENT_SEC) {
                throw new DcosApiException('Job exceeds max deployment time [' . print_r($deployment, 1) . ']', self::E_FAILED_JOB);
            }
        }
    }

    public function checkJobsQueue()
    {
        $jobsQueue = $this->getJobsQueue();
        foreach ($jobsQueue as $queue) {
            if (
                !isset($queue['app']['id']) || !$queue['app']['id'] ||
                !isset($queue['app']['version'])
            ) {
                throw new DcosApiException("Invalid job queue data [" . print_r($queue, 1) . "]", self::E_INVALID_JOB_DATA);
            }
            if (!$jobStartTs = strtotime($queue['app']['version'])) {
                throw new DcosApiException("Invalid job queue 'version' [" . print_r($queue, 1) . "]", self::E_INVALID_JOB_DATA);
            }
            if (time() - $jobStartTs > self::JOB_MAX_QUEUED_SEC) {
                throw new DcosApiException('Job exceeds max queued time [' . print_r($queue, 1) . ']', self::E_FAILED_JOB);
            }
        }
    }
}
