<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Services;

use Convoro\Engine\Database\Connection;

/**
 * What a franchise owns, and how a team changes hands after the draft.
 *
 * 🚨 **Adding and dropping are one operation, not two.** A roster that is full
 * needs a drop to make room, and doing that as two requests means the window
 * between them is a window where somebody else takes the team — so the member
 * gives up a team and gets nothing. `swap()` does both inside one transaction,
 * and the unique index on `(league_id, team_id)` is what decides the race.
 *
 * 🚨 **A team whose game has kicked off may not be dropped this week.** Otherwise
 * a franchise watches a team score four touchdowns for somebody else's opponent
 * and drops it before the lineup is scored — or worse, drops a team that is
 * already in their own lineup and losing. The check is the same
 * `picks_events.cutoff_at` the lineup lock uses, and it is deliberately the same
 * rule rather than a similar one.
 */
final class Rosters
{
    public function __construct(
        private readonly Connection $db,
        private readonly Lineups $lineups,
    ) {
    }

    /**
     * A franchise's teams, with the crest and conference alongside.
     *
     * @return list<array<string, mixed>>
     */
    public function forFranchise(int $leagueId, int $franchiseId): array
    {
        $rosters = $this->db->prefixed('fantasy_rosters');
        $teams = $this->db->prefixed('picks_teams');
        $forums = $this->db->prefixed('forums');

        /*
         * 🚨 The forum SLUG, not its id. Convoro addresses a forum by slug, so
         * a link built from `forum_id` is a 404 that looks like a working link
         * — and `picks_teams.forum_id` is the only thing stored, deliberately,
         * because it is a read-only pointer that must not break when a forum is
         * renamed. Resolving it here is what Picks does on its own board.
         */
        return $this->db->select(
            "SELECT r.*, t.`name` AS `team_name`, t.`abbreviation`, t.`conference`,"
            . " t.`logo_path`, t.`forum_id`, f.`slug` AS `forum_slug`"
            . " FROM `{$rosters}` r"
            . " LEFT JOIN `{$teams}` t ON t.`id` = r.`team_id`"
            . " LEFT JOIN `{$forums}` f ON f.`id` = t.`forum_id`"
            . ' WHERE r.`league_id` = ? AND r.`franchise_id` = ?'
            . ' ORDER BY t.`name`',
            [$leagueId, $franchiseId]
        );
    }

    /**
     * Every owned team in the league, keyed by team id, with the owner.
     *
     * @return array<int, array<string, mixed>>
     */
    public function owners(int $leagueId): array
    {
        $out = [];

        foreach (
            $this->db->table('fantasy_rosters')
                ->where('league_id', $leagueId)
                ->get() as $row
        ) {
            $out[(int) $row['team_id']] = $row;
        }

        return $out;
    }

    public function count(int $leagueId, int $franchiseId): int
    {
        return $this->db->table('fantasy_rosters')
            ->where('league_id', $leagueId)
            ->where('franchise_id', $franchiseId)
            ->count();
    }

