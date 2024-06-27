<?php

namespace Valet;

class RedisServer
{
    public function __construct(
        public Brew $brew
    )
    {
    }

    /**
     * Install the Redis server.
     */
    public function install(): void
    {
        $this->brew->installOrFail('redis');
        $this->brew->restartService('redis');
    }

    /**
     * Start the Redis server.
     */
    public function stop(): void
    {
        $this->brew->stopService('redis');
    }

    /**
     * Restart the Redis server.
     */
    public function restart(): void
    {
        $this->brew->restartService('redis');
    }

    /**
     * Stop the Redis server.
     */
    public function uninstall(): void
    {
        $this->brew->uninstallFormula('redis');
    }
}
