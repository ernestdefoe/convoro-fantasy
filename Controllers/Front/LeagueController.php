<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Controllers\Front;

use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;

/**
 * The lobby, one league's home page, and its transaction log.
 *
 * 🚨 Not one outbound call anywhere in this extension's front end. Every figure
 * on these pages is a row the scheduled scoring pass wrote from fixtures Picks
 * had already fetched, and the page says how old that is rather than pretending
 * it is the present.
 *
 * 🚨 Whether a league is visible and whether it may be JOINED are separate
 * questions, and this file keeps them separate. A private league is listed by
 * name so nobody wonders where their invitation went; the join code is what
 * gets you in. Hiding it entirely means a member who was told about it cannot
 * find out it exists.
 */
final class LeagueController extends FantasyController
{
    /** The lobby: every league in the current season. */
    public function index(Request $request): Response
    {
        if (($gate = $this->gateway($request)) !== null) {
            return $gate;
        }

        $viewer = $this->user($request);
        $leagues = $this->app->make('fantasy.leagues');

        $season = $this->currentSeason();
        $rows = $leagues->all($season === null ? null : (int) $season['id']);

        $mine = [];

        if ($viewer !== null) {
            foreach ($rows as $league) {
                if ($leagues->franchise((int) $league['id'], (int) $viewer['id']) !== null) {
                    $mine[(int) $league['id']] = true;
                }
            }
        }

        return $this->render('fantasy::front/index', $this->shell($request) + [
            'leagues' => $rows,
            'mine' => $mine,
            'season' => $season,
            'mayCreate' => $viewer !== null && $this->app->make('gate')->can($viewer, 'fantasy.create'),
        ]);
    }

    /** One league: the standings, this week's matchups, and my franchise. */
    public function show(Request $request): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        $viewer = $this->user($request);
        $leagues = $this->app->make('fantasy.leagues');
        $schedule = $this->app->make('fantasy.schedule');
        $rosters = $this->app->make('fantasy.rosters');

        $week = $this->currentWeek($league);
        $weekId = $week === null ? 0 : (int) $week['id'];

        $mine = $viewer === null ? null : $leagues->franchise((int) $league['id'], (int) $viewer['id']);

