<?php

declare(strict_types=1);

namespace AuditStash;

use AuditStash\Command\CleanupCommand;
use AuditStash\Monitor\AuditMonitor;
use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\PluginApplicationInterface;
use Cake\Event\EventManager;
use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/**
 * Plugin class for AuditStash
 */
class AuditStashPlugin extends BasePlugin
{
    /**
     * Default JSON encoding flags for storing data.
     *
     * - JSON_UNESCAPED_UNICODE: Store UTF-8 characters directly (ö instead of \u00f6)
     * - JSON_UNESCAPED_SLASHES: Don't escape forward slashes
     * - JSON_PRESERVE_ZERO_FRACTION: Keep 10.0 as 10.0 instead of 10
     *
     * @var int
     */
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Plugin name.
     *
     * @var string|null
     */
    protected ?string $name = 'AuditStash';

    /**
     * Do bootstrapping or not
     *
     * @var bool
     */
    protected bool $bootstrapEnabled = true;

    /**
     * Load routes or not
     *
     * @var bool
     */
    protected bool $routesEnabled = true;

    /**
     * Enable middleware
     *
     * @var bool
     */
    protected bool $middlewareEnabled = false;

    /**
     * Bootstrap the plugin.
     *
     * Registers the AuditMonitor event listener if monitoring is enabled.
     *
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        EventManager::instance()->on(new AuditMonitor());
    }

    /**
     * Add routes for the plugin.
     *
     * Routes are loaded automatically when the plugin is loaded with an Admin prefix.
     * To disable these routes, set 'routes' => false when loading the plugin:
     *
     * ```
     * $this->addPlugin('AuditStash', ['routes' => false]);
     * ```
     *
     * Routes are available at:
     * - /admin/audit-logs
     * - /admin/audit-logs/view/{id}
     * - /admin/audit-logs/timeline/{source}/{primaryKey}
     * - /admin/audit-logs/export
     *
     * @param \Cake\Routing\RouteBuilder $routes The route builder to update.
     *
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->prefix('Admin', function (RouteBuilder $routes): void {
            $routes->plugin('AuditStash', ['path' => '/audit-logs'], function (RouteBuilder $routes): void {
                $routes->setRouteClass(DashedRoute::class);

                // Audit Logs viewer routes
                $routes->connect('/', ['controller' => 'AuditLogs', 'action' => 'index']);
                $routes->connect('/view/{id}', ['controller' => 'AuditLogs', 'action' => 'view'])
                    ->setPass(['id'])
                    ->setPatterns(['id' => '[0-9]+']);
                $routes->connect('/timeline/{source}/{primaryKey}', ['controller' => 'AuditLogs', 'action' => 'timeline'])
                    ->setPass(['source', 'primaryKey']);
                $routes->connect('/export', ['controller' => 'AuditLogs', 'action' => 'export'])
                    ->setExtensions(['csv', 'json']);

                $routes->fallbacks();
            });
        });
    }

    /**
     * Register console commands for the plugin.
     *
     * @param \Cake\Console\CommandCollection $commands The command collection to update.
     *
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        $commands->add('audit_stash cleanup', CleanupCommand::class);

        return $commands;
    }
}
