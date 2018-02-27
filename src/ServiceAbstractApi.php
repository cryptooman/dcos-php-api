<?php

namespace DcosPhpApi;

abstract class ServiceAbstractApi
{
    use Debug;

    const USE_HTTPS_PROTO                   = false;

    const HARDWARE_CPUS_MAX                 = 8;            // Float
    const HARDWARE_MEM_MAX                  = 32768;        // In megabytes
    const HARDWARE_DISK_MAX                 = 104857600;    // In megabytes

    const JOB_TOKEN_ID_MAX_LEN              = 50;
    const JOB_CMD_STR_MAX_LENGTH            = 1024;
    const JOB_CONTAINER_IMAGE_NAME_MAX_LEN  = 100;
    const JOB_GET_OUTPUT_MAX_LEN            = 1024100;   // Get stdout or stderr logs max size
    const JOB_MAX_DEPLOYMENT_SEC            = 900;
    const JOB_MAX_QUEUED_SEC                = 900;
    const JOB_MAX_RUNNING_SEC               = 604800;

    const RES_CODE_200                      = 200;
    const RES_CODE_201                      = 201;
    const RES_CODE_204                      = 204;
    const RES_CODE_404                      = 404;

    const E_UNKNOWN_SERVICE                 = 2001;
    const E_INVALID_MASTER_NODE_ADDRESS     = 2002;
    const E_INVALID_AUTH_TOKEN              = 2003;
    const E_BAD_REQUEST                     = 2004;
    const E_FAILED_REQUEST                  = 2005;
    const E_UNEXPECTED_REDIRECT             = 2006;
    const E_NOT_FOUND                       = 2007;
    const E_UNKNOWN_RESPONSE_CODE           = 2008;
    const E_ERROR_JSON_DECODE               = 2009;
    const E_ERROR_JSON_ENCODE               = 2010;
    const E_INVALID_MASTER_STATE            = 2011;
    const E_INVALID_SLAVE_STATE             = 2012;
    const E_INVALID_JOB_DATA                = 2013;
    const E_FAILED_JOB                      = 2014;

    public $mapGetJobsList;
    public $mapGetJob;

    protected $dcosHost;

    private $httpProto;
    private $authToken;

    function __construct($dcosHost, $authToken)
    {
        $this->dcosHost = $dcosHost;
        $this->authToken = $authToken;

        $this->httpProto = (self::USE_HTTPS_PROTO) ? 'https' : 'http';
    }

    public function mapData(array $data, array $map)
    {
        $result = [];
        foreach ($map as $key => $source) {
            if (is_callable($source)) {
                $result[$key] = $source($data);
            }
            else if (isset($data[$source])) {
                $result[$key] = $data[$source];
            }
        }
        return $result;
    }

    public function getSlaveState($slaveId)
    {
        $cacheLifetimeSec = 5;

        static $cache;
        if ($cache && count($cache) > 10000) {
            $cache = [];
        }

        $cacheKey = $slaveId;

        if (!isset($cache[$cacheKey]['expire']) || $cache[$cacheKey]['expire'] <= time()) {
            $response = $this->request("/agent/$slaveId/slave(1)/state?_timestamp=" . time(), HttpRequest::HTTP_GET);
            if ($response['code'] == self::RES_CODE_200 && isset($response['content']) && $response['content']) {
                $cache[$cacheKey] = [
                    'expire' => time() + $cacheLifetimeSec,
                    'data' => $response['content']
                ];
            }
            else {
                throw new DcosApiException("Failed to get slave state [$slaveId]", self::E_FAILED_REQUEST);
            }
        }
        return $cache[$cacheKey]['data'];
    }

