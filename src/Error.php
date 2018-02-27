<?php

namespace DcosPhpApi;

class Error
{
    use Debug;

    public function log($message)
    {
        $error = "ERROR : " . date('Y-m-d H:i:s') . " " . $message;
        $this->debug($error);
        file_put_contents(Config::get('errorsLogFile'), $error . "\n", FILE_APPEND | LOCK_EX);
        return $error;
    }
}