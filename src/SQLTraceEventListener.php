<?php /** @noinspection PhpUndefinedFunctionInspection */
/** @noinspection PhpUnused */

/** @noinspection PhpUndefinedNamespaceInspection */

namespace LaravelSQLTrace;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Database\Events\QueryExecuted;
use Redis;
use Throwable;

class SQLTraceEventListener
{
    /** @var resource $fp1 记录 sql.log */
    protected $fp1;
    /** @var resource $fp2 记录 sql_trace.log */
    protected $fp2;
    /** @var resource $fp3 记录 sql_error.log */
    protected $fp3;
    /** @var Redis $predis */
    protected $predis;
    /** @var Client $http */
    protected $http;
    protected $singleton;
    protected static $global_app_trace_id;

    protected static $ENV_MAP = [
        'log_prefix' => '',
        'enable_log' => false,
        'enable_analytic' => '',
        'enable_params_log' => false,
        'dsn' => '', // must
        'ignore_folder' => null,
        'redis_host' => '',
        'redis_port' => '',
        'redis_password' => '',
    ];

    public function __construct()
    {
        if ($this->singleton) {
            return;
        }
        $this->loadEnv();
        date_default_timezone_set('Asia/Shanghai');
        $sql_file = static::getEnv('log_prefix');
        $path = pathinfo($sql_file);
        $sql_file = $path['dirname'] . DIRECTORY_SEPARATOR . $path['filename'];
        if (static::getEnv('enable_log')) {
            $this->fp1 = @fopen($sql_file . '.log', 'ab+');
            $this->fp2 = @fopen($sql_file . '_trace.log', 'ab+');
        }
        $this->fp3 = @fopen($sql_file . '_error.log', 'ab+');
        if (static::getEnv('enable_analytic')) {
            try {
                $this->predis = XRedis::getInstance()->predis;
            } catch (Throwable $e) {
                $this->error('[REDIS_ERROR] ' . $e->getMessage());
                $this->predis = null;
            }
        }
        if (self::getEnv('dsn')) {
            $this->http = new Client(['base_uri' => self::getEnv('dsn'), 'timeout' => 1]);
        }
        $this->singleton = $this;
    }

