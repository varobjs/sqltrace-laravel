<?php

namespace SQLTrace;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;

class EventHandler
{
    /**
     * @var Dispatcher
     */
    private $events;

    /**
     * EventHandler constructor.
     *
     * @param Dispatcher $events
     * @param array $config
     */
    public function __construct(Dispatcher $events, array $config)
    {
        $this->events = $events;
    }

    public function subscribe(): void
    {
        $this->events->listen(QueryExecuted::class, [$this, 'queryExecuted']);
    }

    public function queryExecuted(QueryExecuted $query): void
    {
        TraceAppSchema::create($query);
    }
}
