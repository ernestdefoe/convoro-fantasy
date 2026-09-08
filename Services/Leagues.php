<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Services;

use Convoro\Extensions\Fantasy\Services\Sports\Scoring as SportScoring;

use Convoro\Engine\Database\Connection;
use Convoro\Engine\Support\Str;

/**
 * Leagues and the franchises inside them.
 *
 * 🚨 **A franchise is the unit, not a member.** Everything downstream — rosters,
 * lineups, matchups, scores — points at a franchise id, and the member is looked
 * up through it. That indirection is what lets a league survive somebody
 * deleting their account: the franchise row goes with them, but nothing else in
 * the league needed to know who they were.
 *
 * 🚨 **A league's rules are frozen once its draft starts.** Roster size and the
 * number of starters decide how many draft slots exist, and a commissioner
 * lowering the roster size halfway through a draft would leave picks that refer
 * to slots the board no longer has. `mayChangeRules()` is the one place that is
 * decided, and both the form and the controller ask it.
 */
final class Leagues
{
    public function __construct(private readonly Connection $db)
    {
    }

    /* ------------------------------------------------------------- reading */

    public function find(int $id): ?array
    {
        $row = $this->db->table('fantasy_leagues')->where('id', $id)->first();

        return $row === null ? null : $this->shape($row);
    }

    public function bySlug(string $slug): ?array
    {
        $row = $this->db->table('fantasy_leagues')->where('slug', $slug)->first();

        return $row === null ? null : $this->shape($row);
    }

    /**
     * Every league, newest first, with the franchise count attached.
     *
     * 🚨 One query and a second for the counts, rather than a count per league.
     * The listing is the page every member lands on and a per-row subquery is
     * how it becomes slow on the only site that ever gets big enough to notice.
     *
     * @return list<array<string, mixed>>
     */
    public function all(?int $seasonId = null): array
    {
        $query = $this->db->table('fantasy_leagues')->orderByDesc('id');

        if ($seasonId !== null) {
            $query->where('season_id', $seasonId);
        }

        $leagues = array_map(fn (array $row): array => $this->shape($row), $query->get());

        if ($leagues === []) {
            return [];
        }

        $counts = [];
        $ids = array_column($leagues, 'id');
        $table = $this->db->prefixed('fantasy_franchises');

        // 🚨 Raw, because the builder has no `selectRaw` and an aggregate is the
        // one thing it cannot express. The ids are integers this method just
        // read out of the database, and they are cast on the way in regardless.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        foreach (
            $this->db->select(
                "SELECT `league_id`, COUNT(*) AS `total` FROM `{$table}`"
                . " WHERE `league_id` IN ({$placeholders}) GROUP BY `league_id`",
                array_map('intval', $ids)
            ) as $row
        ) {
            $counts[(int) $row['league_id']] = (int) $row['total'];
        }

        foreach ($leagues as $index => $league) {
            $leagues[$index]['franchise_count'] = $counts[$league['id']] ?? 0;
        }

