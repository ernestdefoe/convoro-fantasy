<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Services;

use Convoro\Engine\Database\Connection;

/**
 * Who plays whom, and where everybody stands.
 *
 * 🚨 **The schedule is a circle-method round robin**, which is the standard
 * construction and the reason it is worth naming: one franchise is held fixed
 * and the rest rotate around it, so over `n − 1` weeks every franchise plays
 * every other exactly once, and with an odd number of franchises the fixed slot
 * pairs with nobody and that franchise takes the bye. Pairing at random instead
 * produces a season where two people never meet and somebody draws the same
 * opponent three times.
 *
 * 🚨 **A bye is a matchup with a NULL opponent, not a missing row.** Every
 * franchise appears exactly once in every week, so "who am I playing?" always
 * has an answer — and the standings can count byes rather than inferring them
 * from a gap.
 *
 * 🚨 **Standings are computed, never stored.** A league has a dozen franchises
 * and a couple of dozen weeks; the whole table is two queries. A stored
 * standings table is a second copy of a fact `fantasy_matchups` already holds,
 * and the copy is the one that goes wrong when a score is corrected.
 */
final class Schedule
{
    public function __construct(
        private readonly Connection $db,
        private readonly Leagues $leagues,
    ) {
    }

    /**
     * Build the fixtures for every remaining week of the season.
     *
     * 🚨 Only ever fills in weeks that have NO matchups yet. Re-running it after
     * somebody joins does not rewrite results that have already been played —
     * and it is re-run, because "regenerate the schedule" is the first thing a
     * commissioner reaches for when a league changes size.
     *
     * @param array<string, mixed> $league
     * @return array{weeks: int, matchups: int}
     */
    public function build(array $league): array
    {
        $leagueId = (int) $league['id'];
        $franchises = array_map(
            static fn (array $f): int => (int) $f['id'],
            $this->leagues->franchises($leagueId)
        );

        if (count($franchises) < 2) {
            return ['weeks' => 0, 'matchups' => 0];
        }

        $weeks = $this->weeks((int) $league['season_id']);

        if ($weeks === []) {
            return ['weeks' => 0, 'matchups' => 0];
        }

        $existing = array_map('intval', $this->db->table('fantasy_matchups')
            ->where('league_id', $leagueId)
            ->pluck('week_id'));

        $rounds = $this->roundRobin($franchises);
        $written = 0;
        $touched = 0;

        foreach (array_values($weeks) as $index => $week) {
            $weekId = (int) $week['id'];

            if (in_array($weekId, $existing, true)) {
                continue;
            }

            /*
             * The rotation repeats once every franchise has met every other. A
             * college season is longer than that for a small league, and going
             * round again is the right answer — the alternative is a schedule
             * that stops in October.
             */
            $pairs = $rounds[$index % count($rounds)];
            $rows = [];

            foreach ($pairs as [$home, $away]) {
                $rows[] = [
                    'league_id' => $leagueId,
                    'week_id' => $weekId,
                    'home_franchise_id' => $home,
                    'away_franchise_id' => $away,
                    'home_points' => 0,
                    'away_points' => 0,
                    'status' => 'scheduled',
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ];
            }

            if ($rows === []) {
                continue;
            }

            $this->db->table('fantasy_matchups')->insertBulk($rows);

            $written += count($rows);
            $touched++;
        }

        return ['weeks' => $touched, 'matchups' => $written];
    }

    /**
     * One round-robin cycle: a list of weeks, each a list of `[home, away]`
     * pairs. `away` is null for the franchise on a bye.
     *
     * @param list<int> $franchises
     * @return list<list<array{0: int, 1: int|null}>>
     */
    public function roundRobin(array $franchises): array
    {
        /*
         * 🚨 An odd count gets a phantom entry, which is the standard trick: the
         * franchise drawn against the phantom has the bye that week. Without it
         * the rotation has to special-case the leftover on every round, and the
         * special case is where the double-booking creeps in.
         */
        $ghost = count($franchises) % 2 === 1;

        if ($ghost) {
            $franchises[] = 0;
        }

        $count = count($franchises);
        $rounds = [];

        // The fixed franchise is index 0; everything else rotates around it.
        $rotating = array_slice($franchises, 1);

        for ($round = 0; $round < $count - 1; $round++) {
            $order = array_merge([$franchises[0]], $rotating);
            $pairs = [];

            for ($i = 0; $i < intdiv($count, 2); $i++) {
                $home = $order[$i];
                $away = $order[$count - 1 - $i];

                if ($home === 0) {
                    $pairs[] = [$away, null];
                    continue;
                }

                if ($away === 0) {
                    $pairs[] = [$home, null];
                    continue;
                }

                /*
                 * Alternate who is nominally at home each round. It changes
                 * nothing about scoring — there is no home advantage in this
                 * format — but it stops the same franchise being listed first in
                 * every fixture for the entire season.
                 */
                $pairs[] = $round % 2 === 0 ? [$home, $away] : [$away, $home];
            }

            $rounds[] = $pairs;

            // Rotate: the last of the rotating group moves to the front.
            array_unshift($rotating, array_pop($rotating));
        }

        return $rounds;
    }

