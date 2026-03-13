<?php

declare(strict_types=1);

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/**
 * @var \Cake\Routing\RouteBuilder $routes
 */
$routes->plugin(
    'AuditStash',
    ['path' => '/audit-stash'],
    function (RouteBuilder $builder): void {
        $builder->setRouteClass(DashedRoute::class);

        $builder->prefix('Admin', function (RouteBuilder $builder): void {
            $builder->connect('/', ['controller' => 'AuditLogs', 'action' => 'index']);

            // Related changes route
            $builder->connect(
                '/audit-logs/related-changes/{source}/{primary_key}',
                ['controller' => 'AuditLogs', 'action' => 'relatedChanges'],
                ['pass' => ['source', 'primary_key']],
            );

            // Bulk changes route
            $builder->connect(
                '/audit-logs/bulk-changes',
                ['controller' => 'AuditLogs', 'action' => 'bulkChanges'],
            );

            $builder->fallbacks();
        });

        $builder->fallbacks();
    },
);
