<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Services;

use Convoro\Engine\Database\Connection;

/**
 * The draft: an order, a board of empty slots, and one team going into one slot
 * at a time.
 *
 * 🚨 **Every slot of every round is created up front**, unfilled, the moment the
 * draft starts. It costs one bulk insert of `roster_size × franchises` rows and
 * it buys the two properties a draft actually needs:
 *
 *   - **Whose turn is it** is `the first slot with no team in it`, a lookup
 *     rather than a calculation. A snake order computed on demand is a formula
 *     that has to agree with itself on every screen, and the round where it
 *     stops agreeing is the round somebody picks out of turn.
 *   - **A pick landing twice is refused by the database.** The unique index on
 *     `(league_id, overall)` plus a WHERE on `team_id IS NULL` means a
 *     double-submit updates nothing the second time, rather than being caught by
 *     a check that another request can interleave inside.
 *
 * 🚨 **A drafted team is written to the roster in the same transaction as the
 * slot.** They are two rows describing one event, and a crash between them is a
 * pick nobody owns — which, in a draft, is unrecoverable without an admin
 * rebuilding the board by hand.
 *
 * 🚨 **Autopick is not a nicety.** A draft with no clock stops the first time
 * somebody goes to work, and a stalled draft in week zero is how a fantasy
 * league dies before it starts. When the clock expires the board takes the best
 * available team by the only ranking it honestly has — see `bestAvailable()`.
 */
final class Draft
{
    public function __construct(
        private readonly Connection $db,
        private readonly Leagues $leagues,
        private readonly Settings $settings,
    ) {
    }

    /**
     * Draw the order and lay out the board.
     *
     * 🚨 Refuses a league that has already started rather than rebuilding, and
     * refuses one with fewer than two franchises. A one-franchise draft
     * "succeeds" and produces a league that can never have a matchup, which
     * presents much later as an empty schedule nobody can explain.
     *
     * @return array{ok: bool, problem?: string, slots?: int}
     */
    public function start(array $league): array
    {
        // 🚨 Read fresh, not taken from the caller's copy. Two commissioners
        // pressing Start would otherwise both lay out a board.
        if ($this->status((int) $league['id']) !== 'setup') {
            return ['ok' => false, 'problem' => 'already_started'];
        }

        $franchises = $this->leagues->franchises((int) $league['id']);

        if (count($franchises) < 2) {
            return ['ok' => false, 'problem' => 'too_few'];
        }

        $rounds = (int) $league['roster_size'];
        $order = $this->drawOrder($franchises);
        $slots = [];
        $overall = 0;

        for ($round = 1; $round <= $rounds; $round++) {
            /*
             * 🚨 The snake. Even rounds run backwards, which is the entire
             * fairness argument for this format: the franchise that picked last
             * in round one picks first in round two.
             */
            $sequence = ($league['draft_type'] === 'snake' && $round % 2 === 0)
                ? array_reverse($order)
                : $order;

            foreach ($sequence as $franchiseId) {
                $slots[] = [
                    'league_id' => (int) $league['id'],
                    'round' => $round,
                    'overall' => ++$overall,
                    'franchise_id' => $franchiseId,
                    'team_id' => null,
                    'made_at' => 0,
                    'autopicked' => 0,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ];
            }
        }

        $this->db->transaction(function () use ($league, $franchises, $order, $slots): void {
            foreach ($order as $position => $franchiseId) {
                $this->db->table('fantasy_franchises')
                    ->where('id', $franchiseId)
                    ->updateAll(['draft_order' => $position + 1, 'updated_at' => $this->now()]);
            }

            unset($franchises);

            $this->db->table('fantasy_draft_picks')->insertBulk($slots);

            $this->db->table('fantasy_leagues')->where('id', $league['id'])->updateAll([
                'status' => 'drafting',
                'draft_started_at' => time(),
                'updated_at' => $this->now(),
            ]);
        });

        return ['ok' => true, 'slots' => count($slots)];
    }

    /**
     * The slot currently on the clock, or null when the draft is done.
     *
     * @return array<string, mixed>|null
     */
    public function onTheClock(int $leagueId): ?array
    {
        $row = $this->db->table('fantasy_draft_picks')
            ->where('league_id', $leagueId)
            ->whereNull('team_id')
            ->orderBy('overall')
            ->first();

        return $row === null ? null : $this->shapeSlot($row);
    }

