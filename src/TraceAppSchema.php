<?php

namespace SQLTrace;

use Illuminate\Database\Events\QueryExecuted;

class TraceAppSchema
{
    protected $app_uuid;
    protected $app_name;
    protected $run_host;
    protected $run_mode;
    protected $pid;
    protected $referer;
    protected $request_uri;
    protected $request_query;
    protected $request_post;

    protected $trace_sql = [];

    public function __construct()
    {
        $this->app_uuid = Utils::static_uuid();
        $this->app_name = config('app.name', 'default') ?? 'no-app-name';
        $this->run_host = gethostname();
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
        $this->request_query = json_encode($_GET);
        $this->request_post = json_encode($_POST);
    }

    /**
     * @var TraceAppSchema|null $instance
     */
    protected static $instance;

    /**
     * @param QueryExecuted $event
     * @return TraceAppSchema|null
     */
    public static function create(QueryExecuted $event): ?TraceAppSchema
    {
        if (null === static::$instance) {
            static::$instance = new TraceAppSchema();
            Log::getInstance()->info('trace-app', static::$instance->toArray());
        }
        $sql = TraceSqlSchema::create(
            static::$instance->app_uuid,
            $event
        );
        static::$instance->addTraceSql($sql->toArray());

        return static::$instance;
    }

    protected function addTraceSql(array $trace_sql): void
    {
        $this->trace_sql[] = $trace_sql;
    }

    public function toArray(): array
    {
        return [
            'app_uuid' => $this->app_uuid,
            'app_name' => $this->app_name,
            'run_host' => $this->run_host,
            'run_mode' => $this->run_mode,
            'pid' => $this->pid,
            'referer' => $this->referer,
            'request_uri' => $this->request_uri,
            'request_query' => $this->request_query,
            'request_post' => $this->request_post,
        ];
    }
}