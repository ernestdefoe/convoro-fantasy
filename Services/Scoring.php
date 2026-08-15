<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Services;

use Convoro\Engine\Database\Connection;

/**
 * What a started team was worth, and who won the matchup.
 *
 * 🚨 **A game only scores once it is finished.** `picks_events` carries a live
 * score while a game is being played, and scoring off that would mean a
 * franchise's total moving up and down all afternoon and a matchup being
 * "final" at half time. The gate is `status = finished` AND both scores present
 * — a game with a NULL score has not been reported on, which is not 0–0.
 *
 * 🚨 **Scoring is idempotent and re-runnable.** Every pass upserts by
 * `(league, franchise, week, team)`, so running it twice writes the same row
 * twice and changes nothing. That is what makes it safe to put on a schedule
 * next to a live-score sync that will re-report a game that was already final.
 *
 * 🚨 **The workings are stored, not just the total.** `scored`, `allowed`,
 * `won`, `shutout` and `bonus` are written alongside the points, because a
 * commissioner fixing a broken rule in week three would otherwise make every
 * earlier week unexplainable: re-deriving an old score under today's rules gives
 * a different answer to the one on the standings.
 *
 * The rules themselves come off the league row, which is what lets two leagues
 * on the same forum disagree about them.
 */
final class Scoring
{
    public function __construct(
        private readonly Connection $db,
        private readonly Lineups $lineups,
    ) {
    }

    /**
     * Score one week of one league.
     *
     * @param array<string, mixed> $league
     * @return array{scored: int, finalised: int}
     */
    public function week(array $league, int $weekId): array
    {
        $leagueId = (int) $league['id'];
        $byFranchise = $this->lineups->startingByFranchise($leagueId, $weekId);

        if ($byFranchise === []) {
            return ['scored' => 0, 'finalised' => 0];
        }

        $allTeams = array_values(array_unique(array_merge(...array_values($byFranchise))));
        $games = $this->lineups->gamesFor($weekId, $allTeams);

        /*
         * Draft position, needed only for the upset bonus. Read once for the
         * whole week rather than per game, and skipped entirely when the bonus
         * is switched off — which it is by default.
         */
        $draftedAt = ((float) $league['upset_bonus']) !== 0.0
            ? $this->draftPositions($leagueId)
            : [];

        $scored = 0;

        foreach ($byFranchise as $franchiseId => $teamIds) {
            foreach ($teamIds as $teamId) {
                $game = $games[$teamId] ?? null;

                if ($game === null || !$this->isFinal($game)) {
                    continue;
                }

                $this->write($league, (int) $franchiseId, $weekId, (int) $teamId, $game, $draftedAt);
                $scored++;
            }
        }

        $finalised = $this->totalMatchups($league, $weekId, $games);

        return ['scored' => $scored, 'finalised' => $finalised];
    }

    /**
     * Roll the week's team scores up into the matchups.
     *
     * 🚨 A matchup goes `final` only when every team BOTH sides started has a
     * finished game. A matchup called final while somebody's Saturday-night game
     * is still on is a result that changes after it was announced, and that is
     * the one thing a league will not forgive.
     *
     * @param array<string, mixed> $league
     * @param array<int, array<string, mixed>> $games
     */
    private function totalMatchups(array $league, int $weekId, array $games): int
    {
        $leagueId = (int) $league['id'];

        $totals = [];

        foreach (
            $this->db->table('fantasy_scores')
                ->where('league_id', $leagueId)
                ->where('week_id', $weekId)
                ->get() as $row
        ) {
            $franchiseId = (int) $row['franchise_id'];
            $totals[$franchiseId] = ($totals[$franchiseId] ?? 0.0) + (float) $row['points'];
        }

        $starting = $this->lineups->startingByFranchise($leagueId, $weekId);
        $finalised = 0;

        foreach (
            $this->db->table('fantasy_matchups')
                ->where('league_id', $leagueId)
                ->where('week_id', $weekId)
                ->get() as $matchup
        ) {
            $home = (int) $matchup['home_franchise_id'];
            $away = $matchup['away_franchise_id'] === null ? null : (int) $matchup['away_franchise_id'];

            $complete = $this->allPlayed($starting[$home] ?? [], $games)
                && ($away === null || $this->allPlayed($starting[$away] ?? [], $games));

            $this->db->table('fantasy_matchups')->where('id', $matchup['id'])->updateAll([
                'home_points' => round($totals[$home] ?? 0.0, 2),
                'away_points' => $away === null ? 0 : round($totals[$away] ?? 0.0, 2),
                'status' => $complete ? 'final' : 'scheduled',
                'updated_at' => $this->now(),
            ]);

            if ($complete) {
                $finalised++;
            }
        }

        return $finalised;
    }

