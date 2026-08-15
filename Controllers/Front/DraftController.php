<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Controllers\Front;

use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;

/**
 * The draft room.
 *
 * 🚨 Whose turn it is comes from the database on every request, never from the
 * page. The board is twelve people refreshing the same screen and all wanting
 * the same team; anything the browser tells this controller about whose turn it
 * is, is a claim by one of them.
 *
 * 🚨 The commissioner starting the draft and a member making a pick are
 * different permissions in the same file, and both are checked against the
 * league row rather than a group. Commissioner is a property of THIS league —
 * permissions cannot express that, so it is not a permission.
 */
final class DraftController extends FantasyController
{
    public function show(Request $request): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        $viewer = $this->user($request);
        $leagues = $this->app->make('fantasy.leagues');
        $draft = $this->app->make('fantasy.draft');

        $slot = (string) $league['status'] === 'drafting'
            ? $draft->onTheClock((int) $league['id'])
            : null;

        $mine = $viewer === null ? null : $leagues->franchise((int) $league['id'], (int) $viewer['id']);

        $franchises = [];

        foreach ($leagues->franchises((int) $league['id']) as $franchise) {
            $franchises[(int) $franchise['id']] = $franchise;
        }

        // Read once. The board is the biggest query on this page and the
        // template needs it grouped as well as flat.
        $board = $draft->board((int) $league['id']);

        return $this->render('fantasy::front/draft', $this->shell($request) + [
            'league' => $league,
            'board' => $board,
            'teamNames' => $this->teamNames($board),
            'franchises' => $franchises,
            'slot' => $slot,
            'onTheClockName' => $slot === null
                ? ''
                : (string) ($franchises[$slot['franchise_id']]['display_name'] ?? ''),
            'deadline' => $draft->deadline($league, $slot),
            'mine' => $mine,
            'isMyTurn' => $slot !== null && $mine !== null && $slot['franchise_id'] === (int) $mine['id'],
            'available' => (string) $league['status'] === 'drafting'
                ? $draft->available((int) $league['id'], (string) $request->query('q', ''))
                : [],
            'search' => (string) $request->query('q', ''),
            'isCommissioner' => $leagues->isCommissioner($league, $viewer),
            'teamsByRound' => $this->rounds($board),
        ]);
    }

    /** Commissioner: draw the order and lay out the board. */
    public function start(Request $request): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        $viewer = $this->user($request);
        $where = '/fantasy/' . $league['slug'] . '/draft';

        if (!$this->mayRun($request, $league, $viewer)) {
            return Response::forbidden(__('fantasy.not_the_commissioner'));
        }

        $result = $this->app->make('fantasy.draft')->start($league);

        if (!$result['ok']) {
            return $this->problem($request, $this->say((string) $result['problem']), $where);
        }

        /*
         * 🚨 The schedule is built here, not when the draft finishes. A league
         * that can see who it plays in week one while it is still drafting is a
         * league where people know what they are drafting for — and building it
         * at the end would mean a crash between the last pick and the schedule
         * leaves an active league with no fixtures at all.
         */
        $this->app->make('fantasy.schedule')->build($league);

        return $this->notice($request, __n('fantasy.draft_started', (int) $result['slots']), $where);
    }

    /** Make a pick. */
    public function pick(Request $request): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        [$franchise, $denied] = $this->myFranchise($request, $league);

        if ($denied !== null) {
            return $denied;
        }

        $where = '/fantasy/' . $league['slug'] . '/draft';

        $result = $this->app->make('fantasy.draft')->pick(
            $league,
            (int) $franchise['id'],
            (int) $request->input('team_id', 0)
        );

        if (!$result['ok']) {
            return $this->problem($request, $this->say((string) $result['problem']), $where);
        }

        return $this->notice($request, __('fantasy.pick_made'), $where);
    }

    /**
     * Commissioner: fill the current slot because somebody is not coming back.
     *
     * 🚨 Exists even when the automatic clock is switched on, because the clock
     * only runs when the league set one. A league that chose no clock and then
     * stalled has no other way out except an admin editing the database.
     */
    public function skip(Request $request): Response
    {
        [$league, $error] = $this->league($request);

        if ($error !== null) {
            return $error;
        }

        $viewer = $this->user($request);
        $where = '/fantasy/' . $league['slug'] . '/draft';

        if (!$this->mayRun($request, $league, $viewer)) {
            return Response::forbidden(__('fantasy.not_the_commissioner'));
        }

        $result = $this->app->make('fantasy.draft')->autopick($league);

        if (!$result['ok']) {
            return $this->problem($request, $this->say((string) $result['problem']), $where);
        }

        return $this->notice($request, __('fantasy.pick_made_for_them'), $where);
    }

    /* ------------------------------------------------------------- private */

    /**
     * Whether this person may run this league's draft.
     *
     * An administrator may, as well as the commissioner: somebody has to be able
     * to unstick a league whose commissioner has left the site, and the
     * alternative is a support request nobody can act on.
     *
     * @param array<string, mixed> $league
     * @param array<string, mixed>|null $viewer
     */
    private function mayRun(Request $request, array $league, ?array $viewer): bool
    {
        if ($this->app->make('fantasy.leagues')->isCommissioner($league, $viewer)) {
            return true;
        }

        if ($viewer === null) {
            return false;
        }

        $db = $this->app->make('db');

        try {
            return $db->table('group_members')
                ->join(
                    $db->prefixed('groups'),
                    $db->prefixed('group_members') . '.group_id',
                    '=',
                    $db->prefixed('groups') . '.id'
                )
                ->where($db->prefixed('groups') . '.is_admin', 1)
                ->where($db->prefixed('group_members') . '.user_id', (int) $viewer['id'])
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The names of every team already drafted, keyed by id.
     *
     * 🚨 One query for the whole board rather than a lookup per row. A
     * twelve-team draft with an eight-team squad is ninety-six slots, and a
     * per-row lookup is ninety-six queries to render one table.
     *
     * @param list<array<string, mixed>> $board
     * @return array<int, string>
     */
    private function teamNames(array $board): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $slot): int => (int) ($slot['team_id'] ?? 0),
            $board
        )));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (
            $this->app->make('db')->table('picks_teams')
                ->whereIn('id', array_values(array_unique($ids)))
                ->get() as $row
        ) {
            $out[(int) $row['id']] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * The board grouped by round, for a table with one row per round.
     *
     * @param list<array<string, mixed>> $board
     * @return array<int, list<array<string, mixed>>>
     */
    private function rounds(array $board): array
    {
        $out = [];

        foreach ($board as $slot) {
            $out[(int) $slot['round']][] = $slot;
        }

        return $out;
    }
}
