<?php

namespace DcosPhpApi;

class HttpRequest
{
    use Debug;

    const HTTP_GET                      = 'GET';
    const HTTP_POST                     = 'POST';
    const HTTP_PUT                      = 'PUT';
    const HTTP_DELETE                   = 'DELETE';

    const URI_MAX_LENGTH                = 1024;
    const POST_DATA_MAX_BYTES           = 16384;
    const REQUEST_ATTEMPTS              = 10;
    const REQUEST_ATTEMPT_DELAY_INIT_MS = 1000;
    const RESPONSE_CONTENT_MAX_BYTES    = 1048576;

    const E_INVALID_REQUEST_URL         = 1001;
    const E_INVALID_HTTP_METHOD         = 1002;
    const E_INVALID_HEADERS             = 1003;
    const E_INVALID_POST_DATA           = 1004;
    const E_INVALID_CURL_OPTIONS        = 1005;
    const E_FAILED_CURL_INIT            = 1006;
    const E_FAILED_SET_CURL_OPTIONS     = 1007;
    const E_UNDEFINED_REQUEST           = 1008;
    const E_FAILED_GET_RESPONSE         = 1009;
    const E_FAILED_PARSE_RESPONSE       = 1010;
    const E_INVALID_RESPONSE_CONTENT    = 1011;

    static public $lastRequest = [];
    static public $lastResponse = [];

    private $curlOptions = [
        CURLOPT_CONNECTTIMEOUT      => 20,
        CURLOPT_TIMEOUT             => 60,
        CURLOPT_FAILONERROR         => 0,
        CURLOPT_RETURNTRANSFER      => 1,
        CURLOPT_FOLLOWLOCATION      => 1,
        CURLOPT_MAXREDIRS           => 5,
        CURLOPT_NOBODY              => 0,
        CURLOPT_HEADER              => 1,
        CURLOPT_ENCODING            => 'identity',
        CURLOPT_BUFFERSIZE          => 16384,
        CURLOPT_MAXCONNECTS         => 5,
        CURLOPT_PORT                => 80,
        CURLOPT_SSL_VERIFYPEER      => 0,
        CURLOPT_SSL_VERIFYHOST      => 0,
        CURLOPT_HTTPHEADER          => [],
        CURLOPT_POSTFIELDS          => '',
        CURLOPT_VERBOSE             => 0,
        CURLOPT_STDERR              => STDERR,
    ];

    private $curlHandler;
    private $requestUrl;

    static public function getLastRequest()
    {
        return self::$lastRequest;
    }

    static public function getLastResponse()
    {
        return self::$lastResponse;
    }

    function __construct() {
        self::$lastRequest = [];
        self::$lastResponse = [];

        if ($this->isDebug()) {
            $this->curlOptions[CURLOPT_VERBOSE] = 1;
            $this->curlOptions[CURLOPT_NOPROGRESS] = 0;
        }
    }

    function __destruct()
    {
        if (is_resource($this->curlHandler))
            @curl_close($this->curlHandler);
    }

    public function setRequest($url, $method = self::HTTP_GET, array $headers = [], $postData = '', array $curlOpts = [])
    {
        self::$lastRequest = [];

        if (!$url || strlen($url) > self::URI_MAX_LENGTH) {
            throw new HttpRequestException('Invalid url', self::E_INVALID_REQUEST_URL, $url, null);
        }
        $this->requestUrl = $url;

        if (!in_array($method, [self::HTTP_GET, self::HTTP_POST, self::HTTP_PUT, self::HTTP_DELETE])) {
            throw new HttpRequestException("Request method [$method] is not supported", self::E_INVALID_HTTP_METHOD, $url, null);
        }

        if ($headers && $headers == array_values($headers)) {
            throw new HttpRequestException("Headers must be in associative format", self::E_INVALID_HEADERS, $url, null);
        }

        if ($postData && strlen($postData) > self::POST_DATA_MAX_BYTES) {
            throw new HttpRequestException("Post data is too long [$postData]", self::E_INVALID_POST_DATA, $url, null);
        }

        if ($curlOpts && $curlOpts == array_values($curlOpts)) {
            throw new HttpRequestException("Curl options must be in associative format", self::E_INVALID_CURL_OPTIONS, $url, null);
        }

        $this->curlHandler = curl_init();
        if (!is_resource($this->curlHandler)) {
            throw new HttpRequestException('Failed to init curl resource', self::E_FAILED_CURL_INIT, $url, $this->curlHandler);
        }

        $this->curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
        $this->curlOptions[CURLOPT_URL] = $url;

        $headersAsStr = '';
        if ($headers) {
            foreach ($headers as $name => $value) {
                $headersAsStr[] = "$name: $value";
            }
            $this->curlOptions[CURLOPT_HTTPHEADER] = $headersAsStr;
        }

        if (in_array($method, [self::HTTP_POST, self::HTTP_PUT]) && strlen($postData)) {
            $this->curlOptions[CURLOPT_POSTFIELDS] = $postData;
        }

        if ($curlOpts) {
            $this->curlOptions = array_replace($this->curlOptions, $curlOpts);
        }

        if (!curl_setopt_array($this->curlHandler, $this->curlOptions)) {
            throw new HttpRequestException('Failed to set curl options', self::E_FAILED_SET_CURL_OPTIONS, $url, $this->curlHandler);
        }

        self::$lastRequest = $this->getRequest();

        return $this;
    }