    /**
     * @param list<int> $teamIds
     * @param array<int, array<string, mixed>> $games
     */
    private function allPlayed(array $teamIds, array $games): bool
    {
        foreach ($teamIds as $teamId) {
            $game = $games[(int) $teamId] ?? null;

            /*
             * 🚨 A started team with NO game this week does not hold the matchup
             * open. Byes happen every week of a college season, and treating one
             * as "not finished yet" would leave half the schedule permanently
             * unresolved.
             */
            if ($game !== null && !$this->isFinal($game)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $game */
    private function isFinal(array $game): bool
    {
        return (string) $game['status'] === 'finished'
            && $game['home_score'] !== null
            && $game['away_score'] !== null;
    }

    /**
     * @param array<string, mixed> $league
     * @param array<string, mixed> $game
     * @param array<int, int> $draftedAt
     */
    private function write(array $league, int $franchiseId, int $weekId, int $teamId, array $game, array $draftedAt): void
    {
        $isHome = (bool) $game['is_home'];
        $scored = (int) ($isHome ? $game['home_score'] : $game['away_score']);
        $allowed = (int) ($isHome ? $game['away_score'] : $game['home_score']);

        $won = $scored > $allowed;
        $shutout = $won && $allowed === 0;

        /*
         * 🚨 Margin counts only in a win, and only the margin of victory.
         * Multiplying a negative margin by the same rate would make a heavy
         * defeat cost more than the points-allowed rule already charges for it,
         * which is the same penalty twice.
         */
        $margin = $won ? $scored - $allowed : 0;

        $bonus = 0.0;

        if ($won) {
            $bonus += (float) $league['win_bonus'];
        }

        if ($shutout) {
            $bonus += (float) $league['shutout_bonus'];
        }

        $upset = (float) $league['upset_bonus'];

        if ($upset !== 0.0 && $won && $draftedAt !== []) {
            $mine = $draftedAt[$teamId] ?? null;
            $theirs = $draftedAt[(int) $game['opponent_id']] ?? null;

            /*
             * An upset is beating a team the room rated more highly — a LOWER
             * overall pick number. Both sides have to have been drafted in this
             * league for the comparison to mean anything; beating an undrafted
             * team is not an upset.
             */
            if ($mine !== null && $theirs !== null && $mine > $theirs) {
                $bonus += $upset;
            }
        }

        $points = round(
            $scored * (float) $league['points_per_point']
            + $allowed * (float) $league['points_per_point_allowed']
            + $margin * (float) $league['points_per_margin']
            + $bonus,
            2
        );

        $values = [
            'event_id' => (int) $game['id'],
            'points' => $points,
            'scored' => $scored,
            'allowed' => $allowed,
            'won' => (int) $won,
            'shutout' => (int) $shutout,
            'bonus' => round($bonus, 2),
            'updated_at' => $this->now(),
        ];

        $existing = $this->db->table('fantasy_scores')
            ->where('league_id', $league['id'])
            ->where('franchise_id', $franchiseId)
            ->where('week_id', $weekId)
            ->where('team_id', $teamId)
            ->first();

        if ($existing !== null) {
            $this->db->table('fantasy_scores')->where('id', $existing['id'])->updateAll($values);

            return;
        }

        $this->db->table('fantasy_scores')->insertGetId($values + [
            'league_id' => (int) $league['id'],
            'franchise_id' => $franchiseId,
            'week_id' => $weekId,
            'team_id' => $teamId,
            'created_at' => $this->now(),
        ]);
    }

    /**
     * Where each team went in the draft, keyed by team id.
     *
     * @return array<int, int>
     */
    private function draftPositions(int $leagueId): array
    {
        $out = [];

        foreach (
            $this->db->table('fantasy_draft_picks')
                ->where('league_id', $leagueId)
                ->whereNotNull('team_id')
                ->get() as $row
        ) {
            $out[(int) $row['team_id']] = (int) $row['overall'];
        }

        return $out;
    }

    /**
     * A week's team-by-team breakdown for one franchise.
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(int $leagueId, int $franchiseId, int $weekId): array
    {
        $scores = $this->db->prefixed('fantasy_scores');
        $teams = $this->db->prefixed('picks_teams');

        return $this->db->select(
            "SELECT s.*, t.`name` AS `team_name`, t.`logo_path` AS `team_logo`"
            . " FROM `{$scores}` s"
            . " LEFT JOIN `{$teams}` t ON t.`id` = s.`team_id`"
            . ' WHERE s.`league_id` = ? AND s.`franchise_id` = ? AND s.`week_id` = ?'
            . ' ORDER BY s.`points` DESC',
            [$leagueId, $franchiseId, $weekId]
        );
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
