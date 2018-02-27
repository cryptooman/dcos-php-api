<?php

namespace DcosPhpApi;

trait Debug
{
    public function debug()
    {
        if (!$this->isDebug()) {
            return;
        }
        $mixed = func_get_args();
        $msg = [];
        foreach ($mixed as $arg) {
            if (is_array($arg) || is_object($arg)) {
                $msg[] = print_r($arg, 1);
            }
            else {
                $msg[] = $arg;
            }
        }
        $msg = join(' -- ', $msg);
        $microtime = explode('.', microtime(true));
        $uTime = $microtime[0];
        $muTime = isset($microtime[1]) ? sprintf('%04d', $microtime[1]) : '0000';
        $line = strftime("%Y-%m-%d %H:%M:%S", $uTime) . '.' . $muTime . " -- " . __CLASS__ . " -- " . $msg . "\n";
        fwrite(STDERR, $line);
    }

    public function isDebug()
    {
        return (bool) Config::get('debug');
    }
}