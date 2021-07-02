<?php

namespace LaravelSQLTrace;

use Phalcon\Storage\Exception;
use Phalcon\Storage\SerializerFactory;
use Redis;
use Varobj\XP\Exception\SystemConfigException;
use Varobj\XP\Exception\UsageErrorException;

/**
 * XRedis 工具类,单例模式 && 默认读取配置
 *
 * Usage:
 *
 * ```php
 * // 自定义配置
 * $redisWithConfig = XRedis::getInstance( $configParams );
 * // 默认配置 .env file ( REDIS_* )
 * $predis = XRedis::getInstance()->predis;
 *
 * // 三者区别
 * // 1. $xRedis 高层,封装 lock unlock 等复杂业务
 * // 3. $predis 低层,基于 php-redis 支持多种命令
 *
 * // `set abc test 10`
 * $predis->set('abc', 'test', 10)
 * $predis->set('key', 'value', ['nx', 'ex' => 10])
 * $predis->eval('luaScript', $arguments, $keySize);
 * ```
 */
class XRedis
{
    protected static $_singletonStack = [];

    /**
     * @param mixed $params
     * @param bool $refresh
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

    /**
     * XRedis constructor.
     * @param array $params
     * @throws \Exception
     */
    public function __construct(array $params = [])
    {
        $host = $params['host'] ?? env('SQL_TRACE_REDIS_HOST', env('REDIS_HOST', ''));
        $port = $params['port'] ?? env('SQL_TRACE_REDIS_PORT', env('REDIS_PORT', 6379));
        if (!$host && !$port) {
            throw new \Exception('redis配置不正确');
        }
        $password = $params['password'] ?? env('SQL_TRACE_REDIS_PASSWORD', env('REDIS_PASSWORD', ''));

        $this->predis = new Redis();
        $this->predis->connect($host, $port, 1);
        if ($password) {
            $this->predis->auth($password);
        }
    }
}
