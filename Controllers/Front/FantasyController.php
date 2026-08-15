<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Controllers\Front;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;

/**
 * What every front-end screen in this extension needs before it can answer.
 *
 * 🚨 The three gates are asked in a fixed order and each one means something
 * different: **switched off** is a 404, because an extension that is installed
 * and not in use should look like a page that does not exist rather than one
 * somebody is not allowed to see; **not allowed to look** is a 403; **no such
 * league** is a 404 again. Getting that order wrong leaks whether a private
 * league exists to somebody who may not see any of them.
 *
 * 🚨 Picks being present is checked too, and separately. Fantasy declares it in
 * the manifest so it cannot normally be missing — but an operator who disables
 * Picks while leaving Fantasy on would otherwise get a fatal on a missing
 * binding rather than a page saying what is wrong.
 */
abstract class FantasyController extends Controller
{
    protected const NOTICE = 'fantasy_notice';
    protected const PROBLEM = 'fantasy_problem';

    /**
     * The reason this request cannot be answered at all, or null to carry on.
     */
    protected function gateway(Request $request): ?Response
    {
        if (!$this->app->make('fantasy.settings')->enabled()) {
            return Response::notFound(__('fantasy.not_found'));
        }

        if (!$this->app->bound('picks.seasons')) {
            return Response::notFound(__('fantasy.needs_picks'));
        }

        $viewer = $this->user($request);

        if (!$this->app->make('gate')->can($viewer, 'fantasy.view')) {
            return Response::forbidden(__('fantasy.may_not_view'));
        }

        return null;
    }

    /**
     * The league named in the route, or the response explaining why not.
     *
     * @return array{0: array<string, mixed>, 1: null}|array{0: array<string, mixed>, 1: Response}
     */
    protected function league(Request $request): array
    {
        if (($gate = $this->gateway($request)) !== null) {
            return [[], $gate];
        }

        $league = $this->app->make('fantasy.leagues')->bySlug((string) $request->routeParam('slug'));

        if ($league === null) {
            return [[], Response::notFound(__('fantasy.no_such_league'))];
        }

        return [$league, null];
    }

    /**
     * The franchise the signed-in member plays in this league, or the response
     * explaining why they may not act.
     *
     * @param array<string, mixed> $league
     * @return array{0: array<string, mixed>, 1: null}|array{0: array<string, mixed>, 1: Response}
     */
    protected function myFranchise(Request $request, array $league): array
    {
        $viewer = $this->user($request);

        if ($viewer === null || !$this->app->make('gate')->can($viewer, 'fantasy.play')) {
            return [[], Response::forbidden(__('fantasy.may_not_play'))];
        }

        $franchise = $this->app->make('fantasy.leagues')->franchise((int) $league['id'], (int) $viewer['id']);

        if ($franchise === null) {
            return [[], Response::forbidden(__('fantasy.not_in_this_league'))];
        }

        return [$franchise, null];
    }

    /**
     * The season leagues are being played in.
     *
     * 🚨 Read-only, and that is the whole reason this exists rather than calling
     * Picks' `seasonForYear()`. That method CREATES the season row when it is
     * missing, which is correct for a sync and completely wrong for a page
     * render: a signed-out visitor loading the lobby would write a season into
     * the database.
     *
     * The open week's season if there is one, because that is what people are
     * actually playing; otherwise the most recent, so an out-of-season lobby
     * shows last year rather than nothing.
     *
     * @return array<string, mixed>|null
     */
    protected function currentSeason(): ?array
    {
        $seasons = $this->app->make('picks.seasons');
        $week = $seasons->currentWeek();

        if ($week !== null) {
            $season = $seasons->season((int) $week['season_id']);

            if ($season !== null) {
                return $season;
            }
        }

        return $seasons->all()[0] ?? null;
    }

    /**
     * The week a league is currently playing.
     *
     * 🚨 Picks' own idea of the current week, not one this extension works out.
     * Two answers to "what week is it" is how a lineup screen and a scoring pass
     * end up disagreeing, and the member is the one who finds out.
     *
     * @param array<string, mixed> $league
     * @return array<string, mixed>|null
     */
    protected function currentWeek(array $league): ?array
    {
        $week = $this->app->make('picks.seasons')->currentWeek();

        if ($week !== null && (int) $week['season_id'] === (int) $league['season_id']) {
            return $week;
        }

        // The league is playing a season that is not the live one — a finished
        // year being looked back at. Its last week is the useful answer.
        return $this->app->make('db')->table('picks_weeks')
            ->where('season_id', (int) $league['season_id'])
            ->orderByDesc('week_number')
            ->first();
    }

    /** Common template data every screen in this extension renders with. */
    protected function shell(Request $request): array
    {
        return [
            'notice' => $this->session($request)->getFlash(self::NOTICE),
            'problem' => $this->session($request)->getFlash(self::PROBLEM),
            'viewer' => $this->user($request),
            'scoredAt' => $this->app->make('fantasy.settings')->scoredAt(),
        ];
    }

    protected function notice(Request $request, string $message, string $where): Response
    {
        $this->session($request)->flash(self::NOTICE, $message);

        return $this->redirect($where);
    }

    protected function problem(Request $request, string $message, string $where): Response
    {
        $this->session($request)->flash(self::PROBLEM, $message);

        return $this->redirect($where);
    }

    /**
     * Turn a service's problem code into words.
     *
     * 🚨 One mapping, shared by every controller. A problem code that has no
     * phrase falls back to a generic one rather than printing the code — a
     * member seeing `not_your_turn` on screen is a bug that reads as contempt.
     */
    protected function say(string $problem): string
    {
        $key = 'fantasy.problem_' . $problem;
        $phrase = __($key);

        return $phrase === $key ? __('fantasy.problem_generic') : $phrase;
    }
}
