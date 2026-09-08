<?php

declare(strict_types=1);

/*
 * Fantasy.
 *
 * 🚨 Four rules carry this module, and every one of them is about somebody not
 * winning unfairly.
 *
 *   - **The lineup lock.** A started team cannot be benched, and a benched team
 *     cannot be started, once its own game has kicked off. Otherwise a franchise
 *     watches the first quarter and moves whoever is losing.
 *   - **One owner per team.** Within a league a team belongs to exactly one
 *     franchise, and that is a UNIQUE index rather than a rule the application
 *     remembers — two people clicking Add on the same free agent in the same
 *     second is not hypothetical.
 *   - **A pick lands once.** A draft slot is filled by an UPDATE guarded on the
 *     slot still being empty, so a double-submit changes nothing the second time
 *     instead of overwriting somebody.
 *   - **Only finished games score.** A live score would move a total all
 *     afternoon and finalise a matchup at half time.
 *
 * 🚨 Nothing here reaches the network. Fantasy makes no outbound call at all —
 * Picks owns the key and the sync, and this module reads the rows it left.
 */

use Convoro\Engine\Convoro;
use Convoro\Extensions\Fantasy\Services\Draft;
use Convoro\Extensions\Fantasy\Services\Leagues;
use Convoro\Extensions\Fantasy\Services\Lineups;
use Convoro\Extensions\Fantasy\Services\Rosters;
use Convoro\Extensions\Fantasy\Services\Schedule;
use Convoro\Extensions\Fantasy\Services\Scoring;
use Convoro\Extensions\Fantasy\Services\Settings;
use Convoro\Extensions\Fantasy\Services\Sports\Scoring as SportScoring;

$app = Convoro::getInstance();
$db = $app->make('db');

$MARK = 'zz-fantasy';

$leagues = new Leagues($db);
$lineups = new Lineups($db);
$rosters = new Rosters($db, $lineups);
$settings = new Settings($db);
$draft = new Draft($db, $leagues, $settings);
$schedule = new Schedule($db, $leagues);
$scoring = new Scoring($db, $lineups);

/**
 * A league with two members, four teams, one week and one game.
 *
 * 🚨 Built out of real rows in the real tables rather than mocks, because every
 * property being tested here is enforced by a UNIQUE index or a foreign key. A
 * fake would pass while the database refused, which is the wrong way round.
 */
