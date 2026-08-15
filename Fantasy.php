<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy;

use Convoro\Engine\Module\Module;
use Convoro\Extensions\Fantasy\Services\Draft;
use Convoro\Extensions\Fantasy\Services\Leagues;
use Convoro\Extensions\Fantasy\Services\Lineups;
use Convoro\Extensions\Fantasy\Services\Rosters;
use Convoro\Extensions\Fantasy\Services\Schedule;
use Convoro\Extensions\Fantasy\Services\Scoring;
use Convoro\Extensions\Fantasy\Services\Settings;

/**
 * Fantasy — a season-long league played with college football TEAMS.
 *
 * 🚨 **Teams, not players, and that is a design decision rather than a shortcut.**
 *
 * The obvious reading of "fantasy football" is a player draft, and for the NFL
 * that is the right shape: thirty-two rosters, published depth charts, and a
 * stats feed everybody agrees on. College is none of those things. There are
 * around eleven thousand FBS players, depth charts that are deliberately
 * unreliable, and a transfer portal that rewrites rosters between seasons. A
 * player game needs a second data source, a weekly sync an order of magnitude
 * larger than the one Picks runs, and projections nobody publishes for the
 * bottom half of the Sun Belt.
 *
 * Drafting teams needs none of that. Every fact it scores from — the fixture,
 * the final score, which side won — is already in `picks_events` because Picks
 * syncs it for the pick'em. It also fits what this particular forum is: a place
 * organised into one sub-forum per team, where people follow programmes rather
 * than running backs.
 *
 * The scoring seam is deliberately narrow — `Scoring::week()` reads a game and
 * writes points — so a player-level game could be added later as a second
 * scorer without any of this being rebuilt.
 *
 * 🚨 **Everything reads Picks and nothing writes to it.** A team is a row in
 * `picks_teams`, a week is a row in `picks_weeks`, a result is a row in
 * `picks_events`. This extension owns who owns which team, who started it, and
 * what that was worth. Copying a score across would create two tables that can
 * disagree about who won, and the one people would complain about is this one.
 *
 * 🚨 **Nothing on a page makes an outbound call**, because there is nothing to
 * call: Picks owns the API key and the schedule. Fantasy's own scheduled work is
 * scoring, which is pure database.
 */
final class Fantasy extends Module
{
    /** Queue handlers. */
    public const SCORE = 'fantasy.score';
    public const CLOCK = 'fantasy.clock';

    public function register(): void
    {
        /*
         * Filed under `community` beside Picks, because it is the same kind of
         * thing: something members do with each other rather than a change to
         * how an existing part of the product behaves.
         */
        $this->adminNav()->area('community')
            ->item('fantasy', '/admin/fantasy', 'fantasy.nav')
            ->badge(fn (): int => $this->app->make('fantasy.settings')->problems());

        /*
         * Where this extension's pages are, so a widget can be scoped to them.
         *
         * 🚨 Without this the section is simply unknown, and a widget carrying
         * ANY section condition reads as "hidden from you" on every one of
         * these pages — including to the administrator arranging them, who is
         * given no reason. Registered in register() beside everything else,
         * rather than in boot(), so it exists before a page is rendered.
         */
        $this->app->make('widget_sections')->register('fantasy', [
            'label' => 'fantasy.nav',
            'paths' => ['/fantasy'],
            'module' => 'fantasy',
        ]);

        $db = $this->app->make('db');

        $this->app->singleton('fantasy.settings', fn (): Settings => new Settings($db));
        $this->app->singleton('fantasy.leagues', fn (): Leagues => new Leagues($db));
        $this->app->singleton('fantasy.lineups', fn (): Lineups => new Lineups($db));

        $this->app->singleton('fantasy.rosters', fn (): Rosters => new Rosters(
            $db,
            $this->app->make('fantasy.lineups'),
        ));

        $this->app->singleton('fantasy.draft', fn (): Draft => new Draft(
            $db,
            $this->app->make('fantasy.leagues'),
            $this->app->make('fantasy.settings'),
        ));

        $this->app->singleton('fantasy.schedule', fn (): Schedule => new Schedule(
            $db,
            $this->app->make('fantasy.leagues'),
        ));

        $this->app->singleton('fantasy.scoring', fn (): Scoring => new Scoring(
            $db,
            $this->app->make('fantasy.lineups'),
        ));

        /*
         * 🚨 Registered in register(), not boot() — the cron boots everything
         * and then reads the schedule, so a module registering its schedule
         * during boot relies on an order it does not control and fails silently.
         * This is the seam that sets the manifest floor.
         *
         * Both minutely, and both affordable because almost every tick returns
         * without touching a league. Scoring asks one question first — which
         * weeks have had a game settle since last time — and stops there when
         * the answer is none, which it is for most of the week. The draft clock
         * looks only at leagues actually drafting.
         *
         * Minutely rather than slower because a pick timer that fires a minute
         * late is forgiven and one that fires ten minutes late is a broken
         * draft, and because a final score people can see on the scoreboard
         * should be in the standings while they are still looking at it.
         */
        $this->schedule()->minutely(self::SCORE);
        $this->schedule()->minutely(self::CLOCK);
    }

