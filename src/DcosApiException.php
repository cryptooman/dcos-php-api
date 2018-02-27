<?php

namespace DcosPhpApi;

class DcosApiException extends \Exception
{
    function __construct($message, $code)
    {
        $errorMsg[] = "[$code] $message";
        if ($lastRequest = HttpRequest::getLastRequest()) {
            $errorMsg[] = "Request:\n" . print_r($lastRequest, 1);
        }
        if ($lastResponse = HttpRequest::getLastResponse()) {
            $errorMsg[] = "Response:\n" . print_r($lastResponse, 1);
        }
        parent::__construct(join("\n", $errorMsg), $code);
    }
}