    /**
     * 处理SQL事件
     *
     * 只要把当前类挂载到 QueryExecuted 事件上，
     * Laravel 的每次数据库执行操作都会执行 handle 函数
     * ```
     * app/Providers/EventServiceProvider.php
     *
     * ...
     * protected $listen = [
     *   \Illuminate\Database\Events\QueryExecuted::class => [ \LaravelSQLTrace\SQLTraceEventListener::class, ],
     * ];
     * ...
     *
     * ```
     *
     * @param QueryExecuted $event
     *
     * @return void
     */
    public function handle(QueryExecuted $event): void
    {
        if (($check = $this->checkIsOk()) < 0) {
            $this->error('[CHECK_ERROR] ' . $check);
            return;
        }
        try {
            $db_host = $event->connection->getConfig('host');
            $exec_ms = $event->time; // ms
            $sql = $event->sql;
            // trim \r\n 替换成 space
            $sql = str_replace(["\r", "\n", "\r\n"], ' ', $sql);
            if (!$this->analyseAndContinue($db_host, $exec_ms, $sql)) {
                return;
            }

            $sql_trace_id = static::get_curr_sql_trace_id();
            // 需要单行显示，方便日志集成处理工具
            $bindings = implode(', ', array_map(static function ($v) {
                $v === null && $v = "null";
                return $v;
            }, $event->bindings));
            // 绑定的值换成\\n
            $bindings = str_replace(["\r", "\n", "\r\n"], ' ', $bindings);
            $this->saveSQLToFile($db_host, $exec_ms, $sql_trace_id, $sql, $bindings);

            global $argv, $global_upload_log_data, $_get, $_post;
            $_uri = $_SERVER['REQUEST_URI'] ?? '';
            $_uri = explode('?', $_uri)[0] ?? '';
            $api_uri = sprintf("%s %s", $_SERVER['REQUEST_METHOD'] ?? '', $_uri);

            $data = [
                'app_uuid' => static::get_global_app_trace_id(),
                'sql_uuid' => $sql_trace_id,
                'app_name' => config('app.name', 'default') ?? 'no-app-name',
                'db_host' => $db_host,
                'run_host' => gethostname(),
                'run_ms' => (int)$exec_ms,
                'run_mode' => PHP_SAPI,
                'pid' => (int)getmygid(),
                'request_uri' => PHP_SAPI === 'cli' ? implode(' ', $argv) : $api_uri,
                'referer' => PHP_SAPI !== 'cli' ? ($_SERVER['HTTP_REFERER'] ?? '') : '',
                'trace_sql_md5' => md5($sql),
                'trace_sql' => $sql,
                'trace_sql_binds' => $bindings,
                'created_at' => static::get_datetime_ms(),
                'trace_files' => [],
            ];
            if (empty($_get)) {
                $_get = json_encode($_GET);
                $_post = json_encode($_POST);
                $data['request_query'] = $_get;
                $data['request_post'] = $_post;
            }
            $logback = $this->saveSQLTraceToFile(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), $sql_trace_id);
            foreach ($logback as $item) {
                $data['trace_files'][] = [
                    'trace_file' => $item['file'],
                    'trace_line' => $item['line'],
                    'trace_class' => $item['class'],
                    'created_at' => static::get_datetime_ms(),
                ];
            }
            $global_upload_log_data[] = $data;
            $this->uploadLog();
        } catch (Throwable $e) {
            $this->error('[MAIN_ERROR] ' . $e->getMessage());
        }
    }

    /**
     * 上传日志
     *
     * @param bool $force
     */
    protected function uploadLog(bool $force = false): void
    {
        if (!$this->http) {
            return;
        }
        global $global_upload_log_data;
        if (empty($global_upload_log_data)) {
            return;
        }

        if ($force || count($global_upload_log_data) >= 10) {
            try {
                $body = json_encode($global_upload_log_data);
                $this->http->post('/api/v1/trace', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => $body,
                ]);
            } catch (Throwable $e) {
                $this->error(sprintf("[UPLOAD_ERROR] %s %s", $e->getMessage(), json_encode($body)));
            }
            $global_upload_log_data = [];
        }
    }

    /**
     * 保存调用栈
     *
     * 返回sql的logback
     *
     * @param array  $traces
     * @param string $curr_sql_trace_id
     *
     * @return array
     */
    protected function saveSQLTraceToFile(array $traces, string $curr_sql_trace_id): array
    {
        $j = 1;
        $format_traces = [];
        while (!empty($traces)) {
            $trace = array_pop($traces);
            $skip_folder = static::getEnv('ignore_folder');
            if (isset($trace['file']) && strpos($trace['file'] ?? '', $skip_folder) === false) {
                $format_trace = [
                    'file' => $trace['file'] ?: '',
                    'line' => $trace['line'] ?? 0,
                    'class' => $trace['class'] . $trace['type'] . $trace['function'] . '(..)'
                ];
                if (static::getEnv('enable_log')) {
                    fwrite($this->fp2, sprintf(
                        "\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n",
                        static::get_datetime_ms(),
                        $curr_sql_trace_id,
                        $j . '#' . $format_trace['file'],
                        $format_trace['line'],
                        $format_trace['class']
                    ));
                }
                $format_traces[] = $format_trace;
            }
            $j++;
        }
        return $format_traces;
    }

    /**
     * 保存SQL记录
     *
     * @param string $db_host
     * @param float  $exec_ms
     * @param string $sql_trace_id
     * @param string $sql
     * @param string $bindings
     *
     * @throws Exception
     */
    protected function saveSQLToFile(string $db_host, float $exec_ms, string $sql_trace_id, string $sql, string $bindings): void
    {
        if (!static::getEnv('enable_log')) {
            return;
        }
        global $argv;
        fwrite($this->fp1, sprintf(
            "\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n",
            config('app.name', 'default'),
            static::get_global_app_trace_id(),
            $sql_trace_id,
            static::get_datetime_ms(),
            $db_host,
            gethostname(),
            $exec_ms,
            getmypid(),
            PHP_SAPI,
            PHP_SAPI === 'cli' ? implode(' ', $argv) : ($_SERVER['REQUEST_URI'] ?? ''),
            PHP_SAPI !== 'cli' ? ($_SERVER['HTTP_REFERER'] ?? '') : '',
            md5($sql),
            $sql,
            $bindings
        ));
    }

    /**
     * 毫秒时间戳
     *
     * @return string
     */
    protected static function get_datetime_ms(): string
    {
        return date('Y-m-d') . 'T' . date('H:i:s.') . str_pad((int)(1000 * (microtime(true) - time())), 3, 0, STR_PAD_LEFT);
    }

    /**
     * 全局ID
     *
     * @return string
     * @throws Exception
     */
    protected static function get_global_app_trace_id(): string
    {
        if (!static::$global_app_trace_id) {
            static::$global_app_trace_id = $_SERVER['HTTP_TRACE_ID'] ??
                (
                    $_GET['trace_id'] ??
                    md5(time() . getmypid() . random_int(0, 9999))
                );
        }
        return self::$global_app_trace_id;
    }

    /**
     * SQL ID
     *
     * @return string
     * @throws Exception
     */
    protected static function get_curr_sql_trace_id(): string
    {
        return md5(time() . getmypid() . random_int(0, 9999));
    }

    /**
     * Redis统计
     *
     * 在此处处理，redis 计数等统计操作
     *
     * @param string $db_host
     * @param float  $exec_ms
     * @param string $sql
     *
     * @return bool 返回 false，当前执行完成，不再执行后续逻辑，比如降级处理的写入日志文件，推送第三方
     * @throws Exception
     */
    protected function analyseAndContinue(string $db_host, float $exec_ms, string $sql): bool
    {
        if (app()->environment() === 'local') {
            $is_continue = true;
        } else {
            $is_continue = $exec_ms > 0.1 || random_int(1, 20000) > (20000 - 20);
        }

        if ($this->predis && static::getEnv('enable_analytic')) {
            $sql_key = md5($db_host . $sql);
            $hash_key = 'SQL_TRACE_HASH_KEY:' . date('Ymd');
            $hash_key_incr = 'SQL_TRACE_HASH_KEY_INCR:' . date('Ymd');
            $hash_key_time_incr = 'SQL_TRACE_HASH_KEY_TIME_INCR:' . date('Ymd');
            if ($is_continue || !$this->predis->hExists($hash_key, $sql_key)) {
                $this->predis->hSet($hash_key, $sql_key, sprintf(
                    "```db_host=%s```app_host=%s```pid=%s```sql=%s```",
                    $db_host,
                    $_SERVER['REMOTE_ADDR'] ?? '-',
                    getmypid(),
                    $sql
                ));
            }
            $this->predis->hIncrBy($hash_key_incr, $sql_key, 1);
            $this->predis->hIncrBy($hash_key_time_incr, $sql_key, $exec_ms);
            if ($is_continue) {
                $this->predis->ttl($hash_key) === -1 && $this->predis->expire($hash_key, 2 * 86400);
                $this->predis->ttl($hash_key_incr) === -1 && $this->predis->expire($hash_key_incr, 2 * 86400);
                $this->predis->ttl($hash_key_time_incr) === -1 && $this->predis->expire($hash_key_time_incr, 2 * 86400);
            }
        }

        return $is_continue;
    }

    /**
     * 错误日志
     *
     * @param string $error
     */
    protected function error(string $error): void
    {
        @fwrite($this->fp3, '[' . static::get_datetime_ms() . ']' . $error . PHP_EOL);
    }

    /**
     * 判断环境是否OK
     *
     * @return int
     */
    protected function checkIsOk(): int
    {
        if ($this->predis === null && static::getEnv('enable_analytic')) {
            return -1;
        }

        if (($this->fp1 === false || $this->fp2 === false) && static::getEnv('enable_log')) {
            return -2;
        }

        return $this->fp3 === false ? -3 : 0;
    }

    protected function loadEnv(): void
    {
        self::$ENV_MAP['log_prefix'] = env('SQL_TRACE_SQL_PREFIX', '/tmp/sql');
        self::$ENV_MAP['enable_log'] = env('SQL_TRACE_ENABLE_LOG', true);
        self::$ENV_MAP['enable_analytic'] = env('SQL_TRACE_ANALYTIC', false);
        self::$ENV_MAP['dsn'] = env('SQL_TRACE_DSN', '');
        self::$ENV_MAP['ignore_folder'] = env('SQL_TRACE_IGNORE_FOLDER', 'vendor');
        self::$ENV_MAP['redis_host'] = env('SQL_TRACE_REDIS_HOST', '127.0.0.1');
        self::$ENV_MAP['redis_port'] = env('SQL_TRACE_REDIS_PORT', 6379);
        self::$ENV_MAP['redis_password'] = env('SQL_TRACE_REDIS_PASSWORD', 'vendor');
        self::$ENV_MAP['enable_params_log'] = env('SQL_TRACE_ENABLE_PARAMS_LOG', false);
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public static function getEnv(string $key)
    {
        return self::$ENV_MAP[$key];
    }

    public function __destruct()
    {
        if (self::getEnv('enable_log')) {
            fclose($this->fp1);
            fclose($this->fp2);
        }
        $this->uploadLog(true);
        fclose($this->fp3);
    }
}
