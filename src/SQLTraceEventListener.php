<?php /** @noinspection PhpUnusedParameterInspection */

namespace LaravelSQLTrace;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Database\Events\QueryExecuted;
use Redis;
use Throwable;

class SQLTraceEventListener
{
    /** @var resource $fp1 */
    protected $fp1;
    /** @var resource $fp2 */
    protected $fp2;
    /** @var resource $fp3 */
    protected $fp3;
    /** @var Redis $predis */
    protected $predis;
    /** @var \GuzzleHttp\Client $http */
    protected $http;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        date_default_timezone_set('Asia/Shanghai');
        $sql_file = env('SQL_TRACE_SQL_FILE', '/tmp/sql.log');
        $path = pathinfo($sql_file);
        $sql_file = $path['dirname'] . DIRECTORY_SEPARATOR . $path['filename'];
        $this->fp1 = @fopen($sql_file . '.log', 'a+');
        $this->fp2 = @fopen($sql_file . '_trace.log', 'a+');
        $this->fp3 = @fopen($sql_file . '_error.log', 'a+');
        if (env('SQL_TRACE_ANALYSE')) {
            try {
                $this->predis = XRedis::getInstance()->predis;
            } catch (Throwable $e) {
                $this->error('[REDIS_ERROR] ' . $e->getMessage());
                $this->predis = null;
            }
        }
        $this->http = new Client(['base_uri' => 'localhost:7788']);
    }

    public function __destruct()
    {
        fclose($this->fp1);
        fclose($this->fp2);
        fclose($this->fp3);
    }

    /**
     * Handle the event.
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
    public function handle(QueryExecuted $event)
    {
        if (($check = $this->checkIsOk()) < 0) {
            $this->error('[CHECK_ERROR] ' . $check);
            return;
        }
        try {
            $sql_trace_id = static::get_curr_sql_trace_id();
            $db_host = $event->connection->getConfig('host');
            $exec_ms = $event->time; // ms
            $sql = $event->sql;
            // trim \r\n 替换成 space
            $sql = str_replace(["\r", "\n", "\r\n"], '', $sql);
            // 需要单行显示，方便日志集成处理工具
            $bindings = implode(', ', array_map(function ($v) {
                $v === null && $v = "null";
                return $v;
            }, $event->bindings));
            // 绑定的值换成\\n
            $bindings = str_replace(["\r", "\n", "\r\n"], '\\n', $bindings);

            if (!$this->analyseAndContinue($db_host, $exec_ms, $sql)) {
                return;
            }

            $this->saveSQLToFile(
                $db_host,
                $exec_ms,
                $sql_trace_id,
                $sql,
                $bindings
            );

            $logback = $this->saveSQLTraceToFile(
                debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
                $sql_trace_id
            );
            global $argv;
            $data = [
                'app_uuid' => static::get_global_app_trace_id(),
                'sql_uuid' => $sql_trace_id,
                'db_host' => $db_host,
                'run_host' => gethostname(),
                'run_ms' => $exec_ms,
                'pid' => getmygid(),
                'request_uri' => PHP_SAPI === 'cli' ? implode(' ', $argv) : ($_SERVER['REQUEST_URI'] ?? ''),
                'referer' => PHP_SAPI !== 'cli' ? ($_SERVER['HTTP_REFERER'] ?? '') : '',
                'trace_sql_md5' => md5($sql),
                'trace_sql' => $sql,
                'trace_sql_binds' => $bindings,
                'created_at' => static::get_datetime_ms(),
                'trace_files' => [],
            ];
            foreach ($logback as $item) {
                $data['trace_files'][] = [
                    'trace_file' => $item['file'],
                    'trace_line' => $item['line'],
                    'trace_class' => $item['class'],
                    'created_at' => static::get_datetime_ms(),
                ];
            }
            $this->http->post('/api/v1/trace', [
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'body' => $data
            ]);
        } catch (Throwable $e) {
            $this->error('[MAIN_ERROR] ' . $e->getMessage());
        }
    }

    /**
     * 保存调用链接，返回sql的logback
     *
     * @param array  $traces
     * @param string $curr_sql_trace_id
     *
     * @return array
     */
    protected function saveSQLTraceToFile(array $traces, string $curr_sql_trace_id): array
    {
        $i = 0;
        $j = 1;
        $format_traces = [];
        while (!empty($traces)) {
            $trace = array_pop($traces);
            $skip_folder = env('SQL_TRACE_IGNORE_FOLDER', 'vendor');
            if (isset($trace['file']) && strstr($trace['file'] ?? '', $skip_folder) === false) {
                $format_trace = [
                    'file' => $trace['file'] ?: '',
                    'line' => $trace['line'] ?? 0,
                    'class' => $trace['class'] . $trace['type'] . $trace['function'] . '(..)'
                ];
                fwrite($this->fp2, sprintf(
                    "\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n",
                    static::get_datetime_ms(),
                    $curr_sql_trace_id,
                    $j . '#' . $format_trace['file'],
                    $format_trace['line'],
                    $format_trace['class']
                ));
                $format_traces[] = $format_trace;
                $i++;
            }
            $j++;
        }
        return $format_traces;
    }

    /**
     * @param string $db_host
     * @param float  $exec_ms
     * @param string $sql_trace_id
     * @param string $sql
     * @param string $bindings
     */
    protected function saveSQLToFile(
        string $db_host,
        float  $exec_ms,
        string $sql_trace_id,
        string $sql,
        string $bindings
    )
    {
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

    protected static function get_datetime_ms(): string
    {
        return date('Y-m-d&H:i:s.') . str_pad((int)(10000 * (microtime(true) - time())), 3, 0, STR_PAD_LEFT);
    }

    protected static $global_app_trace_id;

    protected static function get_global_app_trace_id(): string
    {
        if (!static::$global_app_trace_id) {
            static::$global_app_trace_id = $_SERVER['HTTP_TRACE_ID'] ??
                (
                    $_GET['trace_id'] ??
                    strtoupper(substr(md5(time() . getmypid() . rand(0, 9999)), 0, 8))
                );
        }
        return self::$global_app_trace_id;
    }

    protected static function get_curr_sql_trace_id(): string
    {
        return strtoupper(substr(md5(time() . getmypid() . rand(0, 9999)), 0, 8));
    }

    /**
     * 在此处处理，redis 计数等统计操作
     *
     * @param string $db_host
     * @param float  $exec_ms
     * @param string $sql
     *
     * @return bool 返回 false，当前执行完成，不再执行后续逻辑，比如降级处理的写入日志文件，推送第三方
     * @throws Exception
     */
    protected function analyseAndContinue(
        string $db_host,
        float  $exec_ms,
        string $sql
    ): bool
    {
        if (app()->environment() === 'local') {
            $is_continue = true;
        } else {
            $is_continue = $exec_ms > 0.1 || random_int(1, 20000) > (20000 - 20);
        }

        if (env('SQL_TRACE_ANALYSE') === true && $this->predis) {
            $sql_key = md5($db_host . $sql);
            $hash_key = 'SQL_TRACE_HASH_KEY:' . date('Ymd');
            $hash_key_incr = 'SQL_TRACE_HASH_KEY_INCR:' . date('Ymd');
            $hash_key_time_incr = 'SQL_TRACE_HASH_KEY_TIME_INCR:' . date('Ymd');
            if (!$this->predis->hExists($hash_key, $sql_key) || $is_continue) {
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

    protected function error(string $error)
    {
        @fwrite($this->fp3, $error);
    }

    protected function checkIsOk(): int
    {
        if (env('SQL_TRACE_ANALYSE') === true && $this->predis === null) {
            return -1;
        }

        return $this->fp1 !== false && $this->fp2 !== false && $this->fp3 !== false ? 0 : -2;
    }
}