    /**
     * The weeks of a season, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function weeks(int $seasonId): array
    {
        return $this->db->table('picks_weeks')
            ->where('season_id', $seasonId)
            ->orderBy('season_type')
            ->orderBy('week_number')
            ->get();
    }

    /**
     * A league's matchups for one week, with both franchises named.
     *
     * @return list<array<string, mixed>>
     */
    public function forWeek(int $leagueId, int $weekId): array
    {
        $matchups = $this->db->table('fantasy_matchups')
            ->where('league_id', $leagueId)
            ->where('week_id', $weekId)
            ->orderBy('id')
            ->get();

        $names = [];

        foreach ($this->leagues->franchises($leagueId) as $franchise) {
            $names[(int) $franchise['id']] = $franchise;
        }

        return array_map(static function (array $row) use ($names): array {
            $home = (int) $row['home_franchise_id'];
            $away = $row['away_franchise_id'] === null ? null : (int) $row['away_franchise_id'];

            return $row + [
                'home' => $names[$home] ?? null,
                'away' => $away === null ? null : ($names[$away] ?? null),
                'is_bye' => $away === null,
                'home_points' => (float) $row['home_points'],
                'away_points' => (float) $row['away_points'],
            ];
        }, $matchups);
    }

    /**
     * The table.
     *
     * 🚨 Only `final` matchups count towards a record. A week in progress moves
     * the points column and nothing else, so nobody is shown as having won a
     * game that is still being played.
     *
     * Ordered by wins, then by points scored — points for is the standard
     * tiebreak and it is also the only one that is always available. Head-to-head
     * would be better and is deliberately not used: it is undefined for three-way
     * ties, which is exactly when a tiebreak is needed.
     *
     * @return list<array<string, mixed>>
     */
    public function standings(int $leagueId): array
    {
        $table = [];

        foreach ($this->leagues->franchises($leagueId) as $franchise) {
            $table[(int) $franchise['id']] = $franchise + [
                'wins' => 0,
                'losses' => 0,
                'ties' => 0,
                'byes' => 0,
                'points_for' => 0.0,
                'points_against' => 0.0,
            ];
        }

        foreach (
            $this->db->table('fantasy_matchups')
                ->where('league_id', $leagueId)
                ->get() as $row
        ) {
            $home = (int) $row['home_franchise_id'];
            $away = $row['away_franchise_id'] === null ? null : (int) $row['away_franchise_id'];
            $homePoints = (float) $row['home_points'];
            $awayPoints = (float) $row['away_points'];
            $isFinal = (string) $row['status'] === 'final';

            if (!isset($table[$home])) {
                continue;
            }

            $table[$home]['points_for'] += $homePoints;

            if ($away === null) {
                if ($isFinal) {
                    $table[$home]['byes']++;
                }

                continue;
            }

            if (!isset($table[$away])) {
                continue;
            }

            $table[$away]['points_for'] += $awayPoints;
            $table[$home]['points_against'] += $awayPoints;
            $table[$away]['points_against'] += $homePoints;

            if (!$isFinal) {
                continue;
            }

            if ($homePoints > $awayPoints) {
                $table[$home]['wins']++;
                $table[$away]['losses']++;
            } elseif ($awayPoints > $homePoints) {
                $table[$away]['wins']++;
                $table[$home]['losses']++;
            } else {
                $table[$home]['ties']++;
                $table[$away]['ties']++;
            }
        }

        $rows = array_values($table);

        usort($rows, static function (array $a, array $b): int {
            return [$b['wins'], $b['points_for']] <=> [$a['wins'], $a['points_for']];
        });

        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = $index + 1;
            $rows[$index]['points_for'] = round((float) $row['points_for'], 2);
            $rows[$index]['points_against'] = round((float) $row['points_against'], 2);
        }

        return $rows;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
