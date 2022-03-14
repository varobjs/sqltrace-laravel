<?php

return [
    'enable_analytic' => env('SQL_TRACE_ANALYTIC', false),
    'enable_log' => env('SQL_TRACE_ENABLE_LOG', true),
    'log_file' => env('SQL_TRACE_LOG_FILE', '/tmp/sql.log'),
    'enable_params' => env('SQL_TRACE_ENABLE_PARAMS_LOG', false),
    'dsn' => env('SQLTRACE_DSN', ''),
    'ignore_folder' => env('SQL_TRACE_IGNORE_FOLDER', 'vendor'),
    'redis_host' => env('SQL_TRACE_REDIS_HOST', '127.0.0.1'),
    'redis_port' => env('SQL_TRACE_REDIS_PORT', 6379),
    'redis_password' => env('SQL_TRACE_REDIS_PASSWORD', ''),
];