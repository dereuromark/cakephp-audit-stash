<?php
declare(strict_types=1);

namespace AuditStash;

use Cake\Core\BasePlugin;
use Cake\Core\PluginApplicationInterface;

/**
 * Plugin class for AuditStash
 */
class AuditStashPlugin extends BasePlugin
{
    /**
     * Plugin name.
     *
     * @var string
     */
    protected string $name = 'AuditStash';

    /**
     * Do bootstrapping or not
     *
     * @var bool
     */
    protected bool $bootstrapEnabled = false;

    /**
     * Load routes or not
     *
     * @var bool
     */
    protected bool $routesEnabled = false;

    /**
     * Console middleware
     *
     * @var bool
     */
    protected bool $consoleEnabled = true;

    /**
     * Enable middleware
     *
     * @var bool
     */
    protected bool $middlewareEnabled = false;
}
