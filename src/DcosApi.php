<?php

namespace DcosPhpApi;

class DcosApi
{
    use Debug;

    const SERVICE_MARATHON = '\DcosPhpApi\ServiceMarathonApi';

    private $service;

    function __construct($dcosHost, $authTokenBase64 = '', $serviceName = self::SERVICE_MARATHON)
    {
        $dcosHost = trim($dcosHost);
        if (!$dcosHost) {
            throw new DcosApiException('Invalid master node address', ServiceAbstractApi::E_INVALID_MASTER_NODE_ADDRESS);
        }

        if ($authTokenBase64) {
            $authToken = trim(base64_decode($authTokenBase64));
            if (!$authToken || strlen($authToken) > 1024) {
                throw new DcosApiException('Invalid auth token', ServiceAbstractApi::E_INVALID_AUTH_TOKEN);
            }
        }
        else {
            $authToken = '';
        }

        if (!in_array($serviceName, [self::SERVICE_MARATHON])) {
            throw new DcosApiException("Unknown service [$serviceName]", ServiceAbstractApi::E_UNKNOWN_SERVICE);
        }

        $this->service = new $serviceName($dcosHost, $authToken);
    }

    public function getService()
    {
        return $this->service;
    }

    public function getJobsList()
    {
        $jobs = $this->service->getJobsList();
        if (!$jobs) {
            return [];
        }
        $result = [];
        foreach ($jobs as $job) {
            $result[] = $this->service->mapData($job, $this->service->mapGetJobsList);
        }
        return $result;
    }

    public function getJob($jobId)
    {
        $job = $this->service->getJob($jobId);
        if (!$job) {
            return [];
        }
        return $this->service->mapData($job, $this->service->mapGetJob);
    }

    public function getJobOutput($jobId)
    {
        return $this->service->getJobOutput($jobId);
    }

    public function addJob(
        $jobTokenId,
        $command,
        $containerImage,
        $cpus                   = JOB_DEFAULT_CPUS,
        $mem                    = JOB_DEFAULT_MEM,
        $disk                   = JOB_DEFAULT_DISK,
        array $containerPaths   = [['/tmp/', '/tmp/']],
        $removeCompleted        = true
    )
    {
        return $this->service->addJob(
            $jobTokenId,
            $command,
            $containerImage,
            $cpus,
            $mem,
            $disk,
            $containerPaths,
            $removeCompleted
        );
    }

    public function removeJob($jobId)
    {
        return $this->service->removeJob($jobId);
    }
}
