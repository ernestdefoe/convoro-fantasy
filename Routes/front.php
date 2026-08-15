<?php

declare(strict_types=1);

use Convoro\Engine\Http\Middleware\Authenticate;
use Convoro\Extensions\Fantasy\Controllers\Front\DraftController;
use Convoro\Extensions\Fantasy\Controllers\Front\LeagueController;
use Convoro\Extensions\Fantasy\Controllers\Front\LineupController;

/** @var \Convoro\Engine\Routing\Router $router */

/*
 * No middleware on the reads, for the reason Picks gives: whether Fantasy
 * answers at all is a setting and whether a signed-out visitor may look is a
 * permission, so the controller decides rather than the router. An extension
 * that is installed and switched off should 404 like a page that does not
 * exist, not 403 like one somebody is not allowed to see.
 *
 * 🚨 The literal path comes first. `/fantasy/{slug}` would match the word
 * "create" as happily as it matches a league, and the symptom would be "no such
 * league" on the site's own button.
 */
$router->get('/fantasy', [LeagueController::class, 'index'], 'fantasy.index');

$router->group()
    ->middleware(Authenticate::class)
    ->group(function ($router) {
        $router->post('/fantasy/create', [LeagueController::class, 'create'], 'fantasy.create');
    });

$router->get('/fantasy/{slug}', [LeagueController::class, 'show'], 'fantasy.league');
$router->get('/fantasy/{slug}/draft', [DraftController::class, 'show'], 'fantasy.draft');
$router->get('/fantasy/{slug}/lineup', [LineupController::class, 'show'], 'fantasy.lineup');
$router->get('/fantasy/{slug}/franchise/{franchise}', [LeagueController::class, 'franchise'], 'fantasy.franchise');

/*
 * The writes.
 *
 * 🚨 Signed-in at the router, permission-checked in the controller, and then
 * checked AGAIN — against whose turn it is, or against the team's own kickoff —
 * before a single row moves. Three gates rather than one because they answer
 * three different questions, and the third is the only one that is about time.
 */
$router->group()
    ->middleware(Authenticate::class)
    ->group(function ($router) {
        $router->post('/fantasy/{slug}/join', [LeagueController::class, 'join'], 'fantasy.join');
        $router->post('/fantasy/{slug}/leave', [LeagueController::class, 'leave'], 'fantasy.leave');
        $router->post('/fantasy/{slug}/rename', [LeagueController::class, 'rename'], 'fantasy.rename');

        $router->post('/fantasy/{slug}/draft/start', [DraftController::class, 'start'], 'fantasy.draft.start');
        $router->post('/fantasy/{slug}/draft/pick', [DraftController::class, 'pick'], 'fantasy.draft.pick');
        $router->post('/fantasy/{slug}/draft/skip', [DraftController::class, 'skip'], 'fantasy.draft.skip');

        $router->post('/fantasy/{slug}/lineup/start', [LineupController::class, 'start'], 'fantasy.lineup.start');
        $router->post('/fantasy/{slug}/lineup/bench', [LineupController::class, 'bench'], 'fantasy.lineup.bench');
        $router->post('/fantasy/{slug}/lineup/swap', [LineupController::class, 'swap'], 'fantasy.lineup.swap');
    });