    public function getTaskLogs($slaveId, $frameworkId, $taskId, $containerId, $logType, $offset, $length)
    {
        if (!in_array($logType, array(JOB_OUTPUT_STDOUT, JOB_OUTPUT_STDERR))) {
            throw new DcosApiException("Invalid log type [$logType]", self::E_BAD_REQUEST);
        }

        $cacheLifetimeSec = 5;

        static $cache;
        if ($cache && count($cache) > 10000) {
            $cache = [];
        }

        $cacheKey = $slaveId . '--' . $frameworkId . '--' . $taskId . '--' . $containerId . '--' . $logType;

        if (!isset($cache[$cacheKey]['expire']) || $cache[$cacheKey]['expire'] <= time()) {
            $response = $this->request(
                "/agent/$slaveId/files/read?path=" .
                "/var/lib/mesos/slave/slaves/$slaveId/frameworks/$frameworkId/executors/$taskId/runs/$containerId/$logType&offset=$offset&length=$length",
                HttpRequest::HTTP_GET
            );
            if ($response['code'] == self::RES_CODE_200 && isset($response['content']['data'])) {
                $cache[$cacheKey] = [
                    'expire' => time() + $cacheLifetimeSec,
                    'data' => $response['content']['data']
                ];
            }
            else if ($response['code'] == self::RES_CODE_404) {
                $cache[$cacheKey] = [
                    'expire' => time() + $cacheLifetimeSec,
                    'data' => ''
                ];
            }
            else {
                throw new DcosApiException("Failed to get task logs [$taskId]", self::E_FAILED_REQUEST);
            }
        }
        return $cache[$cacheKey]['data'];
    }

    protected function request($path, $method, array $data = [], $getRequestAsCmdString = false)
    {
        $httpRequest = new HttpRequest;

        $headers = [
            'User-Agent'    => 'dcos-php-api-1.0',
            'Content-Type'  => 'application/json'
        ];
        if ($this->authToken) {
            $headers['Authorization'] = "token=$this->authToken";
        }

        $postData = ($data) ? $this->jsonEncode($data) : '';

        $httpRequest->setRequest(
            $this->httpProto . '://' . $this->dcosHost . $path,
            $method,
            $headers,
            $postData
        );

        if ($getRequestAsCmdString) {
            return $httpRequest->getRequestAsCmdString();
        }

        $response = $httpRequest->execRequest();

        $this->debug('Got response', $response);

        $resCode    = $response['code'];
        $resHeaders = $response['headers'];
        $resContent = null;

        if ($resCode >= 200 && $resCode <= 299) {
            $resContent = $this->jsonDecode($response['content']);
        }
        else if ($resCode == self::RES_CODE_404) {
            $resContent = $this->jsonDecode($response['content']);
        }
        else if ($resCode >= 100 && $resCode <= 199) {
            throw new DcosApiException('Request was not completed', self::E_FAILED_REQUEST);
        }
        else if ($resCode >= 300 && $resCode <= 399) {
            throw new DcosApiException('Unexpected redirect', self::E_UNEXPECTED_REDIRECT);
        }
        else if ($resCode >= 400 && $resCode <= 599) {
            throw new DcosApiException('Request error', self::E_FAILED_REQUEST);
        }
        else {
            throw new DcosApiException('Unknown response code', self::E_UNKNOWN_RESPONSE_CODE);
        }

        return ['code' => $resCode, 'headers' => $resHeaders, 'content' => $resContent];
    }

    protected function makeJobId($token)
    {
        $microtime = explode('.', microtime(true));
        $uTime = $microtime[0];
        $muTime = isset($microtime[1]) ? sprintf('%04d', $microtime[1]) : '0000';
        $id = strftime("%Y%m%d%H%M%S", $uTime) . $muTime . mt_rand(1000, 9999);
        $id .= '-' . $token;
        return $id;
    }

    protected function jsonEncode($mixed)
    {
        $jsonEncoded = json_encode($mixed);
        if (json_last_error()) {
            throw new DcosApiException(
                'Failed to encode json ('. json_last_error_msg() .'). JSON: ' . print_r($mixed, 1),
                self::E_ERROR_JSON_ENCODE
            );
        }
        return $jsonEncoded;
    }

    protected function jsonDecode($jsonData)
    {
        $jsonData = trim($jsonData);
        if (!$jsonData) {
            return '';
        }
        $jsonDecoded = json_decode($jsonData, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error()) {
            throw new DcosApiException(
                'Failed to decode json ('. json_last_error_msg() .'). JSON: ' . $jsonData,
                self::E_ERROR_JSON_DECODE
            );
        }
        return $jsonDecoded;
    }

    protected function checkProbability($ratio)
    {
        $ratio = (double) $ratio;
        if ($ratio >= 1) {
            return true;
        }
        if ($ratio <= 0) {
            return false;
        }
        $fractionLen = strlen(strstr($ratio, '.')) - 1;
        $max = pow(10, $fractionLen);
        return (bool) (mt_rand(1, $max) <= $ratio * $max);
    }
}
