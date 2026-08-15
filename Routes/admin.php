<?php

declare(strict_types=1);

use Convoro\Engine\Http\Middleware\RequireAdmin;
use Convoro\Extensions\Fantasy\Controllers\Admin\FantasyController;

/** @var \Convoro\Engine\Routing\Router $router */

$router->group()
    ->prefix('/admin/fantasy')
    ->middleware(RequireAdmin::class)
    ->group(function ($router) {
        /*
         * 🚨 Every literal path is registered before anything carrying `{id}`,
         * because `{id}` matches the word "settings" as happily as it matches 7.
         */
        $router->get('/', [FantasyController::class, 'index'], 'admin.fantasy');
        $router->post('/settings', [FantasyController::class, 'saveSettings'], 'admin.fantasy.settings');

        $router->post('/{id}/rescore', [FantasyController::class, 'rescore'], 'admin.fantasy.rescore');
        $router->post('/{id}/reschedule', [FantasyController::class, 'reschedule'], 'admin.fantasy.reschedule');
        $router->post('/{id}/adopt', [FantasyController::class, 'adopt'], 'admin.fantasy.adopt');
        $router->post('/{id}/delete', [FantasyController::class, 'delete'], 'admin.fantasy.delete');
    });