        return $this->render('fantasy::front/league', $this->shell($request) + [
            'league' => $league,
            'week' => $week,
            'standings' => $schedule->standings((int) $league['id']),
            'matchups' => $weekId > 0 ? $schedule->forWeek((int) $league['id'], $weekId) : [],
            'franchises' => $leagues->franchises((int) $league['id']),
            'mine' => $mine,
            'moves' => $rosters->moves((int) $league['id'], 12),
            'isCommissioner' => $leagues->isCommissioner($league, $viewer),
            'isFull' => $leagues->isFull($league),
            'mayPlay' => $viewer !== null && $this->app->make('gate')->can($viewer, 'fantasy.play'),
        ]);
    }

    /** One franchise's roster and week-by-week record. */
    public function franchise(Request $request): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        $leagues = $this->app->make('fantasy.leagues');
        $franchise = $leagues->franchiseById((int) $request->routeParam('franchise'));

        if ($franchise === null || $franchise['league_id'] !== (int) $league['id']) {
            return Response::notFound(__('fantasy.no_such_franchise'));
        }

        $week = $this->currentWeek($league);
        $weekId = $week === null ? 0 : (int) $week['id'];

        $viewer = $this->user($request);

        return $this->render('fantasy::front/franchise', $this->shell($request) + [
            'league' => $league,
            'franchise' => $franchise,
            'roster' => $this->app->make('fantasy.rosters')->forFranchise((int) $league['id'], (int) $franchise['id']),
            'starting' => $weekId > 0
                ? $this->app->make('fantasy.lineups')->starting((int) $league['id'], (int) $franchise['id'], $weekId)
                : [],
            'week' => $week,
            'breakdown' => $weekId > 0
                ? $this->app->make('fantasy.scoring')->breakdown((int) $league['id'], (int) $franchise['id'], $weekId)
                : [],
            'isMine' => $viewer !== null && (int) $franchise['user_id'] === (int) $viewer['id'],
        ]);
    }

    /* -------------------------------------------------------------- writes */

    public function create(Request $request): Response
    {
        if (($gate = $this->gateway($request)) !== null) {
            return $gate;
        }

        $viewer = $this->user($request);

        if ($viewer === null || !$this->app->make('gate')->can($viewer, 'fantasy.create')) {
            return Response::forbidden(__('fantasy.may_not_create'));
        }

        $settings = $this->app->make('fantasy.settings');
        $leagues = $this->app->make('fantasy.leagues');

        $owned = $this->app->make('db')->table('fantasy_leagues')
            ->where('commissioner_id', (int) $viewer['id'])
            ->count();

        if ($owned >= $settings->maxOwned()) {
            return $this->problem($request, __n('fantasy.too_many_owned', $settings->maxOwned()), '/fantasy');
        }

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->problem($request, __('fantasy.league_needs_a_name'), '/fantasy');
        }

        $season = $this->currentSeason();

        if ($season === null) {
            return $this->problem($request, __('fantasy.no_season'), '/fantasy');
        }

        $league = $leagues->create([
            'name' => $name,
            'description' => $request->input('description', ''),
            'season_id' => (int) $season['id'],
            'max_franchises' => $request->input('max_franchises', $settings->defaultFranchises()),
            'roster_size' => $request->input('roster_size', $settings->defaultRoster()),
            'starters' => $request->input('starters', $settings->defaultStarters()),
            'is_public' => $request->input('is_public', '1'),
            'draft_type' => $request->input('draft_type', 'snake'),
        ], (int) $viewer['id'], $settings);

        return $this->notice($request, __('fantasy.league_created'), '/fantasy/' . $league['slug']);
    }

    public function join(Request $request): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        $viewer = $this->user($request);

        if ($viewer === null || !$this->app->make('gate')->can($viewer, 'fantasy.play')) {
            return Response::forbidden(__('fantasy.may_not_play'));
        }

        $leagues = $this->app->make('fantasy.leagues');
        $where = '/fantasy/' . $league['slug'];

        if (!$leagues->mayChangeRules($league)) {
            return $this->problem($request, __('fantasy.draft_has_started'), $where);
        }

        if ($leagues->isFull($league)) {
            return $this->problem($request, __('fantasy.league_is_full'), $where);
        }

        /*
         * 🚨 The code is compared case-insensitively and with spaces stripped.
         * It gets read aloud in a thread and typed on a phone; refusing
         * "abc 123" for a code of "ABC123" is a support message, not security.
         */
        if (!$league['is_public']) {
            $given = strtoupper(preg_replace('/\s+/', '', (string) $request->input('join_code', '')) ?? '');

            if ($given !== strtoupper((string) $league['join_code'])) {
                return $this->problem($request, __('fantasy.wrong_code'), $where);
            }
        }

        $leagues->join((int) $league['id'], (int) $viewer['id'], (string) $request->input('team_name', ''));

        return $this->notice($request, __('fantasy.joined'), $where);
    }

    public function rename(Request $request): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        $viewer = $this->user($request);
        $leagues = $this->app->make('fantasy.leagues');
        $mine = $viewer === null ? null : $leagues->franchise((int) $league['id'], (int) $viewer['id']);

        if ($mine === null) {
            return Response::forbidden(__('fantasy.not_in_this_league'));
        }

        $leagues->rename((int) $mine['id'], (string) $request->input('team_name', ''));

        return $this->notice($request, __('fantasy.franchise_renamed'), '/fantasy/' . $league['slug']);
    }

    public function leave(Request $request): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        $viewer = $this->user($request);
        $leagues = $this->app->make('fantasy.leagues');
        $mine = $viewer === null ? null : $leagues->franchise((int) $league['id'], (int) $viewer['id']);
        $where = '/fantasy/' . $league['slug'];

        if ($mine === null) {
            return Response::forbidden(__('fantasy.not_in_this_league'));
        }

        if (!$leagues->leave($league, (int) $mine['id'])) {
            return $this->problem($request, __('fantasy.cannot_leave_now'), $where);
        }

        return $this->notice($request, __('fantasy.left'), '/fantasy');
    }
}
