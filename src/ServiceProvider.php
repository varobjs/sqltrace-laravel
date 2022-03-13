<?php

namespace SQLTrace;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;

class ServiceProvider extends IlluminateServiceProvider
{
    /**
     * Abstract type to bind Sentry as in the Service Container.
     *
     * @var string
     */
    public static $abstract = 'SQLTrace';

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->app->make(static::$abstract);

        if ($this->hasDsnSet()) {
            $this->bindEvents();
        }
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sqltrace.php', static::$abstract);
    }

    /**
     * Bind to the Laravel event dispatcher to log events.
     */
    protected function bindEvents(): void
    {
        $userConfig = $this->getUserConfig();

        $handler = new EventHandler($this->app->events, $userConfig);

        $handler->subscribe();
    }

    /**
     * Check if a DSN was set in the config.
     *
     * @return bool
     */
    protected function hasDsnSet(): bool
    {
        $config = $this->getUserConfig();

        return !empty($config['dsn']);
    }

    /**
     * Retrieve the user configuration.
     *
     * @return array
     */
    private function getUserConfig(): array
    {
        $config = $this->app['config'][static::$abstract];

        return empty($config) ? [] : $config;
    }

    public function provides(): array
    {
        return [static::$abstract];
    }
}
