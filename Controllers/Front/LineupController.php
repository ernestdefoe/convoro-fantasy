<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Controllers\Front;

use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;

/**
 * Setting a lineup, and picking up a free agent.
 *
 * 🚨 Every write asks the LOCK last, against rows read in this request. A team's
 * game having kicked off is the one fact the browser must never be trusted on,
 * because the page was rendered before kickoff and the button on it still looks
 * live.
 *
 * 🚨 A lineup that is under-filled saves fine. A form that refuses to save until
 * it is complete is a form that loses the three choices somebody had already
 * made on a Wednesday — and the punishment for an incomplete lineup is already
 * built in: it scores less.
 */
final class LineupController extends FantasyController
{
    public function show(Request $request): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        [$franchise, $denied] = $this->myFranchise($request, $league);

        if ($denied !== null) {
            return $denied;
        }

        $week = $this->currentWeek($league);

        if ($week === null) {
            return $this->problem($request, __('fantasy.no_week'), '/fantasy/' . $league['slug']);
        }

        $weekId = (int) $week['id'];
        $lineups = $this->app->make('fantasy.lineups');
        $roster = $this->app->make('fantasy.rosters')->forFranchise((int) $league['id'], (int) $franchise['id']);

        $teamIds = array_map(static fn (array $row): int => (int) $row['team_id'], $roster);
        $games = $lineups->gamesFor($weekId, $teamIds);
        $starting = $lineups->starting((int) $league['id'], (int) $franchise['id'], $weekId);
        $now = time();

        $teams = [];

        foreach ($roster as $row) {
            $teamId = (int) $row['team_id'];
            $game = $games[$teamId] ?? null;

            $teams[] = $row + [
                'is_starting' => in_array($teamId, $starting, true),
                'is_movable' => $lineups->movable($teamId, $games, $now),
                'game' => $game,

                /*
                 * The opponent's name, resolved here rather than in the
                 * template. A template doing its own lookup is a query per row
                 * on a page that already has the whole roster in hand.
                 */
                'opponent' => $game === null ? null : $this->teamName((int) $game['opponent_id']),
                'kickoff_at' => $game === null ? 0 : (int) $game['match_at'],
                'locks_at' => $game === null ? 0 : (int) $game['cutoff_at'],
            ];
        }

        return $this->render('fantasy::front/lineup', $this->shell($request) + [
            'league' => $league,
            'franchise' => $franchise,
            'week' => $week,
            'teams' => $teams,
            'startingCount' => count($starting),
            'freeAgents' => $this->app->make('fantasy.rosters')->freeAgents(
                (int) $league['id'],
                (string) $request->query('q', ''),
                60
            ),
            'search' => (string) $request->query('q', ''),
            'rosterFull' => count($roster) >= (int) $league['roster_size'],
        ]);
    }

    public function start(Request $request): Response
    {
        return $this->move($request, true);
    }

    public function bench(Request $request): Response
    {
        return $this->move($request, false);
    }

    /** Take a free agent, dropping somebody if the roster is full. */
    public function swap(Request $request): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        [$franchise, $denied] = $this->myFranchise($request, $league);

        if ($denied !== null) {
            return $denied;
        }

        $week = $this->currentWeek($league);
        $where = '/fantasy/' . $league['slug'] . '/lineup';

        if ($week === null) {
            return $this->problem($request, __('fantasy.no_week'), $where);
        }

        $result = $this->app->make('fantasy.rosters')->swap(
            $league,
            (int) $franchise['id'],
            (int) $request->input('add_team_id', 0),
            (int) $request->input('drop_team_id', 0),
            (int) $week['id']
        );

        if (!$result['ok']) {
            return $this->problem($request, $this->say((string) $result['problem']), $where);
        }

        return $this->notice($request, __('fantasy.roster_changed'), $where);
    }

    /* ------------------------------------------------------------- private */

    private function move(Request $request, bool $starting): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        [$franchise, $denied] = $this->myFranchise($request, $league);

        if ($denied !== null) {
            return $denied;
        }

        $week = $this->currentWeek($league);
        $where = '/fantasy/' . $league['slug'] . '/lineup';

        if ($week === null) {
            return $this->problem($request, __('fantasy.no_week'), $where);
        }

        $lineups = $this->app->make('fantasy.lineups');
        $teamId = (int) $request->input('team_id', 0);

        $result = $starting
            ? $lineups->start($league, (int) $franchise['id'], (int) $week['id'], $teamId)
            : $lineups->bench($league, (int) $franchise['id'], (int) $week['id'], $teamId);

        if (!$result['ok']) {
            return $this->problem($request, $this->say((string) $result['problem']), $where);
        }

        return $this->notice(
            $request,
            $starting ? __('fantasy.team_started') : __('fantasy.team_benched'),
            $where
        );
    }

    private function teamName(int $teamId): string
    {
        if ($teamId < 1) {
            return '';
        }

        $row = $this->app->make('db')->table('picks_teams')->where('id', $teamId)->first();

        return $row === null ? '' : (string) $row['name'];
    }
}
