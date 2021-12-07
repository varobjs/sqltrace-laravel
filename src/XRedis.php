<?php

namespace LaravelSQLTrace;

use Redis;
use RuntimeException;

class XRedis
{
    protected static $_singletonStack = [];

    /**
     * @param mixed $params
     * @param bool  $refresh
     *
     * @return static
     */
    public static function getInstance($params = null, bool $refresh = false): self
    {
        $class = static::class;
        $key = md5($class . serialize($params));
        if (!$refresh && !empty(static::$_singletonStack[$key])) {
            return static::$_singletonStack[$key];
        }

        if ($params) {
            static::$_singletonStack[$key] = new $class($params);
        } else {
            static::$_singletonStack[$key] = new $class();
        }
        return static::$_singletonStack[$key];
    }

    /**
     * @var Redis
     */
    public $predis;

    public function __construct()
    {
        $host = SQLTraceEventListener::getEnv('redis_host');
        $port = SQLTraceEventListener::getEnv('redis_port');
        if (!$host && !$port) {
            throw new RuntimeException('redis配置不正确');
        }
        $password = SQLTraceEventListener::getEnv('redis_password');

        $this->predis = new Redis();
        $this->predis->connect($host, $port, 1);
        if ($password) {
            $this->predis->auth($password);
        }
    }
}
