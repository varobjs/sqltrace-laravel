<?php

namespace SQLTrace;

use Illuminate\Database\Events\QueryExecuted;

class TraceSchema
{
    protected $app_uuid;
    protected $sql_uuid;
    protected $app_name;
    protected $run_host;
    protected $run_mode;
    protected $pid;
    protected $request_uri;
    protected $referer;
    protected $created_at;
    protected $trace_files = [];

    protected $db_host;
    protected $run_ms;
    protected $trace_sql;

    /**
     * @param QueryExecuted $event
     *
     * @return ?TraceSchema
     */
    public static function create(QueryExecuted $event): ?TraceSchema
    {
        $schema = new TraceSchema();
        $schema->setHost($event->connection->getConfig('host'));
        $sql = $event->sql;
        foreach ($event->bindings as $binding) {
            $value = is_numeric($binding) ? $binding : "'" . $binding . "'";
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }
        $schema->setSql($sql);
        $schema->setRunMs($event->time);

        return $schema;
    }

    public function __construct()
    {
        $this->created_at = Utils::get_datetime_ms();
        $this->run_host = gethostname();
        $this->app_uuid = Utils::static_uuid();
        $this->sql_uuid = Utils::uuid();
        $this->app_name = config('app.name', 'default') ?? 'no-app-name';
        $this->run_mode = PHP_SAPI;
        $this->pid = getmypid();

        if ($this->run_mode === 'cli') {
            global $argv;
            $this->request_uri = implode(' ', $argv);
            $this->referer = '';
        } else {
            $_uri = $_SERVER['REQUEST_URI'] ?? '';
            $_uri = explode('?', $_uri)[0] ?? '';
            $this->request_uri = sprintf("%s %s", $_SERVER['REQUEST_METHOD'] ?? '', $_uri);
            $this->referer = $_SERVER['HTTP_REFERER'] ?? '';
        }

        $logback = $this->format_traces(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        foreach ($logback as $item) {
            $this->trace_files[] = [
                'trace_file' => $item['file'] ?? '',
                'trace_line' => $item['line'] ?? '',
                'trace_class' => $item['class'] ?? '',
                'created_at' => Utils::get_datetime_ms(),
            ];
        }
    }

    protected function format_traces(array $traces): array
    {
        $format_traces = [];
        while (!empty($traces)) {
            $trace = array_pop($traces);
            $skip_folder = 'vendor';
            if (isset($trace['file']) && strpos($trace['file'] ?? '', $skip_folder) === false) {
                $format_trace = [
                    'file' => $trace['file'] ?: '',
                    'line' => $trace['line'] ?? 0,
                    'class' => $trace['class'] . $trace['type'] . $trace['function'] . '(..)'
                ];
                $format_traces[] = $format_trace;
            }
        }
        return $format_traces;
    }

    public function setHost($host): void
    {
        $this->db_host = $host;
    }

    public function setSql(string $sql): void
    {
        $this->trace_sql = $sql;
    }

    public function setRunMs(float $ms): void
    {
        $this->run_ms = $ms;
    }

    public function toArray(): array
    {
        $data = [];
        foreach ($this as $k => $v) {
            $data[$k] = $v;
        }
        return $data;
    }
}