    /**
     * The whole board, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function board(int $leagueId): array
    {
        return array_map(
            fn (array $row): array => $this->shapeSlot($row),
            $this->db->table('fantasy_draft_picks')
                ->where('league_id', $leagueId)
                ->orderBy('overall')
                ->get()
        );
    }

    /**
     * When the franchise on the clock runs out of time, or 0 when there is no
     * clock.
     *
     * 🚨 Measured from when the PREVIOUS pick was made, not from when the draft
     * started, and it falls back to the draft's own start for the very first
     * slot. Measuring from the start would expire every remaining pick at once
     * the moment anybody was slow.
     */
    public function deadline(array $league, ?array $slot): int
    {
        $seconds = (int) $league['draft_pick_seconds'];

        if ($seconds < 1 || $slot === null) {
            return 0;
        }

        $previous = $this->db->table('fantasy_draft_picks')
            ->where('league_id', $league['id'])
            ->where('overall', '<', $slot['overall'])
            ->whereNotNull('team_id')
            ->orderByDesc('overall')
            ->first();

        $from = $previous === null
            ? (int) $league['draft_started_at']
            : (int) $previous['made_at'];

        return max(0, $from) + $seconds;
    }

    /**
     * Make a pick.
     *
     * 🚨 Every question is asked against rows read in this request, and the last
     * one is asked by the database. Nothing the browser sends about whose turn
     * it is, or whether a team is free, is consulted — the browser is the thing
     * being defended against, and in a draft it is being operated by twelve
     * people who all want the same running back.
     *
     * @return array{ok: bool, problem?: string, slot?: array<string, mixed>}
     */
    public function pick(array $league, int $franchiseId, int $teamId, bool $auto = false): array
    {
        /*
         * 🚨 The status is read from the database, not from the array handed in.
         * A caller holding a league row from before the draft started would
         * otherwise be told the league is not drafting — and worse, a caller
         * holding one from before it FINISHED could pick into a closed board.
         * Everything else in this class asks the database rather than the
         * caller; the status is no different for being on an array the caller
         * already had.
         */
        if ($this->status((int) $league['id']) !== 'drafting') {
            return ['ok' => false, 'problem' => 'not_drafting'];
        }

        $slot = $this->onTheClock((int) $league['id']);

        if ($slot === null) {
            return ['ok' => false, 'problem' => 'draft_over'];
        }

        if ($slot['franchise_id'] !== $franchiseId) {
            return ['ok' => false, 'problem' => 'not_your_turn'];
        }

        if (!$this->playsIn((int) $league['season_id'], $teamId)) {
            return ['ok' => false, 'problem' => 'no_such_team'];
        }

        $taken = $this->db->table('fantasy_rosters')
            ->where('league_id', $league['id'])
            ->where('team_id', $teamId)
            ->exists();

        if ($taken) {
            return ['ok' => false, 'problem' => 'already_taken'];
        }

        $filled = false;

        $this->db->transaction(function () use ($league, $slot, $franchiseId, $teamId, $auto, &$filled): void {
            /*
             * 🚨 The WHERE on `team_id IS NULL` is what makes a double-submit
             * harmless: the second request updates zero rows and is told the
             * pick was not its to make, rather than overwriting the first.
             */
            $updated = $this->db->table('fantasy_draft_picks')
                ->where('id', $slot['id'])
                ->whereNull('team_id')
                ->updateAll([
                    'team_id' => $teamId,
                    'made_at' => time(),
                    'autopicked' => (int) $auto,
                    'updated_at' => $this->now(),
                ]);

            if ($updated < 1) {
                return;
            }

            $this->db->table('fantasy_rosters')->insertGetId([
                'league_id' => (int) $league['id'],
                'franchise_id' => $franchiseId,
                'team_id' => $teamId,
                'acquired_via' => 'draft',
                'acquired_at' => time(),
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);

            $this->db->table('fantasy_moves')->insertGetId([
                'league_id' => (int) $league['id'],
                'franchise_id' => $franchiseId,
                'team_id' => $teamId,
                'kind' => 'draft',
                'week_id' => 0,
                'created_at' => time(),
            ]);

            $filled = true;
        });

        if (!$filled) {
            return ['ok' => false, 'problem' => 'already_picked'];
        }

        $this->finishIfDone($league);

        return ['ok' => true, 'slot' => $slot];
    }

    /**
     * Fill the current slot for whoever is on the clock, because their time ran
     * out.
     *
     * @return array{ok: bool, problem?: string, slot?: array<string, mixed>}
     */
    public function autopick(array $league): array
    {
        $slot = $this->onTheClock((int) $league['id']);

        if ($slot === null) {
            return ['ok' => false, 'problem' => 'draft_over'];
        }

        $team = $this->bestAvailable((int) $league['id']);

        if ($team === null) {
            return ['ok' => false, 'problem' => 'nothing_available'];
        }

        return $this->pick($league, $slot['franchise_id'], $team, true);
    }

    /**
     * Teams nobody in this league owns.
     *
     * @return list<array<string, mixed>>
     */
    public function available(int $leagueId, string $search = '', int $limit = 400): array
    {
        $teams = $this->db->prefixed('picks_teams');
        $rosters = $this->db->prefixed('fantasy_rosters');
        $events = $this->db->prefixed('picks_events');
        $weeks = $this->db->prefixed('picks_weeks');

        /*
         * 🚨 Only teams that PLAY in this league's season. `picks_teams` held
         * one sport's teams when this was written; it now carries the NFL, the
         * NBA, MLB, the NHL and league football too, and without this a college
         * football draft board offers the Milwaukee Bucks.
         *
         * Asked of the fixtures rather than of a league column, which is both
         * provider-neutral and stricter: a club with no games this season is
         * not draftable however it is labelled.
         */
        $seasonId = $this->seasonOf($leagueId);

        $bindings = [$leagueId, $seasonId];
        $sql = "SELECT t.* FROM `{$teams}` t"
            . " WHERE NOT EXISTS (SELECT 1 FROM `{$rosters}` r WHERE r.`league_id` = ? AND r.`team_id` = t.`id`)"
            . " AND EXISTS (SELECT 1 FROM `{$events}` e"
            . "   INNER JOIN `{$weeks}` w ON w.`id` = e.`week_id`"
            . "   WHERE w.`season_id` = ? AND (e.`home_team_id` = t.`id` OR e.`away_team_id` = t.`id`))";

        $search = trim($search);

        if ($search !== '') {
            $sql .= " AND (t.`name` LIKE ? OR t.`conference` LIKE ? OR t.`abbreviation` LIKE ?)";
            $like = '%' . $search . '%';
            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;
        }

        $sql .= ' ORDER BY t.`name` LIMIT ' . max(1, min(1000, $limit));

        return $this->db->select($sql, $bindings);
    }

    /* ------------------------------------------------------------- private */

    /**
     * Whether a team actually plays in this league's season.
     *
     * 🚨 The guard that keeps a college-football draft from offering the
     * Milwaukee Bucks. `picks_teams` held one sport's teams when this was
     * written; Picks now carries the NFL, the NBA, MLB, the NHL and league
     * football in the same table, so "a team exists" stopped being the same
     * question as "a team is in this competition".
     *
     * 🚨 Asked of the FIXTURES rather than of a league column on the team,
     * which is both provider-neutral and stricter: a club with no games this
     * season is not draftable however it is labelled, and a club that has
     * moved competitions is where its fixtures are.
     */
    private function playsIn(int $seasonId, int $teamId): bool
    {
        if ($seasonId < 1 || $teamId < 1) {
            return false;
        }

        $events = $this->db->prefixed('picks_events');
        $weeks = $this->db->prefixed('picks_weeks');

        return $this->db->selectOne(
            "SELECT 1 FROM `{$events}` e
               INNER JOIN `{$weeks}` w ON w.`id` = e.`week_id`
              WHERE w.`season_id` = ? AND (e.`home_team_id` = ? OR e.`away_team_id` = ?)
              LIMIT 1",
            [$seasonId, $teamId, $teamId],
        ) !== null;
    }

    /** The Picks season a fantasy league plays. */
    private function seasonOf(int $leagueId): int
    {
        $row = $this->db->table('fantasy_leagues')->where('id', $leagueId)->first();

        return (int) ($row['season_id'] ?? 0);
    }


    /**
     * The team the board takes when nobody chose.
     *
     * 🚨 This is a HONEST ranking, not a good one, and the distinction is worth
     * being clear about. Fantasy autopick normally reaches for a projection;
     * there is no projection here, because Picks syncs fixtures and results
     * rather than ratings. So the board takes the team with the best record in
     * the current season, and alphabetical order breaks the tie.
     *
     * A worse pick made instantly beats a better pick that never comes, which is
     * the whole reason autopick exists. But it is deliberately not dressed up as
     * a projection, and the draft recap says a pick was automatic so nobody
     * spends the season wondering why they took Rice.
     */
    private function bestAvailable(int $leagueId): ?int
    {
        $teams = $this->db->prefixed('picks_teams');
        $rosters = $this->db->prefixed('fantasy_rosters');
        $events = $this->db->prefixed('picks_events');
        $weeks = $this->db->prefixed('picks_weeks');

        /*
         * 🚨 Scoped to the season the same way `available()` is, and for the
         * same reason. An autopick that can reach outside the competition is
         * worse than one that cannot pick at all: it puts a team on somebody's
         * roster that will never appear in a fixture, and the franchise is a
         * starter short for the rest of the year with nothing on screen saying
         * why.
         */
        $sql = "SELECT t.`id`,"
            . " SUM(CASE"
            . "   WHEN e.`result` = 'home' AND e.`home_team_id` = t.`id` THEN 1"
            . "   WHEN e.`result` = 'away' AND e.`away_team_id` = t.`id` THEN 1"
            . "   ELSE 0 END) AS `wins`"
            . " FROM `{$teams}` t"
            . " INNER JOIN `{$events}` e ON (e.`home_team_id` = t.`id` OR e.`away_team_id` = t.`id`)"
            . " INNER JOIN `{$weeks}` w ON w.`id` = e.`week_id`"
            . " WHERE w.`season_id` = ?"
            . " AND NOT EXISTS (SELECT 1 FROM `{$rosters}` r WHERE r.`league_id` = ? AND r.`team_id` = t.`id`)"
            . ' GROUP BY t.`id`, t.`name`'
            . ' ORDER BY `wins` DESC, t.`name` ASC'
            . ' LIMIT 1';

        $row = $this->db->select($sql, [$this->seasonOf($leagueId), $leagueId])[0] ?? null;

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Randomise the order.
     *
     * 🚨 `shuffle()` is seeded from the CSPRNG in PHP 7.1 and later, so this is
     * not the predictable `rand` a draft order would be embarrassing to use. It
     * is drawn ONCE and written to the franchise rows, because an order that is
     * recomputed is an order that can change.
     *
     * @param list<array<string, mixed>> $franchises
     * @return list<int>
     */
    private function drawOrder(array $franchises): array
    {
        $ids = array_map(static fn (array $f): int => (int) $f['id'], $franchises);

        shuffle($ids);

        return $ids;
    }

    private function finishIfDone(array $league): void
    {
        if ($this->onTheClock((int) $league['id']) !== null) {
            return;
        }

        $this->db->table('fantasy_leagues')->where('id', $league['id'])->updateAll([
            'status' => 'active',
            'draft_finished_at' => time(),
            'updated_at' => $this->now(),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeSlot(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['league_id'] = (int) $row['league_id'];
        $row['round'] = (int) $row['round'];
        $row['overall'] = (int) $row['overall'];
        $row['franchise_id'] = (int) $row['franchise_id'];
        $row['team_id'] = $row['team_id'] === null ? null : (int) $row['team_id'];
        $row['made_at'] = (int) $row['made_at'];
        $row['autopicked'] = (bool) $row['autopicked'];

        return $row;
    }

    /** This league's status, as the database has it right now. */
    private function status(int $leagueId): string
    {
        $row = $this->db->table('fantasy_leagues')->where('id', $leagueId)->first();

        return $row === null ? '' : (string) $row['status'];
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Whether the site wants expired clocks filled in. */
    public function autopicksEnabled(): bool
    {
        return $this->settings->autopicks();
    }
}
