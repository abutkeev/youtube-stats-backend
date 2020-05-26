<?php
final class Logger
{
    private static $instance;
    private static $debug = false;

    private static $old_debug = false;

    private function __construct($ident = 'Logger', $option = LOG_PERROR, $facility = LOG_LOCAL0)
    {
        openlog($ident, $option, $facility);
        self::$instance = $this;
    }

    public static function init($ident, $option = 0, $facility = LOG_LOCAL0)
    {
        if (!isset(self::$instance)) {
            new self($ident, $option, $facility);
        }
    }

    public static function log($priority, $message, ...$args)
    {
        if (!isset(self::$instance)) {
            new self();
        }

        foreach ($args as $arg) {
            if (is_string(($arg))) {
                $message .= ' '. $arg;
            } else {
                $message .= ' '. json_encode($arg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if ($priority == LOG_DEBUG && self::$debug) {
            syslog($priority, $message);
        } elseif ($priority != LOG_DEBUG) {
            syslog($priority, $message);
        }

    }

    public static function debug($state)
    {
        self::$debug = $state;
        self::$old_debug = $state;
    }

    public static function temporary_debug_on()
    {
        self::$old_debug = self::$debug;

        self::$debug = true;
    }

    public static function temporary_debug_off()
    {
        self::$debug = self::$old_debug;
    }
}