        return $leagues;
    }

    /* ------------------------------------------------------------- writing */

    /**
     * Create a league, with the member who asked as its commissioner and first
     * franchise.
     *
     * 🚨 The creator joins in the same breath. A league whose commissioner is
     * not in it is a state every screen would have to handle — "your franchise"
     * with nothing behind it — for the sake of a case nobody wants.
     *
     * @param array<string, mixed> $input
     */
    public function create(array $input, int $userId, Settings $settings): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $roster = min(25, max(1, (int) ($input['roster_size'] ?? $settings->defaultRoster())));

        $league = [
            'name' => mb_substr($name, 0, 190),
            'slug' => $this->uniqueSlug($name),
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'season_id' => (int) ($input['season_id'] ?? 0),
            'commissioner_id' => $userId,
            'status' => 'setup',
            'max_franchises' => min(32, max(2, (int) ($input['max_franchises'] ?? $settings->defaultFranchises()))),
            'roster_size' => $roster,

            // 🚨 Never more than the roster it is chosen from, or every lineup
            // screen is a form that cannot be completed.
            'starters' => min($roster, max(1, (int) ($input['starters'] ?? $settings->defaultStarters()))),

            'is_public' => (int) ((string) ($input['is_public'] ?? '1') === '1'),
            'join_code' => $this->joinCode(),
            'draft_type' => (string) ($input['draft_type'] ?? 'snake') === 'linear' ? 'linear' : 'snake',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ];

        /*
         * 🚨 The defaults come from the SPORT this season is played in, not
         * from one set of numbers. Points scored, points allowed, margin, a
         * win, a shutout and an upset all mean something in every sport, but a
         * gridiron team scores about thirty points a game, a basketball team a
         * hundred and ten, and a football team one and a half. A rate of 1.0
         * per point is a sensible week in one, an absurd 110-point week in
         * another and rounding error in the third.
         *
         * Anything the form actually sent still wins — this only decides what a
         * commissioner starts from.
         */
        $defaults = SportScoring::defaultsFor($this->leagueKeyOf((int) $league['season_id'])) + self::SCORING;

        foreach (self::SCORING as $column => $fallback) {
            $league[$column] = array_key_exists($column, $input)
                ? $this->money($input[$column])
                : ($defaults[$column] ?? $fallback);
        }

        $id = $this->db->table('fantasy_leagues')->insertGetId($league);

        $this->join($id, $userId, '');

        return $this->find($id) ?? [];
    }

    /**
     * Change a league's settings.
     *
     * 🚨 What may change depends on whether the draft has happened, and the two
     * sets are separated here rather than trusted to the form. Scoring may
     * change at any time — leagues really do fix a broken rule in week three —
     * but the SHAPE of the league may not once the board exists.
     *
     * @param array<string, mixed> $input
     */
    public function update(array $league, array $input): void
    {
        $values = ['updated_at' => $this->now()];

        $name = trim((string) ($input['name'] ?? ''));

        if ($name !== '') {
            $values['name'] = mb_substr($name, 0, 190);
        }

        if (array_key_exists('description', $input)) {
            $values['description'] = trim((string) $input['description']) ?: null;
        }

        if (array_key_exists('is_public', $input)) {
            $values['is_public'] = (int) ((string) $input['is_public'] === '1');
        }

        foreach (array_keys(self::SCORING) as $column) {
            if (array_key_exists($column, $input)) {
                $values[$column] = $this->money($input[$column]);
            }
        }

        if ($this->mayChangeRules($league)) {
            $roster = min(25, max(1, (int) ($input['roster_size'] ?? $league['roster_size'])));

            $values['roster_size'] = $roster;
            $values['starters'] = min($roster, max(1, (int) ($input['starters'] ?? $league['starters'])));
            $values['max_franchises'] = min(32, max(2, (int) ($input['max_franchises'] ?? $league['max_franchises'])));

            if (array_key_exists('draft_type', $input)) {
                $values['draft_type'] = (string) $input['draft_type'] === 'linear' ? 'linear' : 'snake';
            }

            if (array_key_exists('draft_pick_seconds', $input)) {
                $values['draft_pick_seconds'] = max(0, (int) $input['draft_pick_seconds']);
            }
        }

        $this->db->table('fantasy_leagues')->where('id', $league['id'])->updateAll($values);
    }

    public function setStatus(int $leagueId, string $status): void
    {
        $this->db->table('fantasy_leagues')->where('id', $leagueId)->updateAll([
            'status' => $status,
            'updated_at' => $this->now(),
        ]);
    }

    public function delete(int $leagueId): void
    {
        // Every child table cascades from here; see the migration.
        $this->db->table('fantasy_leagues')->where('id', $leagueId)->deleteAll();
    }

    /* ---------------------------------------------------------- franchises */

    /**
     * @return list<array<string, mixed>>
     */
    public function franchises(int $leagueId): array
    {
        $rows = $this->withUser()
            ->where($this->franchisesTable() . '.league_id', $leagueId)
            ->orderBy($this->franchisesTable() . '.draft_order')
            ->orderBy($this->franchisesTable() . '.id')
            ->get();

        return array_map(fn (array $row): array => $this->shapeFranchise($row), $rows);
    }

    public function franchise(int $leagueId, int $userId): ?array
    {
        $row = $this->withUser()
            ->where($this->franchisesTable() . '.league_id', $leagueId)
            ->where($this->franchisesTable() . '.user_id', $userId)
            ->first();

        return $row === null ? null : $this->shapeFranchise($row);
    }

    public function franchiseById(int $id): ?array
    {
        $row = $this->withUser()
            ->where($this->franchisesTable() . '.id', $id)
            ->first();

        return $row === null ? null : $this->shapeFranchise($row);
    }

    /**
     * Franchises with the member's name alongside.
     *
     * 🚨 `username` only. Convoro has no `display_name` column — that is
     * Flarum's, and selecting it is a hard SQL error rather than a blank.
     * `shapeFranchise()` builds the displayed name from what is here.
     */
    private function withUser(): \Convoro\Engine\Database\Query\Builder
    {
        $franchises = $this->franchisesTable();
        $users = $this->db->prefixed('users');

        return $this->db->table('fantasy_franchises')
            ->select($franchises . '.*', $users . '.username AS username')
            ->leftJoin($users, $users . '.id', '=', $franchises . '.user_id');
    }

    private function franchisesTable(): string
    {
        return $this->db->prefixed('fantasy_franchises');
    }

    /**
     * Put a member in a league.
     *
     * 🚨 Returns the existing franchise rather than failing when they are
     * already in. Joining twice is a double-click, not an error, and the unique
     * index means the second insert would fail anyway — this just makes the
     * outcome the one the member expected.
     */
    public function join(int $leagueId, int $userId, string $name): array
    {
        $existing = $this->franchise($leagueId, $userId);

        if ($existing !== null) {
            return $existing;
        }

        $this->db->table('fantasy_franchises')->insertGetId([
            'league_id' => $leagueId,
            'user_id' => $userId,
            'name' => mb_substr(trim($name), 0, 100),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        return $this->franchise($leagueId, $userId) ?? [];
    }

    public function rename(int $franchiseId, string $name): void
    {
        $this->db->table('fantasy_franchises')->where('id', $franchiseId)->updateAll([
            'name' => mb_substr(trim($name), 0, 100),
            'updated_at' => $this->now(),
        ]);
    }

    /**
     * Take a franchise out of a league.
     *
     * 🚨 Only before the draft. Afterwards the franchise owns teams, has played
     * matchups and appears in a schedule everybody else's fixtures are built
     * from — removing it would rewrite results that have already happened. A
     * commissioner who needs somebody gone mid-season replaces the member, which
     * is what `handOver()` is for.
     */
    public function leave(array $league, int $franchiseId): bool
    {
        if (!$this->mayChangeRules($league)) {
            return false;
        }

        $this->db->table('fantasy_franchises')->where('id', $franchiseId)->deleteAll();

        return true;
    }

    /** Give a franchise to somebody else, keeping everything it owns. */
    public function handOver(int $franchiseId, int $userId): void
    {
        $this->db->table('fantasy_franchises')->where('id', $franchiseId)->updateAll([
            'user_id' => $userId,
            'updated_at' => $this->now(),
        ]);
    }

    /* ------------------------------------------------------------ questions */

    /** Whether the shape of the league may still be changed. */
    public function mayChangeRules(array $league): bool
    {
        return (string) $league['status'] === 'setup';
    }

    public function isCommissioner(array $league, ?array $user): bool
    {
        if ($user === null) {
            return false;
        }

        return (int) ($league['commissioner_id'] ?? 0) === (int) $user['id'];
    }

    public function isFull(array $league): bool
    {
        return $this->db->table('fantasy_franchises')->where('league_id', $league['id'])->count()
            >= (int) $league['max_franchises'];
    }

    /* -------------------------------------------------------------- shaping */

    /**
     * Which competition a Picks season is, or '' when it does not say.
     *
     * 🚨 Read with a column probe rather than selected outright. Picks gained
     * `picks_seasons.league` after this extension was written, and a Fantasy
     * running against an older Picks is an ordinary situation — not one that
     * should throw an unknown-column error at the moment somebody creates a
     * league. Without it every league is gridiron, which is what they all were.
     */
    private function leagueKeyOf(int $seasonId): string
    {
        if ($seasonId < 1) {
            return '';
        }

        $table = $this->db->prefixed('picks_seasons');

        try {
            $has = false;

            foreach ($this->db->select("SHOW COLUMNS FROM `{$table}`") as $column) {
                if (($column['Field'] ?? '') === 'league') {
                    $has = true;
                    break;
                }
            }

            if (!$has) {
                return '';
            }

            $row = $this->db->selectOne("SELECT `league` FROM `{$table}` WHERE `id` = ?", [$seasonId]);

            return (string) ($row['league'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Scoring rule columns and what a league gets when nothing is chosen.
     *
     * 🚨 Listed once, here, and read by create, update and the admin form. Three
     * copies of this list is three chances for a rule to exist in the form and
     * not in the writer, which presents as a setting that saves and does nothing.
     */
    public const SCORING = [
        'points_per_point' => 1.0,
        'points_per_point_allowed' => -0.5,
        'win_bonus' => 10.0,
        'shutout_bonus' => 8.0,
        'points_per_margin' => 0.25,
        'upset_bonus' => 0.0,
    ];

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['season_id'] = (int) $row['season_id'];
        $row['commissioner_id'] = $row['commissioner_id'] === null ? null : (int) $row['commissioner_id'];
        $row['max_franchises'] = (int) $row['max_franchises'];
        $row['roster_size'] = (int) $row['roster_size'];
        $row['starters'] = (int) $row['starters'];
        $row['is_public'] = (bool) $row['is_public'];
        $row['draft_started_at'] = (int) $row['draft_started_at'];
        $row['draft_finished_at'] = (int) $row['draft_finished_at'];
        $row['draft_pick_seconds'] = (int) $row['draft_pick_seconds'];

        foreach (array_keys(self::SCORING) as $column) {
            $row[$column] = (float) $row[$column];
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeFranchise(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['league_id'] = (int) $row['league_id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['draft_order'] = (int) $row['draft_order'];

        /*
         * 🚨 The displayed name is decided in ONE place. A franchise with no
         * name of its own falls back to the member's username, and every screen
         * reads `display_name` rather than doing its own `?:` — three screens
         * doing it themselves is three chances for one of them to print an empty
         * heading.
         */
        $row['display_name'] = trim((string) $row['name']) !== ''
            ? (string) $row['name']
            : (string) ($row['username'] ?? '');

        return $row;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'league';
        $slug = $base;
        $suffix = 1;

        while ($this->db->table('fantasy_leagues')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$suffix;
        }

        return mb_substr($slug, 0, 190);
    }

    /**
     * 🚨 Not a secret worth protecting cryptographically, and not pretending to
     * be one. It stops a stranger wandering into a private league; anybody who
     * has it was given it. Ambiguous characters are left out because this gets
     * read aloud and typed by hand.
     */
    private function joinCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /** A scoring rule as stored: a number, clamped to something sane. */
    private function money(mixed $value): float
    {
        return max(-100, min(100, round((float) $value, 2)));
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
