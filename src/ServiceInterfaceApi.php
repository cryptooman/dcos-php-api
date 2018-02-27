<?php

namespace DcosPhpApi;

interface ServiceInterfaceApi
{
    function __construct($dcosHost, $authTokenFile);

    public function getJobsList();

    public function getJob($jobId);

    public function getJobOutput($jobId, $outputType = JOB_OUTPUT_STDOUT);

    public function addJob(
        $jobTokenId,
        $command,
        $containerImage,
        $cpus                   = JOB_DEFAULT_CPUS,
        $mem                    = JOB_DEFAULT_MEM,
        $disk                   = JOB_DEFAULT_DISK,
        array $containerPaths   = [['/tmp/', '/tmp/']], // Container path <-> Host path
        $removeCompleted        = true
    );

    public function removeJob($jobId);
}