    public function boot(): void
    {
        $queue = $this->app->make('queue');

        $queue->handle(self::SCORE, fn (): array => $this->scoreEverything());
        $queue->handle(self::CLOCK, fn (): array => $this->runClocks());

        $this->reportHealth();
    }

    /**
     * Score the weeks where something has actually changed.
     *
     * 🚨 The whole cost control is the first question: which weeks have had a
     * game settle since the last run? Scoring every week of every league every
     * minute would be a few hundred queries a minute to write rows identical to
     * the ones already there — a college season is seventeen weeks and sixteen
     * of them are finished and immovable at any moment.
     *
     * 🚨 The window reaches an hour BEHIND the last run rather than starting
     * exactly at it. A run that overlaps a score arriving would otherwise skip
     * that game for ever: it settled a second before the run began and is older
     * than the mark by the time the next one asks. An hour of deliberate overlap
     * costs a handful of no-op upserts and closes the gap, which matters because
     * the missed row is silent — the standings are simply wrong.
     *
     * @return array<string, int>
     */
    private function scoreEverything(): array
    {
        $settings = $this->app->make('fantasy.settings');

        if (!$settings->enabled()) {
            return ['leagues' => 0, 'scored' => 0];
        }

        $db = $this->app->make('db');

        $since = max(0, $settings->scoredAt() - 3600);
        $weeksInPlay = [];

        foreach (
            $db->table('picks_events')
                ->select('week_id')
                ->where('status', 'finished')
                ->where('confirmed_at', '>=', $since)
                ->get() as $row
        ) {
            $weeksInPlay[(int) $row['week_id']] = true;
        }

        // An open week too, so a lineup change is reflected even when no game
        // has settled since — the matchup totals move when somebody benches.
        foreach ($db->table('picks_weeks')->where('is_open', true)->pluck('id') as $id) {
            $weeksInPlay[(int) $id] = true;
        }

        $settings->put('fantasy_scored_at', (string) time());

        if ($weeksInPlay === []) {
            return ['leagues' => 0, 'scored' => 0];
        }

        $leagues = $this->app->make('fantasy.leagues');
        $scoring = $this->app->make('fantasy.scoring');
        $schedule = $this->app->make('fantasy.schedule');

        $scored = 0;
        $touched = 0;
        $lastWeek = 0;

        foreach ($db->table('fantasy_leagues')->where('status', 'active')->pluck('id') as $id) {
            $league = $leagues->find((int) $id);

            if ($league === null) {
                continue;
            }

            $touched++;

            foreach ($schedule->weeks((int) $league['season_id']) as $week) {
                $weekId = (int) $week['id'];

                if (!isset($weeksInPlay[$weekId])) {
                    continue;
                }

                $result = $scoring->week($league, $weekId);

                if ($result['scored'] > 0) {
                    $scored += $result['scored'];
                    $lastWeek = $weekId;
                }
            }
        }

        if ($lastWeek > 0) {
            $settings->put('fantasy_scored_week', (string) $lastWeek);
        }

        return ['leagues' => $touched, 'scored' => $scored];
    }

