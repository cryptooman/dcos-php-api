<?php

namespace DcosPhpApi;

class HttpRequestException extends \Exception
{
    function __construct($message, $code, $requestUrl, $curlHandler)
    {
        $curlErrorMsg = is_resource($curlHandler) ? curl_error($curlHandler) : null;
        parent::__construct("[$code] $message ($curlErrorMsg) -- Request URL [$requestUrl]", $code);
    }
}
