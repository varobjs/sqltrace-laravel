<?php

namespace SQLTrace;

use Illuminate\Support\Facades\Log as LaravelLog;

class Log
{
    protected static $reqId;

    public static function getReqId(): string
    {
        global $request_id_seq;
        if (is_null($request_id_seq)) {
            $request_id_seq = 0;
        } else {
            $request_id_seq++;
        }
        if (empty(static::$reqId) && !empty($_SERVER['X_REQ_ID'])) {
            static::$reqId = $_SERVER['X_REQ_ID'] . '-' . $request_id_seq;
        }
        if (empty(static::$reqId)) {
            static::$reqId = Utils::uuid() . '-' . $request_id_seq;
        }
        return preg_replace('/(\d+)$/', $request_id_seq, static::$reqId);
    }

    protected static function getDefaultContext(array &$context, int $logOffset = 0): void
    {
        $context['__req_id'] = static::getReqId();
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2 + $logOffset);
        $context['__file'] = sprintf(
            '%s@%d',
            $trace[1 + $logOffset]['file'] ?? '',
            $trace[1 + $logOffset]['line'] ?? ''
        );
    }

    public static function info(string $msg, array $context = [], int $logOffset = 0): void
    {
        static::getDefaultContext($context, $logOffset);
        LaravelLog::info($msg, $context);
    }

    public static function error(string $msg, array $context = [], int $logOffset = 0): void
    {
        static::getDefaultContext($context, $logOffset);
        LaravelLog::error($msg, $context);
    }

    public static function debug(string $msg, array $context = [], int $logOffset = 0): void
    {
        static::getDefaultContext($context, $logOffset);
        LaravelLog::debug($msg, $context);
    }

    public static function warning(string $msg, array $context = [], int $logOffset = 0): void
    {
        static::getDefaultContext($context, $logOffset);
        LaravelLog::warning($msg, $context);
    }

    public static function notice(string $msg, array $context = [], int $logOffset = 0): void
    {
        static::getDefaultContext($context, $logOffset);
        LaravelLog::notice($msg, $context);
    }

    public static function critical(string $msg, array $context = [], int $logOffset = 0): void
    {
        static::getDefaultContext($context, $logOffset);
        LaravelLog::critical($msg, $context);
    }

    public static function alert(string $msg, array $context = [], int $logOffset = 0): void
    {
        static::getDefaultContext($context, $logOffset);
        LaravelLog::alert($msg, $context);
    }

    public static function emergency(string $msg, array $context = [], int $logOffset = 0): void
    {
        static::getDefaultContext($context, $logOffset);
        LaravelLog::emergency($msg, $context);
    }

    public static function log(string $level, string $msg, array $context = [], int $logOffset = 0): void
    {
        static::getDefaultContext($context, $logOffset);
        LaravelLog::log($level, $msg, $context);
    }
}