    /**
     * Re-score one league from the beginning, for an operator who has corrected
     * a result or changed a scoring rule.
     *
     * 🚨 Not on the schedule. This is the expensive path and it exists because
     * the cheap one deliberately ignores settled weeks — so a corrected score
     * from three weeks ago would never be picked up otherwise.
     *
     * @return array<string, int>
     */
    public function rescore(int $leagueId): array
    {
        $league = $this->app->make('fantasy.leagues')->find($leagueId);

        if ($league === null) {
            return ['weeks' => 0, 'scored' => 0];
        }

        $scoring = $this->app->make('fantasy.scoring');
        $schedule = $this->app->make('fantasy.schedule');

        $weeks = 0;
        $scored = 0;

        foreach ($schedule->weeks((int) $league['season_id']) as $week) {
            $result = $scoring->week($league, (int) $week['id']);

            $weeks++;
            $scored += $result['scored'];
        }

        return ['weeks' => $weeks, 'scored' => $scored];
    }

    /**
     * Fill in expired draft clocks.
     *
     * 🚨 One pick per league per run, deliberately. A league that has been
     * stalled overnight would otherwise have its entire remaining draft
     * auto-completed inside one cron tick — technically correct and, to the
     * twelve people who wake up to it, indistinguishable from the site having
     * eaten their draft. One a minute is visible, interruptible, and gives
     * whoever is on the clock a chance to come back.
     *
     * @return array<string, int>
     */
    private function runClocks(): array
    {
        $settings = $this->app->make('fantasy.settings');

        if (!$settings->enabled() || !$settings->autopicks()) {
            return ['picked' => 0];
        }

        $db = $this->app->make('db');
        $leagues = $this->app->make('fantasy.leagues');
        $draft = $this->app->make('fantasy.draft');

        $picked = 0;

        foreach ($db->table('fantasy_leagues')->where('status', 'drafting')->pluck('id') as $id) {
            $league = $leagues->find((int) $id);

            if ($league === null || (int) $league['draft_pick_seconds'] < 1) {
                continue;
            }

            $slot = $draft->onTheClock((int) $league['id']);
            $deadline = $draft->deadline($league, $slot);

            if ($slot === null || $deadline < 1 || time() < $deadline) {
                continue;
            }

            if (($draft->autopick($league))['ok']) {
                $picked++;
            }
        }

        return ['picked' => $picked];
    }

    /**
     * Say how we are on the screen an operator opens when something is wrong.
     *
     * 🚨 Reads only rows this site already has. A health check that reaches off
     * the machine hangs on the day the thing it reaches is down — which is the
     * day somebody opens it. That is core's own rule for this registry.
     *
     * 🚨 Guarded with `bound()`. The registry arrived after the version this
     * extension's manifest requires, so on an older core Fantasy simply does not
     * appear on the health screen rather than fatalling on a missing binding.
     */
    private function reportHealth(): void
    {
        if (!$this->app->bound('health_checks')) {
            return;
        }

        $this->app->make('health_checks')->register('fantasy', 'running', function (): array {
            $settings = $this->app->make('fantasy.settings');
            $label = __('fantasy.health_label');

            if (!$settings->enabled()) {
                return [
                    'label' => $label,
                    'value' => __('fantasy.health_off'),
                    'tone' => 'ok',
                    'note' => __('fantasy.health_off_note'),
                ];
            }

            $db = $this->app->make('db');
            $drafting = $db->table('fantasy_leagues')->where('status', 'drafting')->count();

            if ($drafting > 0) {
                return [
                    'label' => $label,
                    'value' => __('fantasy.health_drafting'),
                    'tone' => 'warn',
                    'note' => __n('fantasy.health_drafting_note', $drafting),
                ];
            }

            $active = $db->table('fantasy_leagues')->where('status', 'active')->count();

            if ($active < 1) {
                return [
                    'label' => $label,
                    'value' => __('fantasy.health_idle'),
                    'tone' => 'ok',
                    'note' => __('fantasy.health_idle_note'),
                ];
            }

            return [
                'label' => $label,
                'value' => __('fantasy.health_ok'),
                'tone' => 'ok',

                /*
                 * 🚨 Paired with when scoring last ran. A figure with no age
                 * attached is a figure nobody can act on, and the point at which
                 * an operator stops looking at this screen.
                 */
                'note' => __n('fantasy.health_ok_note', $active, [
                    'when' => \Convoro\Extensions\Picks\Picks::ago($settings->scoredAt()),
                ]),
            ];
        });
    }
}
