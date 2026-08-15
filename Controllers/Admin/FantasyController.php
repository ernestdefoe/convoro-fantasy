<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Controllers\Admin;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;
use Convoro\Extensions\Fantasy\Fantasy;

/**
 * The operator's screen: switch fantasy on, set what a new league starts with,
 * and unstick a league that has gone wrong.
 *
 * 🚨 Never an action called `settings` — the base `Controller` has a protected
 * `settings(): array` and the clash is a fatal at class load rather than
 * anything `php -l` will show you. The path may say settings; the method may
 * not.
 *
 * 🚨 Nothing here changes a league's SCORING. That belongs to the commissioner
 * of that league, and an admin screen that could rewrite it is an admin screen
 * that will be blamed for it. What an admin can do is delete a league or hand it
 * to somebody else — the two things that need authority the commissioner may no
 * longer have.
 */
final class FantasyController extends Controller
{
    private const NOTICE = 'fantasy_admin_notice';
    private const PROBLEM = 'fantasy_admin_problem';

    public function index(Request $request): Response
    {
        $settings = $this->app->make('fantasy.settings');
        $leagues = $this->app->make('fantasy.leagues');

        return $this->render('fantasy::admin/index', [
            'settings' => $settings,
            'leagues' => $leagues->all(),
            'seasons' => $this->app->make('db')->table('picks_seasons')->orderByDesc('year')->get(),
            'notice' => $this->session($request)->getFlash(self::NOTICE),
            'problem' => $this->session($request)->getFlash(self::PROBLEM),
            'picksReady' => $this->app->bound('picks.settings')
                && $this->app->make('picks.settings')->enabled(),
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $this->app->make('fantasy.settings')->save([
            'fantasy_enabled' => $request->input('fantasy_enabled', '0'),
            'fantasy_max_owned' => $request->input('fantasy_max_owned', '2'),
            'fantasy_default_franchises' => $request->input('fantasy_default_franchises', '10'),
            'fantasy_default_roster' => $request->input('fantasy_default_roster', '8'),
            'fantasy_default_starters' => $request->input('fantasy_default_starters', '4'),
            'fantasy_autopick' => $request->input('fantasy_autopick', '0'),
            'fantasy_carry_lineups' => $request->input('fantasy_carry_lineups', '0'),
        ]);

        return $this->done($request, __('fantasy.admin_saved'));
    }

    /**
     * Re-score a league from week one.
     *
     * 🚨 The reason this button exists: the scheduled pass deliberately skips
     * weeks where nothing settled, so a result corrected three weeks ago is
     * never picked up by it. Without this the only fix is editing the database.
     */
    public function rescore(Request $request): Response
    {
        $id = (int) $request->routeParam('id');

        /** @var Fantasy $module */
        $module = $this->app->make('modules')->get('fantasy');

        $result = $module->rescore($id);

        return $this->done($request, __('fantasy.admin_rescored', [
            'weeks' => (string) $result['weeks'],
            'scored' => (string) $result['scored'],
        ]));
    }

    /**
     * Rebuild the fixtures for weeks that have none.
     *
     * Never touches a week that already has matchups, so a league that grew by
     * one franchise gets the remaining weeks re-paired without rewriting results
     * that have been played.
     */
    public function reschedule(Request $request): Response
    {
        $league = $this->app->make('fantasy.leagues')->find((int) $request->routeParam('id'));

        if ($league === null) {
            return $this->failed($request, __('fantasy.no_such_league'));
        }

        $result = $this->app->make('fantasy.schedule')->build($league);

        return $this->done($request, __('fantasy.admin_rescheduled', [
            'weeks' => (string) $result['weeks'],
            'matchups' => (string) $result['matchups'],
        ]));
    }

    /** Give a league to somebody else, for when its commissioner has gone. */
    public function adopt(Request $request): Response
    {
        $league = $this->app->make('fantasy.leagues')->find((int) $request->routeParam('id'));

        if ($league === null) {
            return $this->failed($request, __('fantasy.no_such_league'));
        }

        /*
         * 🚨 The field is `commissioner`, not `username`. A password manager
         * reads the NAME and offers to fill a login regardless of what the
         * attributes say — core's own PasswordManagerTest refuses the latter.
         */
        $username = trim((string) $request->input('commissioner', ''));
        $user = $this->app->make('db')->table('users')->where('username', $username)->first();

        if ($user === null) {
            return $this->failed($request, __('fantasy.no_such_member', ['name' => $username]));
        }

        $this->app->make('db')->table('fantasy_leagues')->where('id', $league['id'])->updateAll([
            'commissioner_id' => (int) $user['id'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->done($request, __('fantasy.admin_adopted', ['name' => $username]));
    }

    /**
     * Delete a league and everything in it.
     *
     * 🚨 Requires the league's own name to be typed back. Every child table
     * cascades from this row — rosters, lineups, matchups, a whole season of
     * scores — and there is no undo. A confirmation that is one click away from
     * a list of leagues is a confirmation that gets clicked on the wrong row.
     */
    public function delete(Request $request): Response
    {
        $league = $this->app->make('fantasy.leagues')->find((int) $request->routeParam('id'));

        if ($league === null) {
            return $this->failed($request, __('fantasy.no_such_league'));
        }

        $typed = trim((string) $request->input('confirm_name', ''));

        if ($typed !== trim((string) $league['name'])) {
            return $this->failed($request, __('fantasy.admin_name_mismatch'));
        }

        $this->app->make('fantasy.leagues')->delete((int) $league['id']);

        return $this->done($request, __('fantasy.admin_deleted', ['name' => (string) $league['name']]));
    }

    private function done(Request $request, string $message): Response
    {
        $this->session($request)->flash(self::NOTICE, $message);

        return $this->redirect('/admin/fantasy');
    }

    private function failed(Request $request, string $message): Response
    {
        $this->session($request)->flash(self::PROBLEM, $message);

        return $this->redirect('/admin/fantasy');
    }
}