$fixture = static function (array $options = []) use ($db, $MARK, $leagues, $settings): array {
    /*
     * 🚨 A year of its own per fixture. `picks_seasons.year` is UNIQUE — which
     * is correct, a season IS its year — so every test that builds a league
     * needs a distinct one or the second one to run collides.
     */
    static $year = 2900;

    $seasonId = $db->table('picks_seasons')->insertGetId([
        'name' => 'zz Season',
        'slug' => $MARK . '-season-' . bin2hex(random_bytes(4)),
        'year' => ++$year,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $weekId = $db->table('picks_weeks')->insertGetId([
        'season_id' => $seasonId,
        'name' => 'zz Week 1',
        'week_number' => 1,
        'season_type' => 'regular',
        'is_open' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $teams = [];

    foreach (['alpha', 'bravo', 'charlie', 'delta'] as $name) {
        $teams[$name] = $db->table('picks_teams')->insertGetId([
            'name' => 'zz ' . $name,
            'slug' => $MARK . '-' . $name . '-' . bin2hex(random_bytes(4)),
            'conference' => 'zz Conference',
            'espn_id' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $users = [];

    foreach (['one', 'two'] as $who) {
        $tag = $MARK . '-' . $who . '-' . bin2hex(random_bytes(4));

        $users[$who] = $db->table('users')->insertGetId([
            'username' => $tag,
            'username_clean' => $tag,
            'email' => $tag . '@example.invalid',
            'password' => 'x',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $league = $leagues->create([
        'name' => 'zz League ' . bin2hex(random_bytes(4)),
        'season_id' => $seasonId,
        'max_franchises' => 4,
        'roster_size' => $options['roster'] ?? 2,
        'starters' => $options['starters'] ?? 1,
    ], $users['one'], $settings);

    $leagues->join((int) $league['id'], $users['two'], 'zz Two');

    return [
        'league' => $leagues->find((int) $league['id']),
        'seasonId' => $seasonId,
        'weekId' => $weekId,
        'teams' => $teams,
        'users' => $users,
        'franchises' => $leagues->franchises((int) $league['id']),
    ];
};

/** A game in the fixture week, with control over kickoff and result. */
$game = static function (array $f, string $home, string $away, array $options = []) use ($db): int {
    return $db->table('picks_events')->insertGetId([
        'week_id' => $f['weekId'],
        'home_team_id' => $f['teams'][$home],
        'away_team_id' => $f['teams'][$away],
        'match_at' => $options['match_at'] ?? (time() + 86400),
        'cutoff_at' => $options['cutoff_at'] ?? (time() + 86400),
        'status' => $options['status'] ?? 'scheduled',
        'home_score' => $options['home_score'] ?? null,
        'away_score' => $options['away_score'] ?? null,
        'result' => $options['result'] ?? '',
        'confirmed_at' => $options['confirmed_at'] ?? 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
};

$cleanUp = static function () use ($db, $MARK): void {
    /*
     * Leagues first: every fantasy table cascades from `fantasy_leagues`, and
     * the picks rows underneath cannot go while a roster still points at them.
     */
    foreach ($db->table('fantasy_leagues')->whereLike('slug', 'zz-league%')->get() as $league) {
        $db->table('fantasy_leagues')->where('id', (int) $league['id'])->deleteAll();
    }

    foreach ($db->table('picks_seasons')->whereLike('slug', $MARK . '%')->get() as $season) {
        foreach ($db->table('picks_weeks')->where('season_id', (int) $season['id'])->get() as $week) {
            $db->table('picks_events')->where('week_id', (int) $week['id'])->deleteAll();
            $db->table('picks_weeks')->where('id', (int) $week['id'])->deleteAll();
        }

        $db->table('picks_seasons')->where('id', (int) $season['id'])->deleteAll();
    }

    foreach ($db->table('picks_teams')->whereLike('slug', $MARK . '%')->get() as $team) {
        $db->table('picks_teams')->where('id', (int) $team['id'])->deleteAll();
    }

    foreach ($db->table('users')->whereLike('username_clean', $MARK . '%')->get() as $user) {
        $db->table('users')->where('id', (int) $user['id'])->deleteAll();
    }
};

$cleanUp();

return [
    /* ================================================== THE ROUND ROBIN === */

    'everybody plays everybody exactly once' => static function () use ($schedule): void {
        /*
         * The property the circle method exists for. Pairing at random produces
         * a season where two people never meet and somebody draws the same
         * opponent three times, and nobody notices until week six.
         */
        $rounds = $schedule->roundRobin([1, 2, 3, 4, 5, 6]);

        assertSame(5, count($rounds), 'six franchises should take five weeks to play everybody');

        $met = [];

        foreach ($rounds as $pairs) {
            foreach ($pairs as [$home, $away]) {
                $key = min($home, $away) . '-' . max($home, $away);

                assertTrue(!isset($met[$key]), 'these two are drawn against each other twice');

                $met[$key] = true;
            }
        }

        assertSame(15, count($met), 'six franchises make fifteen distinct pairings');
    },

    'nobody plays twice in a week' => static function () use ($schedule): void {
        foreach ($schedule->roundRobin([1, 2, 3, 4, 5, 6, 7, 8]) as $pairs) {
            $seen = [];

            foreach ($pairs as [$home, $away]) {
                foreach ([$home, $away] as $id) {
                    assertTrue(!isset($seen[$id]), 'a franchise is in two fixtures in the same week');

                    $seen[$id] = true;
                }
            }
        }
    },

    '🚨 an odd league gives everybody exactly one bye' => static function () use ($schedule): void {
        /*
         * The phantom entry. Without it the rotation has to special-case the
         * leftover on every round, and the special case is where a franchise
         * ends up double-booked or missing entirely.
         */
        $rounds = $schedule->roundRobin([1, 2, 3, 4, 5]);
        $byes = [];

        foreach ($rounds as $pairs) {
            $appearances = 0;

            foreach ($pairs as [$home, $away]) {
                $appearances += $away === null ? 1 : 2;

                if ($away === null) {
                    $byes[$home] = ($byes[$home] ?? 0) + 1;
                }
            }

            assertSame(5, $appearances, 'every franchise should appear once a week, bye or not');
        }

        assertSame(5, count($byes), 'somebody never got a bye');
        assertSame([1], array_values(array_unique(array_values($byes))), 'somebody got two byes');
    },

    /* ======================================================= THE DRAFT === */

    'a draft lays out every slot up front' => static function () use ($fixture, $draft, $db): void {
        $f = $fixture(['roster' => 2]);

        $result = $draft->start($f['league']);

        assertTrue($result['ok'], 'the draft would not start');

        // Two rounds, two franchises.
        assertSame(4, (int) $result['slots']);
        assertSame(4, $db->table('fantasy_draft_picks')->where('league_id', $f['league']['id'])->count());
    },

    '🚨 the snake reverses on even rounds' => static function () use ($fixture, $draft): void {
        // The entire fairness argument for the format: whoever picks last in
        // round one picks first in round two.
        $f = $fixture(['roster' => 2]);
        $draft->start($f['league']);

        $board = $draft->board((int) $f['league']['id']);

        assertSame($board[0]['franchise_id'], $board[3]['franchise_id'], 'the snake did not turn around');
        assertSame($board[1]['franchise_id'], $board[2]['franchise_id'], 'the snake did not turn around');
    },

    'a draft refuses to start with one franchise' => static function () use ($db, $MARK, $leagues, $settings, $draft): void {
        /*
         * A one-franchise draft "succeeds" and produces a league that can never
         * have a matchup, which shows up much later as an empty schedule nobody
         * can explain.
         */
        $seasonId = $db->table('picks_seasons')->insertGetId([
            'name' => 'zz Season',
            'slug' => $MARK . '-lonely-' . bin2hex(random_bytes(4)),
            'year' => 2999,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $tag = $MARK . '-lonely-' . bin2hex(random_bytes(4));

        $userId = $db->table('users')->insertGetId([
            'username' => $tag,
            'username_clean' => $tag,
            'email' => $tag . '@example.invalid',
            'password' => 'x',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $league = $leagues->create([
            'name' => 'zz League solo ' . bin2hex(random_bytes(4)),
            'season_id' => $seasonId,
            'roster_size' => 2,
            'starters' => 1,
        ], $userId, $settings);

        $result = $draft->start($leagues->find((int) $league['id']));

        assertTrue(!$result['ok']);
        assertSame('too_few', $result['problem']);
    },

    'a pick out of turn is refused' => static function () use ($fixture, $draft): void {
        $f = $fixture(['roster' => 2]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $others = array_values(array_filter(
            $f['franchises'],
            static fn (array $x): bool => (int) $x['id'] !== $slot['franchise_id']
        ));

        $result = $draft->pick($f['league'], (int) $others[0]['id'], $f['teams']['alpha']);

        assertTrue(!$result['ok']);
        assertSame('not_your_turn', $result['problem']);
    },

    '🚨 a team cannot be drafted twice in one league' => static function () use ($fixture, $draft): void {
        $f = $fixture(['roster' => 2]);
        $draft->start($f['league']);

        $first = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $first['franchise_id'], $f['teams']['alpha']);

        $second = $draft->onTheClock((int) $f['league']['id']);
        $result = $draft->pick($f['league'], $second['franchise_id'], $f['teams']['alpha']);

        assertTrue(!$result['ok']);
        assertSame('already_taken', $result['problem']);
    },

    'a completed draft makes the league active' => static function () use ($fixture, $draft, $leagues): void {
        $f = $fixture(['roster' => 1]);
        $draft->start($f['league']);

        foreach (['alpha', 'bravo'] as $name) {
            $slot = $draft->onTheClock((int) $f['league']['id']);

            assertTrue($slot !== null, 'the board ran out of slots early');

            $draft->pick($f['league'], $slot['franchise_id'], $f['teams'][$name]);
        }

        assertSame(null, $draft->onTheClock((int) $f['league']['id']));
        assertSame('active', (string) $leagues->find((int) $f['league']['id'])['status']);
    },

    'a drafted team lands on the roster' => static function () use ($fixture, $draft, $rosters): void {
        $f = $fixture(['roster' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        assertSame(1, $rosters->count((int) $f['league']['id'], $slot['franchise_id']));
    },

    /* ====================================================== THE LINEUP === */

    'a team can be started and benched before kickoff' => static function () use ($fixture, $draft, $lineups, $game): void {
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        $game($f, 'alpha', 'charlie');

        $started = $lineups->start($f['league'], $slot['franchise_id'], $f['weekId'], $f['teams']['alpha']);

        assertTrue($started['ok'], 'the team would not start');
        assertSame([$f['teams']['alpha']], $lineups->starting(
            (int) $f['league']['id'],
            $slot['franchise_id'],
            $f['weekId']
        ));

        $benched = $lineups->bench($f['league'], $slot['franchise_id'], $f['weekId'], $f['teams']['alpha']);

        assertTrue($benched['ok']);
        assertSame([], $lineups->starting((int) $f['league']['id'], $slot['franchise_id'], $f['weekId']));
    },

    '🚨 a team cannot be started after its own kickoff' => static function () use ($fixture, $draft, $lineups, $game): void {
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        $game($f, 'alpha', 'charlie', ['cutoff_at' => time() - 60]);

        $result = $lineups->start($f['league'], $slot['franchise_id'], $f['weekId'], $f['teams']['alpha']);

        assertTrue(!$result['ok']);
        assertSame('locked', $result['problem']);
    },

    '🚨 a started team cannot be benched after its own kickoff' => static function () use ($fixture, $draft, $lineups, $game): void {
        /*
         * The other direction, and the one that actually gets abused: watch the
         * first quarter, then remove whoever is losing.
         */
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        $kickoff = time() + 3600;
        $game($f, 'alpha', 'charlie', ['cutoff_at' => $kickoff]);

        $lineups->start($f['league'], $slot['franchise_id'], $f['weekId'], $f['teams']['alpha']);

        // Now stand an hour past kickoff.
        $result = $lineups->bench(
            $f['league'],
            $slot['franchise_id'],
            $f['weekId'],
            $f['teams']['alpha'],
            $kickoff + 60
        );

        assertTrue(!$result['ok']);
        assertSame('locked', $result['problem']);
    },

    'a team with no game this week is always movable' => static function () use ($fixture, $draft, $lineups): void {
        // A bye is not a result, and locking somebody out of moving a team that
        // is not playing is how people stop setting lineups.
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        $result = $lineups->start($f['league'], $slot['franchise_id'], $f['weekId'], $f['teams']['alpha']);

        assertTrue($result['ok']);
    },

    'a lineup will not go over the starter count' => static function () use ($fixture, $draft, $lineups, $rosters): void {
        $f = $fixture(['roster' => 2, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $franchiseId = $slot['franchise_id'];

        $draft->pick($f['league'], $franchiseId, $f['teams']['alpha']);

        // Round two snakes back to the same franchise second.
        $draft->pick($f['league'], $draft->onTheClock((int) $f['league']['id'])['franchise_id'], $f['teams']['bravo']);
        $draft->pick($f['league'], $draft->onTheClock((int) $f['league']['id'])['franchise_id'], $f['teams']['charlie']);
        $draft->pick($f['league'], $draft->onTheClock((int) $f['league']['id'])['franchise_id'], $f['teams']['delta']);

        $held = array_map(
            static fn (array $r): int => (int) $r['team_id'],
            $rosters->forFranchise((int) $f['league']['id'], $franchiseId)
        );

        assertSame(2, count($held), 'the franchise should hold two teams after two rounds');

        assertTrue($lineups->start($f['league'], $franchiseId, $f['weekId'], $held[0])['ok']);

        $second = $lineups->start($f['league'], $franchiseId, $f['weekId'], $held[1]);

        assertTrue(!$second['ok']);
        assertSame('lineup_full', $second['problem']);
    },

    'a team somebody else owns cannot be started' => static function () use ($fixture, $draft, $lineups): void {
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        $others = array_values(array_filter(
            $f['franchises'],
            static fn (array $x): bool => (int) $x['id'] !== $slot['franchise_id']
        ));

        $result = $lineups->start($f['league'], (int) $others[0]['id'], $f['weekId'], $f['teams']['alpha']);

        assertTrue(!$result['ok']);
        assertSame('not_yours', $result['problem']);
    },

    /* ===================================================== THE SCORING === */

    '🚨 an unfinished game scores nothing' => static function () use ($fixture, $draft, $lineups, $scoring, $game, $db): void {
        /*
         * `picks_events` carries a live score while a game is being played.
         * Scoring off that means a total that moves all afternoon and a matchup
         * that is final at half time.
         */
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        $game($f, 'alpha', 'charlie', [
            'status' => 'in_progress',
            'home_score' => 21,
            'away_score' => 7,
        ]);

        $lineups->start($f['league'], $slot['franchise_id'], $f['weekId'], $f['teams']['alpha']);

        $result = $scoring->week($f['league'], $f['weekId']);

        assertSame(0, $result['scored']);
        assertSame(0, $db->table('fantasy_scores')->where('league_id', $f['league']['id'])->count());
    },

    'a finished win scores points, allowed, margin and the bonus' => static function () use ($fixture, $draft, $lineups, $scoring, $game, $db): void {
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        $game($f, 'alpha', 'charlie', [
            'status' => 'finished',
            'home_score' => 31,
            'away_score' => 10,
            'result' => 'home',
            'confirmed_at' => time(),
        ]);

        $lineups->start($f['league'], $slot['franchise_id'], $f['weekId'], $f['teams']['alpha']);
        $scoring->week($f['league'], $f['weekId']);

        $row = $db->table('fantasy_scores')
            ->where('league_id', $f['league']['id'])
            ->where('team_id', $f['teams']['alpha'])
            ->first();

        assertTrue($row !== null, 'nothing was scored');

        /*
         * 31 scored × 1 = 31, 10 allowed × −0.5 = −5, 21 margin × 0.25 = 5.25,
         * +10 for the win. Written out because the whole point of storing the
         * workings is that a total can be checked rather than trusted — and
         * because the first version of this line said 36.25, which is what
         * happens when the margin is left out of the sum by hand.
         */
        assertSame('41.25', (string) (float) $row['points']);
        assertSame(31, (int) $row['scored']);
        assertSame(10, (int) $row['allowed']);
        assertSame(1, (int) $row['won']);
        assertSame(0, (int) $row['shutout']);
    },

    'the away side is scored from its own point of view' => static function () use ($fixture, $draft, $lineups, $scoring, $game, $db): void {
        // The calculation that goes wrong when a template does it: "points
        // allowed" quietly becomes the wrong team's score.
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        $game($f, 'charlie', 'alpha', [
            'status' => 'finished',
            'home_score' => 3,
            'away_score' => 24,
            'result' => 'away',
            'confirmed_at' => time(),
        ]);

        $lineups->start($f['league'], $slot['franchise_id'], $f['weekId'], $f['teams']['alpha']);
        $scoring->week($f['league'], $f['weekId']);

        $row = $db->table('fantasy_scores')
            ->where('league_id', $f['league']['id'])
            ->where('team_id', $f['teams']['alpha'])
            ->first();

        assertSame(24, (int) $row['scored']);
        assertSame(3, (int) $row['allowed']);
        assertSame(1, (int) $row['won']);
    },

    'a shutout pays the shutout bonus' => static function () use ($fixture, $draft, $lineups, $scoring, $game, $db): void {
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        $game($f, 'alpha', 'charlie', [
            'status' => 'finished',
            'home_score' => 14,
            'away_score' => 0,
            'result' => 'home',
            'confirmed_at' => time(),
        ]);

        $lineups->start($f['league'], $slot['franchise_id'], $f['weekId'], $f['teams']['alpha']);
        $scoring->week($f['league'], $f['weekId']);

        $row = $db->table('fantasy_scores')
            ->where('league_id', $f['league']['id'])
            ->where('team_id', $f['teams']['alpha'])
            ->first();

        assertSame(1, (int) $row['shutout']);

        // 14 + 0 + 3.5 margin + 10 win + 8 shutout.
        assertSame('35.5', (string) (float) $row['points']);
    },

    '🚨 a defeat is not charged the margin twice' => static function () use ($fixture, $draft, $lineups, $scoring, $game, $db): void {
        /*
         * Margin counts only in a win. Multiplying a negative margin by the same
         * rate would make a heavy defeat cost more than the points-allowed rule
         * already charges for it — the same penalty applied twice.
         */
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        $game($f, 'alpha', 'charlie', [
            'status' => 'finished',
            'home_score' => 7,
            'away_score' => 42,
            'result' => 'away',
            'confirmed_at' => time(),
        ]);

        $lineups->start($f['league'], $slot['franchise_id'], $f['weekId'], $f['teams']['alpha']);
        $scoring->week($f['league'], $f['weekId']);

        $row = $db->table('fantasy_scores')
            ->where('league_id', $f['league']['id'])
            ->where('team_id', $f['teams']['alpha'])
            ->first();

        // 7 scored, 42 allowed × −0.5 = −21. Nothing else.
        assertSame('-14', (string) (float) $row['points']);
    },

    '🚨 scoring twice writes the same answer' => static function () use ($fixture, $draft, $lineups, $scoring, $game, $db): void {
        /*
         * What makes it safe to put on a schedule next to a live-score sync that
         * will re-report a game which was already final.
         */
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $slot['franchise_id'], $f['teams']['alpha']);

        $game($f, 'alpha', 'charlie', [
            'status' => 'finished',
            'home_score' => 20,
            'away_score' => 17,
            'result' => 'home',
            'confirmed_at' => time(),
        ]);

        $lineups->start($f['league'], $slot['franchise_id'], $f['weekId'], $f['teams']['alpha']);

        $scoring->week($f['league'], $f['weekId']);
        $first = $db->table('fantasy_scores')->where('league_id', $f['league']['id'])->get();

        $scoring->week($f['league'], $f['weekId']);
        $second = $db->table('fantasy_scores')->where('league_id', $f['league']['id'])->get();

        assertSame(1, count($first), 'one started team should make one score row');
        assertSame(count($first), count($second), 'scoring twice made a second row');
        assertSame((float) $first[0]['points'], (float) $second[0]['points']);
    },

    /* ==================================================== THE FREE AGENT == */

    '🚨 a released team leaves the lineup with it' => static function () use ($fixture, $draft, $lineups, $rosters, $scoring, $game, $db): void {
        /*
         * The obvious way to cheat this format: start a team, release it, and
         * still be scored for it. The drop has to take the lineup row with it.
         */
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $slot = $draft->onTheClock((int) $f['league']['id']);
        $franchiseId = $slot['franchise_id'];
        $draft->pick($f['league'], $franchiseId, $f['teams']['alpha']);
        $draft->pick($f['league'], $draft->onTheClock((int) $f['league']['id'])['franchise_id'], $f['teams']['bravo']);

        $lineups->start($f['league'], $franchiseId, $f['weekId'], $f['teams']['alpha']);

        assertSame(1, count($lineups->starting((int) $f['league']['id'], $franchiseId, $f['weekId'])));

        $league = (new Leagues($db))->find((int) $f['league']['id']);

        $result = $rosters->swap(
            $league,
            $franchiseId,
            $f['teams']['charlie'],
            $f['teams']['alpha'],
            $f['weekId']
        );

        assertTrue($result['ok'], 'the swap was refused');
        assertSame([], $lineups->starting((int) $f['league']['id'], $franchiseId, $f['weekId']));
    },

    'a team somebody else owns cannot be signed' => static function () use ($fixture, $draft, $rosters, $db): void {
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $first = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $first['franchise_id'], $f['teams']['alpha']);

        $second = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $second['franchise_id'], $f['teams']['bravo']);

        $league = (new Leagues($db))->find((int) $f['league']['id']);

        $result = $rosters->swap(
            $league,
            $first['franchise_id'],
            $f['teams']['bravo'],
            $f['teams']['alpha'],
            $f['weekId']
        );

        assertTrue(!$result['ok']);
        assertSame('already_taken', $result['problem']);
    },

    /* ==================================================== THE STANDINGS === */

    '🚨 only a final matchup counts towards a record' => static function () use ($fixture, $draft, $lineups, $scoring, $schedule, $game, $db): void {
        /*
         * A week in progress moves the points column and nothing else, so nobody
         * is shown as having won a game that is still being played.
         */
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);
        $schedule->build($f['league']);

        $one = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $one['franchise_id'], $f['teams']['alpha']);

        $two = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $two['franchise_id'], $f['teams']['bravo']);

        $lineups->start($f['league'], $one['franchise_id'], $f['weekId'], $f['teams']['alpha']);
        $lineups->start($f['league'], $two['franchise_id'], $f['weekId'], $f['teams']['bravo']);

        // One finished, one still being played.
        $game($f, 'alpha', 'charlie', [
            'status' => 'finished',
            'home_score' => 30,
            'away_score' => 0,
            'result' => 'home',
            'confirmed_at' => time(),
        ]);

        $game($f, 'bravo', 'delta', ['status' => 'in_progress', 'home_score' => 3, 'away_score' => 0]);

        $scoring->week($f['league'], $f['weekId']);

        foreach ($schedule->standings((int) $f['league']['id']) as $row) {
            assertSame(0, (int) $row['wins'], 'a win was recorded while a game was still being played');
            assertSame(0, (int) $row['losses'], 'a loss was recorded while a game was still being played');
        }
    },

    'a finished week decides the matchup' => static function () use ($fixture, $draft, $lineups, $scoring, $schedule, $game): void {
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);
        $schedule->build($f['league']);

        $one = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $one['franchise_id'], $f['teams']['alpha']);

        $two = $draft->onTheClock((int) $f['league']['id']);
        $draft->pick($f['league'], $two['franchise_id'], $f['teams']['bravo']);

        $lineups->start($f['league'], $one['franchise_id'], $f['weekId'], $f['teams']['alpha']);
        $lineups->start($f['league'], $two['franchise_id'], $f['weekId'], $f['teams']['bravo']);

        $game($f, 'alpha', 'charlie', [
            'status' => 'finished', 'home_score' => 42, 'away_score' => 0,
            'result' => 'home', 'confirmed_at' => time(),
        ]);

        $game($f, 'bravo', 'delta', [
            'status' => 'finished', 'home_score' => 10, 'away_score' => 7,
            'result' => 'home', 'confirmed_at' => time(),
        ]);

        $scoring->week($f['league'], $f['weekId']);

        $standings = $schedule->standings((int) $f['league']['id']);
        $wins = array_sum(array_map(static fn (array $r): int => (int) $r['wins'], $standings));
        $losses = array_sum(array_map(static fn (array $r): int => (int) $r['losses'], $standings));

        assertSame(1, $wins, 'exactly one franchise should have won');
        assertSame(1, $losses, 'exactly one franchise should have lost');

        // Alpha's 42–0 shutout beats bravo's narrow win by a distance.
        assertSame($one['franchise_id'], (int) $standings[0]['id']);
    },

    '🚨 rebuilding the schedule leaves played weeks alone' => static function () use ($fixture, $draft, $schedule, $db): void {
        /*
         * "Regenerate the schedule" is the first thing a commissioner reaches
         * for when a league changes size, and it must not rewrite results that
         * have already happened.
         */
        $f = $fixture(['roster' => 1, 'starters' => 1]);
        $draft->start($f['league']);

        $first = $schedule->build($f['league']);

        assertTrue($first['matchups'] > 0, 'no fixtures were written');

        $db->table('fantasy_matchups')
            ->where('league_id', $f['league']['id'])
            ->updateAll(['home_points' => 99, 'status' => 'final']);

        $again = $schedule->build($f['league']);

        assertSame(0, $again['matchups'], 'a rebuild overwrote weeks that already had fixtures');

        $row = $db->table('fantasy_matchups')->where('league_id', $f['league']['id'])->first();

        assertSame('99', (string) (int) (float) $row['home_points'], 'a played result was rewritten');
    },

    /* ======================================================= THE PAGES === */

    '🚨 every template names a layout that exists' => static function () use ($app): void {
        /*
         * The admin screen shipped pointing at `system::admin/layout`, which is
         * not a template this site has. Nothing catches that until somebody opens
         * the page, and what they get is a 500 with no clue in it — `php -l` is
         * happy, the tests were happy, and the extension installs cleanly.
         *
         * The layout name is a string in a file, so this is the only place it can
         * be checked.
         */
        $engine = $app->make('template');
        $root = dirname(__DIR__) . '/Templates';

        foreach (glob($root . '/*/*.cvr') ?: [] as $file) {
            $body = (string) file_get_contents($file);

            if (preg_match("/@layout\\(\\s*'([^']+)'/", $body, $match) !== 1) {
                continue;
            }

            $found = true;

            try {
                $engine->render($match[1], []);
            } catch (\Throwable $e) {
                // Anything other than "not found" means the layout resolved and
                // then failed on data this test did not supply, which is fine.
                $found = !str_contains($e->getMessage(), 'not found');
            }

            assertTrue($found, 'template ' . basename($file) . ' extends a layout that does not exist: ' . $match[1]);
        }
    },

    'no template prints a raw language key' => static function (): void {
        /*
         * A key on screen is a missing phrase, and it reads as broken software.
         * Checked against the LANG FILE rather than a rendered page, because a
         * page only exercises the branches its fixture happens to reach.
         */
        $lang = require dirname(__DIR__) . '/Lang/en.php';
        $missing = [];

        foreach (glob(dirname(__DIR__) . '/Templates/*/*.cvr') ?: [] as $file) {
            // 🚨 Comments stripped FIRST, so a note naming a key it is explaining
            // cannot fail the check it is written beside.
            $body = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));

            preg_match_all("/@(?:lang|choice)\\(\\s*'fantasy\\.([a-z0-9_]+)'/", $body, $matches);

            foreach ($matches[1] as $key) {
                if (!array_key_exists($key, $lang)) {
                    $missing[] = basename($file) . ' → fantasy.' . $key;
                }
            }
        }

        assertSame([], array_values(array_unique($missing)), 'these phrases are used but not defined');
    },

    /* ======================================================== TEARDOWN === */

    'zz teardown' => static function () use ($cleanUp): void {
        $cleanUp();

        assertTrue(true);
    },

    /*
     * 🚨 Scoring in a sport that is not gridiron.
     *
     * Points scored, points allowed, margin, a win, a shutout and an upset all
     * mean something in every sport. The NUMBERS do not travel: a gridiron team
     * scores about thirty points a game, a basketball team a hundred and ten,
     * and a football team one and a half. A rate of 1.0 per point is a sensible
     * week in one, an absurd 110-point week in another and rounding error in
     * the third.
     */
    'a league starts from its own sport\'s numbers' => static function (): void {
        $gridiron = SportScoring::defaultsFor('cfb');
        $nfl = SportScoring::defaultsFor('nfl');
        $hardwood = SportScoring::defaultsFor('nba');
        $soccer = SportScoring::defaultsFor('epl');

        // 🚨 The NFL and college football are ONE sport and share their numbers.
        // Two competitions of the same game scored differently is exactly the
        // drift a per-sport table exists to prevent.
        assertSame($gridiron, $nfl);

        assertTrue($hardwood['points_per_point'] < $gridiron['points_per_point']);
        assertTrue($soccer['points_per_point'] > $gridiron['points_per_point']);

        /*
         * 🚨 A basketball shutout cannot happen, so the bonus is zero rather
         * than inherited. A rule that can never pay out reads as a broken rule,
         * not as an impossible event — and the franchise page now hides it for
         * exactly that reason.
         */
        assertSame(0.0, $hardwood['shutout_bonus']);
        assertFalse(SportScoring::shutoutsHappen('nba'));
        assertTrue(SportScoring::shutoutsHappen('nhl'));

        /*
         * 🚨 The whole point of calibrating them: a typical week is worth
         * roughly the same in any sport, so two leagues on one forum are
         * comparable and a basketball table does not read like a phone number.
         * A representative game, one starter.
         */
        $week = static fn (array $rules, int $scored, int $allowed): float =>
            $scored * $rules['points_per_point']
            + $allowed * $rules['points_per_point_allowed']
            + $rules['win_bonus']
            + ($scored - $allowed) * $rules['points_per_margin'];

        $gridironWeek = $week($gridiron, 31, 17);
        $hardwoodWeek = $week($hardwood, 112, 104);
        $soccerWeek = $week($soccer, 2, 1);
        $diamondWeek = $week(SportScoring::defaultsFor('mlb'), 5, 3);
        $iceWeek = $week(SportScoring::defaultsFor('nhl'), 3, 2);

        foreach ([
            'gridiron' => $gridironWeek,
            'hardwood' => $hardwoodWeek,
            'soccer' => $soccerWeek,
            'diamond' => $diamondWeek,
            'ice' => $iceWeek,
        ] as $sport => $points) {
            assertTrue(
                $points > 15.0 && $points < 45.0,
                $sport . ' scores ' . round($points, 1) . ' for an ordinary win, which is not in step with the rest'
            );
        }
    },

    'an unknown competition is scored as gridiron rather than as nothing' => static function (): void {
        /*
         * A season naming a competition this build has never heard of is
         * somebody's install, not a programming error — and every league that
         * existed before any of this was gridiron.
         */
        assertSame('gridiron', SportScoring::sportOf('quidditch'));
        assertSame('gridiron', SportScoring::sportOf(null));
        assertSame(SportScoring::defaultsFor('cfb'), SportScoring::defaultsFor('quidditch'));
    },
];