    /**
     * Take a free agent, dropping a team to make room if one is named.
     *
     * @param array<string, mixed> $league
     * @return array{ok: bool, problem?: string}
     */
    public function swap(
        array $league,
        int $franchiseId,
        int $addTeamId,
        int $dropTeamId,
        int $weekId,
        ?int $now = null
    ): array {
        // 🚨 Read fresh. A caller holding a league row from before the draft
        // finished would be told the league is not playing when it is.
        $status = $this->db->table('fantasy_leagues')->where('id', $league['id'])->value('status');

        if ((string) $status !== 'active') {
            return ['ok' => false, 'problem' => 'not_active'];
        }

        if ($addTeamId === $dropTeamId) {
            return ['ok' => false, 'problem' => 'same_team'];
        }

        if (!$this->db->table('picks_teams')->where('id', $addTeamId)->exists()) {
            return ['ok' => false, 'problem' => 'no_such_team'];
        }

        $held = $this->count((int) $league['id'], $franchiseId);
        $needsDrop = $held >= (int) $league['roster_size'];

        if ($needsDrop && $dropTeamId < 1) {
            return ['ok' => false, 'problem' => 'roster_full'];
        }

        /*
         * 🚨 Both sides are checked against kickoff, not just the drop. Adding a
         * team whose game has already started is claiming points from a game in
         * progress, which is the same abuse from the other end.
         */
        $lockCheck = array_values(array_filter([$addTeamId, $dropTeamId > 0 ? $dropTeamId : null]));
        $games = $this->lineups->gamesFor($weekId, $lockCheck);

        foreach ($lockCheck as $teamId) {
            if (!$this->lineups->movable((int) $teamId, $games, $now)) {
                return ['ok' => false, 'problem' => 'locked'];
            }
        }

        if ($dropTeamId > 0) {
            $ownsDrop = $this->db->table('fantasy_rosters')
                ->where('league_id', $league['id'])
                ->where('franchise_id', $franchiseId)
                ->where('team_id', $dropTeamId)
                ->exists();

            if (!$ownsDrop) {
                return ['ok' => false, 'problem' => 'not_yours'];
            }
        }

        $taken = $this->db->table('fantasy_rosters')
            ->where('league_id', $league['id'])
            ->where('team_id', $addTeamId)
            ->exists();

        if ($taken) {
            return ['ok' => false, 'problem' => 'already_taken'];
        }

        $done = false;

        $this->db->transaction(function () use ($league, $franchiseId, $addTeamId, $dropTeamId, $weekId, &$done): void {
            if ($dropTeamId > 0) {
                $this->db->table('fantasy_rosters')
                    ->where('league_id', $league['id'])
                    ->where('franchise_id', $franchiseId)
                    ->where('team_id', $dropTeamId)
                    ->deleteAll();

                /*
                 * 🚨 A dropped team comes out of every lineup it was in for
                 * weeks that have not been scored. Leaving it would score points
                 * for a team the franchise no longer owns — which is not a
                 * theoretical bug, it is the obvious way to cheat this format.
                 */
                $this->db->table('fantasy_lineups')
                    ->where('league_id', $league['id'])
                    ->where('franchise_id', $franchiseId)
                    ->where('team_id', $dropTeamId)
                    ->where('week_id', '>=', $weekId)
                    ->deleteAll();

                $this->log((int) $league['id'], $franchiseId, $dropTeamId, 'drop', $weekId);
            }

            $this->db->table('fantasy_rosters')->insertGetId([
                'league_id' => (int) $league['id'],
                'franchise_id' => $franchiseId,
                'team_id' => $addTeamId,
                'acquired_via' => 'waiver',
                'acquired_at' => time(),
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);

            $this->log((int) $league['id'], $franchiseId, $addTeamId, 'add', $weekId);

            $done = true;
        });

        return $done ? ['ok' => true] : ['ok' => false, 'problem' => 'already_taken'];
    }

    /**
     * Teams nobody owns, newest search first.
     *
     * @return list<array<string, mixed>>
     */
    public function freeAgents(int $leagueId, string $search = '', int $limit = 200): array
    {
        $teams = $this->db->prefixed('picks_teams');
        $rosters = $this->db->prefixed('fantasy_rosters');

        $bindings = [$leagueId];
        $sql = "SELECT t.* FROM `{$teams}` t"
            . " WHERE NOT EXISTS (SELECT 1 FROM `{$rosters}` r WHERE r.`league_id` = ? AND r.`team_id` = t.`id`)";

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

    /**
     * The transaction log, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function moves(int $leagueId, int $limit = 50): array
    {
        $moves = $this->db->prefixed('fantasy_moves');
        $teams = $this->db->prefixed('picks_teams');
        $franchises = $this->db->prefixed('fantasy_franchises');
        $users = $this->db->prefixed('users');

        return $this->db->select(
            "SELECT m.*, t.`name` AS `team_name`, t.`logo_path`,"
            . " f.`name` AS `franchise_name`, u.`username` AS `username`"
            . " FROM `{$moves}` m"
            . " LEFT JOIN `{$teams}` t ON t.`id` = m.`team_id`"
            . " LEFT JOIN `{$franchises}` f ON f.`id` = m.`franchise_id`"
            . " LEFT JOIN `{$users}` u ON u.`id` = f.`user_id`"
            . ' WHERE m.`league_id` = ?'
            . ' ORDER BY m.`created_at` DESC, m.`id` DESC'
            . ' LIMIT ' . max(1, min(200, $limit)),
            [$leagueId]
        );
    }

    private function log(int $leagueId, int $franchiseId, int $teamId, string $kind, int $weekId): void
    {
        $this->db->table('fantasy_moves')->insertGetId([
            'league_id' => $leagueId,
            'franchise_id' => $franchiseId,
            'team_id' => $teamId,
            'kind' => $kind,
            'week_id' => $weekId,
            'created_at' => time(),
        ]);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
