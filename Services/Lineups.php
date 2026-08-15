<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Services;

use Convoro\Engine\Database\Connection;

/**
 * Who a franchise is starting this week, and what may still be changed.
 *
 * 🚨 **The lock is per TEAM, at that team's own kickoff.** There is no locked
 * flag on a lineup row anywhere in this extension: the answer is
 * `picks_events.cutoff_at` for the game that team is playing this week, read
 * fresh on every write. Picks made the same decision for the same reason — a
 * stored flag needs something to set it, and the thing that sets it is a cron
 * that can be late, so the first Thursday-night game of the season is the one
 * that gets started retrospectively.
 *
 * That cuts both ways and both directions matter:
 *
 *   - A started team may not be BENCHED after its game has kicked off. Otherwise
 *     a franchise watches the first quarter and removes whoever is losing.
 *   - A benched team may not be STARTED after its game has kicked off, for the
 *     same reason in reverse.
 *
 * 🚨 **A team with no game this week can always be moved.** A bye is not a
 * result, and locking somebody out of changing a team that is not playing is the
 * kind of rule that makes people stop setting lineups.
 *
 * 🚨 **Nothing here enforces the starter count by refusing a write.** Going one
 * over is refused, but a lineup that is UNDER is allowed and simply scores less.
 * A half-set lineup is a normal state of the world on a Wednesday, and a form
 * that refuses to save until it is complete is a form that loses the three
 * choices somebody had already made.
 */
final class Lineups
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The team ids a franchise is starting this week.
     *
     * @return list<int>
     */
    public function starting(int $leagueId, int $franchiseId, int $weekId): array
    {
        return array_map('intval', $this->db->table('fantasy_lineups')
            ->where('league_id', $leagueId)
            ->where('franchise_id', $franchiseId)
            ->where('week_id', $weekId)
            ->pluck('team_id'));
    }

    /**
     * Every franchise's starters for a week, keyed by franchise id.
     *
     * @return array<int, list<int>>
     */
    public function startingByFranchise(int $leagueId, int $weekId): array
    {
        $out = [];

        foreach (
            $this->db->table('fantasy_lineups')
                ->where('league_id', $leagueId)
                ->where('week_id', $weekId)
                ->get() as $row
        ) {
            $franchiseId = (int) $row['franchise_id'];
            $out[$franchiseId] ??= [];
            $out[$franchiseId][] = (int) $row['team_id'];
        }

        return $out;
    }

    /**
     * The games this week for a given set of teams, keyed by team id.
     *
     * 🚨 One query for the whole roster rather than one per team. The lineup
     * screen asks this for every team a franchise owns, and the per-team version
     * is eight queries to render one page.
     *
     * @param list<int> $teamIds
     * @return array<int, array<string, mixed>>
     */
    public function gamesFor(int $weekId, array $teamIds): array
    {
        $teamIds = array_values(array_unique(array_map('intval', $teamIds)));

        if ($teamIds === []) {
            return [];
        }

        $rows = $this->db->table('picks_events')
            ->where('week_id', $weekId)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $home = (int) $row['home_team_id'];
            $away = (int) $row['away_team_id'];

            foreach ([$home, $away] as $teamId) {
                if (!in_array($teamId, $teamIds, true)) {
                    continue;
                }

                /*
                 * The row is stored once per team with the perspective baked in,
                 * so a caller never has to work out which side it was on. That
                 * calculation appearing in three templates is how "points
                 * allowed" ends up being the wrong team's score.
                 */
                $out[$teamId] = $row + [
                    'is_home' => $teamId === $home,
                    'opponent_id' => $teamId === $home ? $away : $home,
                ];
            }
        }

        return $out;
    }

    /**
     * Whether this team may be moved into or out of the lineup right now.
     *
     * @param array<int, array<string, mixed>> $games as returned by gamesFor()
     */
    public function movable(int $teamId, array $games, ?int $now = null): bool
    {
        $game = $games[$teamId] ?? null;

        // No game this week: a bye is not a result, so it is always movable.
        if ($game === null) {
            return true;
        }

        return ($now ?? time()) < (int) $game['cutoff_at'];
    }

    /**
     * Start a team.
     *
     * @param array<string, mixed> $league
     * @return array{ok: bool, problem?: string}
     */
    public function start(array $league, int $franchiseId, int $weekId, int $teamId, ?int $now = null): array
    {
        $owned = $this->db->table('fantasy_rosters')
            ->where('league_id', $league['id'])
            ->where('franchise_id', $franchiseId)
            ->where('team_id', $teamId)
            ->exists();

        if (!$owned) {
            return ['ok' => false, 'problem' => 'not_yours'];
        }

        $games = $this->gamesFor($weekId, [$teamId]);

        if (!$this->movable($teamId, $games, $now)) {
            return ['ok' => false, 'problem' => 'locked'];
        }

        $current = $this->starting((int) $league['id'], $franchiseId, $weekId);

        if (in_array($teamId, $current, true)) {
            // Already started. A double-click is not an error.
            return ['ok' => true];
        }

        if (count($current) >= (int) $league['starters']) {
            return ['ok' => false, 'problem' => 'lineup_full'];
        }

        $this->db->table('fantasy_lineups')->insertGetId([
            'league_id' => (int) $league['id'],
            'franchise_id' => $franchiseId,
            'week_id' => $weekId,
            'team_id' => $teamId,

            // 🚨 Recorded so a member can be TOLD why a team is stuck, which is
            // the question they ask the moment a game starts. The lock itself is
            // still read live from the event.
            'locks_at' => (int) ($games[$teamId]['cutoff_at'] ?? 0),

            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        return ['ok' => true];
    }

    /**
     * Bench a team.
     *
     * @param array<string, mixed> $league
     * @return array{ok: bool, problem?: string}
     */
    public function bench(array $league, int $franchiseId, int $weekId, int $teamId, ?int $now = null): array
    {
        $games = $this->gamesFor($weekId, [$teamId]);

        if (!$this->movable($teamId, $games, $now)) {
            return ['ok' => false, 'problem' => 'locked'];
        }

        $this->db->table('fantasy_lineups')
            ->where('league_id', $league['id'])
            ->where('franchise_id', $franchiseId)
            ->where('week_id', $weekId)
            ->where('team_id', $teamId)
            ->deleteAll();

        return ['ok' => true];
    }

    /**
     * Copy last week's lineup into this one, for teams that are still owned and
     * whose games have not started.
     *
     * 🚨 Off by default at the site level, and it only ever ADDS. A carry-over
     * that also benched things would silently undo a lineup somebody had already
     * started setting, which is the opposite of the convenience it is for.
     *
     * @param array<string, mixed> $league
     */
    public function carryOver(array $league, int $franchiseId, int $fromWeekId, int $toWeekId, ?int $now = null): int
    {
        $previous = $this->starting((int) $league['id'], $franchiseId, $fromWeekId);

        if ($previous === []) {
            return 0;
        }

        $moved = 0;

        foreach ($previous as $teamId) {
            $result = $this->start($league, $franchiseId, $toWeekId, $teamId, $now);

            if ($result['ok']) {
                $moved++;
            }
        }

        return $moved;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
