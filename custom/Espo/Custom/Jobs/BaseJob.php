<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Application;
use Espo\Core\Utils\Log;
use Espo\Core\ORM\EntityManager;

/**
 * Base helper for all custom jobs.
 * Provides safe access to logger & services.
 */
abstract class BaseJob
{
    /**
     * Get Espo logger safely.
     */
    protected function log(): Log
    {
        return Application::getContainer()->get('log');
    }

    /**
     * Get EntityManager safely.
     */
    protected function em(): EntityManager
    {
        return Application::getContainer()->get('entityManager');
    }

    /**
     * Shortcut debug logger.
     */
    protected function debug(string $message): void
    {
        $this->log()->debug($message);
    }

    /**
     * Shortcut info logger.
     */
    protected function info(string $message): void
    {
        $this->log()->info($message);
    }

    /**
     * Shortcut error logger.
     */
    protected function error(string $message): void
    {
        $this->log()->error($message);
    }
}
