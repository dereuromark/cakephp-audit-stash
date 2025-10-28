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
            $builder->fallbacks();
        });

        $builder->fallbacks();
    },
);
