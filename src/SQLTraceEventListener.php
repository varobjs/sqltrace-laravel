<?php /** @noinspection PhpUnusedParameterInspection */

namespace LaravelSQLTrace;

use Illuminate\Database\Events\QueryExecuted;

class SQLTraceEventListener
{
    /** @var resource $fp1 */
    protected $fp1;
    /** @var resource $fp2 */
    protected $fp2;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        ini_set('date.timezone', 'Asia/Shanghai');
        $this->fp1 = fopen('/tmp/sql.log', 'a+');
        $this->fp2 = fopen('/tmp/sql_trace.log', 'a+');
    }

    public function __destruct()
    {
        fclose($this->fp1);
        fclose($this->fp2);
    }

    /**
     * Handle the event.
     *
     * @param QueryExecuted $event
     * @return void
     */
    public function handle(QueryExecuted $event)
    {
        $curr_sql_trace_id = static::get_curr_sql_trace_id();
        $db_host = $event->connection->getConfig('host');
        $exec_time = $event->time;
        $sql = $event->sql;
        $bindings = implode(', ', $event->bindings);

        if (!$this->isLogging($db_host, $exec_time, $sql)) {
            return;
        }

        $this->saveSQLToFile(
            $db_host,
            $exec_time,
            $curr_sql_trace_id,
            $sql,
            $bindings
        );

        $last_trace = $this->saveTraceToFile(
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20),
            $curr_sql_trace_id
        );

        $this->pushLog($db_host, $exec_time, $curr_sql_trace_id, $sql, $bindings, $last_trace);
    }

    /**
     * 保存调用链接，返回sql的实际文件位置
     * @param array $traces
     * @param string $curr_sql_trace_id
     * @return string
     */
    protected function saveTraceToFile(array $traces, string $curr_sql_trace_id): string
    {
        $i = 10;
        $_trace = '';
        while (!empty($traces)) {
            $trace = array_pop($traces);
            if (isset($trace['file']) && strstr($trace['file'], 'vendor') === false) {
                $_trace = $trace['class'];
                $_trace .= $trace['type'];
                $_trace .= $trace['function'];
                isset($trace['file']) && $_trace .= ' at ' . $trace['file'];
                isset($trace['line']) && $_trace .= '@' . $trace['line'];
                // ==> /tmp/sql_trace.log <==
                // [839F2E59]  Illuminate\Routing\Controller->callAction at /example-app/routes/api.php@25
                //             └── Illuminate\Database\Eloquent\Builder->__call at /example-app/app/Http/Controllers/V1/TestController.php@18
                fwrite($this->fp2, sprintf(
                    "%s %s %s\n",
                    $i === 10 ? '[' . $curr_sql_trace_id . ']' : '',
                    $i === 10 ? '' : (str_repeat(' ', $i) . '└──'),
                    $_trace
                ));
                $i++;
            }
        }
        return $_trace;
    }


    /**
     * // ==> /tmp/sql.log
     * // [0D4B491C 839F2E59][2021-07-01/14:58:17,7793][127.0.0.1][1ms]
     * // **************************************************
     * // select count(*) as aggregate from `test` []
     * // **************************************************
     * //
     * @param string $db_host
     * @param float $exec_time
     * @param string $curr_sql_trace_id
     * @param string $sql
     * @param string $bindings
     */
    protected function saveSQLToFile(
        string $db_host,
        float $exec_time,
        string $curr_sql_trace_id,
        string $sql,
        string $bindings
    )
    {
        fwrite($this->fp1, sprintf(
            "\n[%s][%s][%s][%dms]\n%s\n%s [%s]\n%s\n",
            static::get_global_app_trace_id() . ' ' . $curr_sql_trace_id,
            static::get_datetime_ms(),
            $db_host,
            $exec_time,
            str_repeat('*', 50),
            $sql,
            $bindings,
            str_repeat('*', 50)
        ));
    }

    protected static function get_datetime_ms(): string
    {
        return date('Y-m-d/H:i:s.') . (int)(10000 * (microtime(true) - time()));
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
     * @param string $db_host
     * @param float $exec_time
     * @param string $curr_sql_trace_id
     * @param string $sql
     * @param string $bindings
     * @param string $last_trace
     */
    protected function pushLog(
        string $db_host,
        float $exec_time,
        string $curr_sql_trace_id,
        string $sql,
        string $bindings,
        string $last_trace
    ): void
    {
    }

    /**
     * 通过条件判断是否记录此处SQL的信息
     * @param string $db_host
     * @param float $exec_time
     * @param string $sql
     * @return bool
     */
    protected function isLogging(
        string $db_host,
        float $exec_time,
        string $sql
    ): bool
    {
        return true;
    }
}