    public function execRequest()
    {
        self::$lastResponse = [];

        if (!$this->curlHandler) {
            throw new HttpRequestException('Undefined request', self::E_UNDEFINED_REQUEST, $this->requestUrl, $this->curlHandler);
        }

        $response = null;

        $this->debug('Doing request: ' . $this->getRequestAsCmdString());

        $attempt = 1;
        while ($attempt++ <= self::REQUEST_ATTEMPTS) {
            $response = curl_exec($this->curlHandler);
            if ($response === false) {
                usleep(self::REQUEST_ATTEMPT_DELAY_INIT_MS * 1000 * pow($attempt, 2));
                continue;
            }

            $response = self::$lastResponse = $this->parseResponse($response);
            if (($response['code'] >= 200 && $response['code'] <= 299) || $response['code'] == 404) {
                // Verify response content is json-decodable
                if ($response['content']) {
                    json_decode($response['content'], true, 512, JSON_BIGINT_AS_STRING);
                    if (json_last_error()) {
                        continue;
                    }
                }
                break;
            }

            usleep(self::REQUEST_ATTEMPT_DELAY_INIT_MS * 1000 * pow($attempt, 2));
            continue;
        }

        if ($response === false) {
            throw new HttpRequestException('Failed to get response', self::E_FAILED_GET_RESPONSE, $this->requestUrl, $this->curlHandler);
        }

        return $response;
    }

    public function getRequest()
    {
        return [
            'method'    => $this->curlOptions[CURLOPT_CUSTOMREQUEST],
            'url'       => $this->curlOptions[CURLOPT_URL],
            'headers'   => $this->curlOptions[CURLOPT_HTTPHEADER],
            'postData'  => $this->curlOptions[CURLOPT_POSTFIELDS],
        ];
    }

    public function getRequestAsCmdString()
    {
        $cmd = ['curl'];

        if ($this->curlOptions[CURLOPT_VERBOSE]) {
            $cmd[] = '--verbose';
        }

        $cmd[] = '--connect-timeout ' . $this->curlOptions[CURLOPT_CONNECTTIMEOUT];
        $cmd[] = '--max-time ' . $this->curlOptions[CURLOPT_TIMEOUT];
        $cmd[] = '--max-redirs ' . $this->curlOptions[CURLOPT_MAXREDIRS];

        if (self::REQUEST_ATTEMPTS) {
            $cmd[] = '--retry ' . self::REQUEST_ATTEMPTS;
        }

        if ($this->curlOptions[CURLOPT_FOLLOWLOCATION]) {
            $cmd[] = '--location';
        }

        if ($this->curlOptions[CURLOPT_NOBODY]) {
            $cmd[] = '--head';
        }

        if ($this->curlOptions[CURLOPT_HEADER]) {
            $cmd[] = '--include';
        }

        if (!$this->curlOptions[CURLOPT_SSL_VERIFYPEER] || !$this->curlOptions[CURLOPT_SSL_VERIFYHOST]) {
            $cmd[] = '--insecure';
        }

        $cmd[] = '-X ' . $this->curlOptions[CURLOPT_CUSTOMREQUEST];

        if ($this->curlOptions[CURLOPT_POSTFIELDS]) {
            $cmd[] = '-d ' . escapeshellarg($this->curlOptions[CURLOPT_POSTFIELDS]);
        }

        if ($this->curlOptions[CURLOPT_HTTPHEADER]) {
            $headers = [];
            foreach ($this->curlOptions[CURLOPT_HTTPHEADER] as $header) {
                $headers[] = '-H ' . escapeshellarg($header);
            }
            $cmd[] = join(' ', $headers);
        }

        $cmd[] = escapeshellarg($this->curlOptions[CURLOPT_URL]);

        return join(' ', $cmd);
    }

    private function parseResponse($response)
    {
        // NOTE: Several headers sections can be present in response

        $resCode = 0;
        $resHeaders = [];

        $headerSectionClose = "\r\n\r\n";
        $offset = 0;
        while (($pos = strpos($response, $headerSectionClose, $offset)) !== false) {
            $header = substr($response, $offset, $pos - $offset);
            $offset = $pos + strlen($headerSectionClose);
            if (!preg_match('!^(?<proto>HTTP/1\.[1|0]\s(?<code>\d{3}).*)!', $header, $match)) {
                break;
            }

            $resCode = $match['code'];  // Last headers section response code is used

            if (!isset($resHeaders['_'])) {
                $resHeaders['_'] = $match['proto'];
            }
            else {
                $resHeaders['_'] .= "\n" . $match['proto'];
            }

            if (preg_match_all("!^(.+?):(.*)!m", $header, $match)) {
                foreach ($match[1] as $i => $_) {
                    $name = $match[1][$i];
                    if(!isset($resHeaders[$name])) {
                        $resHeaders[$name] = $match[2][$i];
                    }
                    else {
                        $resHeaders[$name] .= "\n" . $match[2][$i];
                    }
                }
            }
        }

        if (!$resCode) {
            throw new HttpRequestException('Failed to get response code', self::E_FAILED_PARSE_RESPONSE, $this->requestUrl, $this->curlHandler);
        }

        $resContent = substr($response, $offset);

        return [
            'code'      => $resCode,
            'headers'   => $resHeaders,
            'content'   => $resContent
        ];
    }
}