<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;
use Convoro\Engine\Database\Schema\Blueprint;

/**
 * Leagues, franchises, rosters, lineups, matchups, scores and the draft.
 *
 * 🚨 **Nothing in here duplicates a fact Picks already owns.** A team is a row
 * in `picks_teams`, a week is a row in `picks_weeks`, a result is a row in
 * `picks_events`. This extension stores who owns which team, who started it, and
 * what that was worth — and reads everything else. Copying a score across would
 * mean two tables that can disagree about who won, and the one people complain
 * about would be whichever this file wrote.
 *
 * 🚨 **`fantasy_rosters` holds only what is held now.** A team is on exactly one
 * roster in a league or none, and that is a UNIQUE index rather than a rule the
 * application remembers — two people clicking Add on the same free agent in the
 * same second is not a hypothetical, it is Sunday morning. Dropping deletes the
 * row; the history lives in `fantasy_moves`, which is an append-only log and has
 * no uniqueness to fight over.
 *
 * 🚨 **A lineup locks per TEAM, at that team's own kickoff**, which is why there
 * is no `locked` column anywhere: the lock is `picks_events.cutoff_at` for the
 * game that team is playing, read fresh on every write. A stored flag would need
 * something to set it, and the thing that set it would be a cron that can be
 * late. Picks made the same decision for the same reason.
 *
 * 🚨 **Points are DECIMAL, never FLOAT.** Half-points are normal in fantasy
 * scoring, and a league decided by a total that is 0.30000000000000004 is a
 * league with an argument in it.
 *
 * The draft order lives on the franchise and the draft slots are materialised up
 * front in `fantasy_draft_picks` — every pick of every round exists, unfilled,
 * the moment the draft starts. It costs one insert of `rounds × franchises` rows
 * and it buys the two things a draft needs: whose turn it is, is a lookup rather
 * than a calculation, and a pick landing twice is refused by a unique index on
 * the slot rather than by whoever is holding the mouse.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema->create('fantasy_leagues', function (Blueprint $bp): void {
            $bp->id();
            $bp->string('name', 190);
            $bp->string('slug', 190);
            $bp->text('description')->nullable()->default(null);

            // The Picks season this league plays. Everything about weeks and
            // results is read through it.
            $bp->bigInt('season_id', true);

            // Whoever created it, and who runs the draft. Never deleted with the
            // member — see the FK below.
            $bp->bigInt('commissioner_id', true)->nullable()->default(null);

            /*
             * `setup` — taking members, no draft yet
             * `drafting` — the draft is live
             * `active` — playing
             * `complete` — the season is over
             *
             * A string rather than an enum so a fifth state is code and not a
             * schema change, which is the same call Picks made on week type.
             */
            $bp->string('status', 20)->default('setup');

            $bp->smallInt('max_franchises', true)->default(12);
            $bp->smallInt('roster_size', true)->default(8);
            $bp->smallInt('starters', true)->default(4);

            // Open to anybody who may play, or only to somebody holding the
            // code. The code is not a secret worth protecting cryptographically
            // — it stops a stranger wandering in, nothing more.
            $bp->bool('is_public')->default(true);
            $bp->string('join_code', 32)->default('');

            /*
             * Scoring, one column per rule.
             *
             * 🚨 Columns rather than a JSON blob, because the scoring pass
             * multiplies these by numbers from `picks_events` and a rule that
             * arrives as a string from `json_decode` is a rule that silently
             * becomes 0. They are also the thing a commissioner most wants to
             * see side by side, and a blob does not render as a form.
             */
            $bp->decimal('points_per_point', 6, 2)->default(1);
            $bp->decimal('points_per_point_allowed', 6, 2)->default(-0.5);
            $bp->decimal('win_bonus', 6, 2)->default(10);
            $bp->decimal('shutout_bonus', 6, 2)->default(8);
            $bp->decimal('points_per_margin', 6, 2)->default(0.25);

            /*
             * Beating a team that beat you to the draft board is the one bit of
             * flavour the format needs: an upset is worth extra when the team
             * you started was picked later than the one it beat. Zero switches
             * it off, and it is zero-by-default because it needs explaining.
             */
            $bp->decimal('upset_bonus', 6, 2)->default(0);

            // `snake` or `linear`. Snake is the default because it is what
            // everybody means by a draft.
            $bp->string('draft_type', 20)->default('snake');
            $bp->int('draft_started_at', true)->default(0);
            $bp->int('draft_finished_at', true)->default(0);

            /*
             * Seconds a franchise has on the clock, or 0 for no clock at all.
             * 🚨 Epoch-second arithmetic throughout this extension, for the
             * reason written at the top of Picks' own migration: a DATETIME
             * carries no zone and the comparison is then a property of php.ini.
             */
            $bp->int('draft_pick_seconds', true)->default(0);

            $bp->timestamps();

            $bp->unique('slug', 'fantasy_leagues_slug');
            $bp->index('season_id');
            $bp->index('status');

            /*
             * 🚨 SET NULL, not CASCADE. Deleting the member who happened to
             * create a league must not delete everybody else's season with them.
             * The league carries on commissioner-less until an admin adopts it.
             */
            $bp->foreign('season_id', 'id', $this->db->prefixed('picks_seasons'), 'CASCADE', 'CASCADE');
            $bp->foreign('commissioner_id', 'id', $this->db->prefixed('users'), 'SET NULL', 'CASCADE');
        });

        $this->schema->create('fantasy_franchises', function (Blueprint $bp): void {
            $bp->id();
            $bp->bigInt('league_id', true);
            $bp->bigInt('user_id', true);

            // What they call their team. Falls back to the username when blank,
            // which is done in one place so every screen agrees.
            $bp->string('name', 100)->default('');

            /*
             * 1-based position in the first round, 0 until the order is drawn.
             * The whole draft board is derived from it, so it is unique within
             * the league: two franchises sharing slot 3 is a draft with no
             * defined order and it must be impossible rather than unlucky.
             */
            $bp->smallInt('draft_order', true)->default(0);

            $bp->timestamps();

            $bp->unique(['league_id', 'user_id'], 'fantasy_franchises_one_each');
            $bp->index('league_id');

            $bp->foreign('league_id', 'id', $this->db->prefixed('fantasy_leagues'), 'CASCADE', 'CASCADE');
            $bp->foreign('user_id', 'id', $this->db->prefixed('users'), 'CASCADE', 'CASCADE');
        });

        $this->schema->create('fantasy_rosters', function (Blueprint $bp): void {
            $bp->id();
            $bp->bigInt('league_id', true);
            $bp->bigInt('franchise_id', true);
            $bp->bigInt('team_id', true);

            // `draft`, `waiver` or `trade`. Shown on the roster so a team that
            // appeared mid-season explains itself.
            $bp->string('acquired_via', 20)->default('draft');
            $bp->int('acquired_at', true)->default(0);

            $bp->timestamps();

            /*
             * 🚨 The constraint the whole add/drop path leans on: within one
             * league a team belongs to at most one franchise. Two simultaneous
             * adds cannot both succeed, and the loser gets an ordinary "already
             * taken" rather than a shared player.
             */
            $bp->unique(['league_id', 'team_id'], 'fantasy_rosters_one_owner');
            $bp->index(['league_id', 'franchise_id'], 'fantasy_rosters_by_franchise');

            $bp->foreign('league_id', 'id', $this->db->prefixed('fantasy_leagues'), 'CASCADE', 'CASCADE');
            $bp->foreign('franchise_id', 'id', $this->db->prefixed('fantasy_franchises'), 'CASCADE', 'CASCADE');
            $bp->foreign('team_id', 'id', $this->db->prefixed('picks_teams'), 'CASCADE', 'CASCADE');
        });

        $this->schema->create('fantasy_lineups', function (Blueprint $bp): void {
            $bp->id();
            $bp->bigInt('league_id', true);
            $bp->bigInt('franchise_id', true);
            $bp->bigInt('week_id', true);
            $bp->bigInt('team_id', true);

            /*
             * 🚨 The kickoff this row was locked against, copied from the event
             * at the moment it was started. Not the lock itself — that is still
             * read live — but the answer to "why can I not move this?", which a
             * member asks the second a game starts.
             */
            $bp->int('locks_at', true)->default(0);

            $bp->timestamps();

            $bp->unique(['league_id', 'franchise_id', 'week_id', 'team_id'], 'fantasy_lineups_one_each');
            $bp->index(['league_id', 'week_id'], 'fantasy_lineups_by_week');

            $bp->foreign('league_id', 'id', $this->db->prefixed('fantasy_leagues'), 'CASCADE', 'CASCADE');
            $bp->foreign('franchise_id', 'id', $this->db->prefixed('fantasy_franchises'), 'CASCADE', 'CASCADE');
            $bp->foreign('week_id', 'id', $this->db->prefixed('picks_weeks'), 'CASCADE', 'CASCADE');
            $bp->foreign('team_id', 'id', $this->db->prefixed('picks_teams'), 'CASCADE', 'CASCADE');
        });

        $this->schema->create('fantasy_scores', function (Blueprint $bp): void {
            $bp->id();
            $bp->bigInt('league_id', true);
            $bp->bigInt('franchise_id', true);
            $bp->bigInt('week_id', true);
            $bp->bigInt('team_id', true);

            // The game this came from, so the roster page can link to it and so
            // a re-score can find its way back.
            $bp->bigInt('event_id', true)->default(0);

            $bp->decimal('points', 8, 2)->default(0);

            /*
             * The workings, kept so a member can be shown WHY they got 31.5
             * rather than a number they have to trust. Scoring rules change
             * mid-season more often than anybody admits, and without these the
             * only way to explain an old week is to re-run it under today's
             * rules — which would give a different answer.
             */
            $bp->smallInt('scored', true)->default(0);
            $bp->smallInt('allowed', true)->default(0);
            $bp->bool('won')->default(false);
            $bp->bool('shutout')->default(false);
            $bp->decimal('bonus', 8, 2)->default(0);

            $bp->timestamps();

            $bp->unique(['league_id', 'franchise_id', 'week_id', 'team_id'], 'fantasy_scores_one_each');
            $bp->index(['league_id', 'week_id'], 'fantasy_scores_by_week');

            $bp->foreign('league_id', 'id', $this->db->prefixed('fantasy_leagues'), 'CASCADE', 'CASCADE');
            $bp->foreign('franchise_id', 'id', $this->db->prefixed('fantasy_franchises'), 'CASCADE', 'CASCADE');
            $bp->foreign('week_id', 'id', $this->db->prefixed('picks_weeks'), 'CASCADE', 'CASCADE');
            $bp->foreign('team_id', 'id', $this->db->prefixed('picks_teams'), 'CASCADE', 'CASCADE');
        });

        $this->schema->create('fantasy_matchups', function (Blueprint $bp): void {
            $bp->id();
            $bp->bigInt('league_id', true);
            $bp->bigInt('week_id', true);

            $bp->bigInt('home_franchise_id', true);

            /*
             * 🚨 NULL is a bye, and an odd number of franchises guarantees one
             * every week. Modelling it as an opponent-less matchup rather than
             * as a missing row means the schedule is complete: every franchise
             * appears exactly once per week, and "who am I playing?" always has
             * an answer, even when the answer is nobody.
             */
            $bp->bigInt('away_franchise_id', true)->nullable()->default(null);

            $bp->decimal('home_points', 8, 2)->default(0);
            $bp->decimal('away_points', 8, 2)->default(0);

            // `scheduled` while any game is unplayed, `final` once every game
            // both sides started has a confirmed result.
            $bp->string('status', 20)->default('scheduled');

            $bp->timestamps();

            $bp->unique(['league_id', 'week_id', 'home_franchise_id'], 'fantasy_matchups_home');
            $bp->index(['league_id', 'week_id'], 'fantasy_matchups_by_week');

            $bp->foreign('league_id', 'id', $this->db->prefixed('fantasy_leagues'), 'CASCADE', 'CASCADE');
            $bp->foreign('week_id', 'id', $this->db->prefixed('picks_weeks'), 'CASCADE', 'CASCADE');
            $bp->foreign('home_franchise_id', 'id', $this->db->prefixed('fantasy_franchises'), 'CASCADE', 'CASCADE');
            $bp->foreign('away_franchise_id', 'id', $this->db->prefixed('fantasy_franchises'), 'CASCADE', 'CASCADE');
        });

        $this->schema->create('fantasy_draft_picks', function (Blueprint $bp): void {
            $bp->id();
            $bp->bigInt('league_id', true);

            // 1-based, both of them. `overall` is the slot number across the
            // whole draft and is what "whose turn is it" reads.
            $bp->smallInt('round', true);
            $bp->smallInt('overall', true);

            $bp->bigInt('franchise_id', true);

            // NULL until the pick is made. This is the whole state machine.
            $bp->bigInt('team_id', true)->nullable()->default(null);

            $bp->int('made_at', true)->default(0);

            // Set when the clock ran out and the board picked for them, so the
            // draft recap can say so rather than implying somebody chose Rice.
            $bp->bool('autopicked')->default(false);

            $bp->timestamps();

            /*
             * 🚨 One franchise per slot, and one slot per number. Both are
             * unique because the draft controller's defence against a
             * double-submit is the database refusing the second write, not a
             * check-then-write that two requests can interleave inside.
             */
            $bp->unique(['league_id', 'overall'], 'fantasy_draft_slot');
            $bp->index(['league_id', 'franchise_id'], 'fantasy_draft_by_franchise');

            $bp->foreign('league_id', 'id', $this->db->prefixed('fantasy_leagues'), 'CASCADE', 'CASCADE');
            $bp->foreign('franchise_id', 'id', $this->db->prefixed('fantasy_franchises'), 'CASCADE', 'CASCADE');
            $bp->foreign('team_id', 'id', $this->db->prefixed('picks_teams'), 'SET NULL', 'CASCADE');
        });

        $this->schema->create('fantasy_moves', function (Blueprint $bp): void {
            $bp->id();
            $bp->bigInt('league_id', true);
            $bp->bigInt('franchise_id', true);
            $bp->bigInt('team_id', true);

            // `draft`, `add`, `drop`, `trade_in`, `trade_out`.
            $bp->string('kind', 20);

            // The week it happened in, or 0 outside the season.
            $bp->bigInt('week_id', true)->default(0);

            $bp->int('created_at', true)->default(0);

            /*
             * 🚨 No unique index, deliberately. This is an append-only log and
             * the same franchise adding, dropping and re-adding the same team is
             * three legitimate rows. Uniqueness here would silently lose the
             * middle one.
             */
            $bp->index(['league_id', 'created_at'], 'fantasy_moves_recent');

            $bp->foreign('league_id', 'id', $this->db->prefixed('fantasy_leagues'), 'CASCADE', 'CASCADE');
            $bp->foreign('franchise_id', 'id', $this->db->prefixed('fantasy_franchises'), 'CASCADE', 'CASCADE');
            $bp->foreign('team_id', 'id', $this->db->prefixed('picks_teams'), 'CASCADE', 'CASCADE');
        });
    }

    public function down(): void
    {
        // Children first: the foreign keys above refuse any other order.
        $this->schema->drop('fantasy_moves');
        $this->schema->drop('fantasy_draft_picks');
        $this->schema->drop('fantasy_matchups');
        $this->schema->drop('fantasy_scores');
        $this->schema->drop('fantasy_lineups');
        $this->schema->drop('fantasy_rosters');
        $this->schema->drop('fantasy_franchises');
        $this->schema->drop('fantasy_leagues');
    }
};
