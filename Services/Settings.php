<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Services;

use Convoro\Engine\Database\Connection;

/**
 * How this site runs fantasy, as opposed to how any one league does.
 *
 * 🚨 The split is the point. A LEAGUE's rules — roster size, scoring, how many
 * teams start — live on the league row, because two leagues on the same forum
 * are allowed to disagree about them and a commissioner owns them. What lives
 * here is what the site decides for everybody: whether fantasy is switched on
 * at all, the ceiling on how many leagues one member may run, and the defaults
 * a new league is created with.
 *
 * Getting that the wrong way round is the usual way this feature turns into a
 * support queue: scoring in a global setting means one league changing its rules
 * changes everybody's, and nobody can see who did it.
 *
 * 🚨 There is no credential here and there should never be one. Fantasy makes no
 * outbound call of its own — every fixture and score it reads was fetched by
 * Picks, which owns the key.
 */
final class Settings
{
    /** The stored defaults, and the list of keys `save()` will accept. */
    private const DEFAULTS = [
        'fantasy_enabled' => '0',

        /*
         * How many leagues one member may be commissioner of at once. The cap
         * exists because the failure mode of an open Create button is forty
         * abandoned leagues with one member each, and no way to tell those from
         * the real one.
         */
        'fantasy_max_owned' => '2',

        // Defaults a new league is created with. A commissioner changes them on
        // their own league; changing them here never touches an existing one.
        'fantasy_default_franchises' => '10',
        'fantasy_default_roster' => '8',
        'fantasy_default_starters' => '4',

        /*
         * 🚨 When a draft clock expires, pick for them. On by default, and the
         * reason is that the alternative is a draft that stops on a Tuesday
         * afternoon because one person went to work. A stalled draft is the
         * single most common way a fantasy league dies in week zero.
         */
        'fantasy_autopick' => '1',

        /*
         * Lineups that were never set score nothing, or the previous week's
         * lineup carries over.
         *
         * Carrying over is the kinder default and it is still off, because a
         * lineup that sets itself is a lineup nobody checks — and the first time
         * it starts a team on a bye it looks like the site lost their picks.
         */
        'fantasy_carry_lineups' => '0',

        // Written by the scoring pass, read by the screens.
        'fantasy_scored_at' => '0',
        'fantasy_scored_week' => '0',
    ];

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly Connection $db)
    {
    }

    public function get(string $key): string
    {
        $this->load();

        return $this->values[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    public function enabled(): bool
    {
        return $this->get('fantasy_enabled') === '1';
    }

    public function maxOwned(): int
    {
        return max(1, (int) $this->get('fantasy_max_owned'));
    }

    /**
     * Defaults for a new league, each clamped to something a league can
     * actually be played with.
     *
     * 🚨 Clamped HERE rather than only in the form, because these are also what
     * a league created by an importer or a test gets, and a league with one
     * franchise or zero starters is not a league — it is a page that divides by
     * zero later on.
     */
    public function defaultFranchises(): int
    {
        return min(32, max(2, (int) $this->get('fantasy_default_franchises')));
    }

    public function defaultRoster(): int
    {
        return min(25, max(1, (int) $this->get('fantasy_default_roster')));
    }

    /** Never more than the roster it is chosen from. */
    public function defaultStarters(): int
    {
        return min($this->defaultRoster(), max(1, (int) $this->get('fantasy_default_starters')));
    }

    public function autopicks(): bool
    {
        return $this->get('fantasy_autopick') === '1';
    }

    public function carriesLineups(): bool
    {
        return $this->get('fantasy_carry_lineups') === '1';
    }

    public function scoredAt(): int
    {
        return (int) $this->get('fantasy_scored_at');
    }

    public function scoredWeek(): int
    {
        return (int) $this->get('fantasy_scored_week');
    }

    /**
     * How many things are wrong, for the pip in the admin rail.
     *
     * 🚨 Zero whenever fantasy is switched off — an extension that is installed
     * and not in use is not a fault, and a permanent badge is how people learn
     * to ignore badges. Reads settings rows and one count; no outbound call,
     * because this runs on every admin page render.
     */
    public function problems(): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        /*
         * The one condition an operator can act on: a draft that was started and
         * has not finished. Everything else fantasy could complain about is
         * somebody's league being quiet, which is not a fault.
         */
        return $this->db->table('fantasy_leagues')->where('status', 'drafting')->count() > 0 ? 1 : 0;
    }

    /** @param array<string, string> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            $this->put($key, (string) $value);
        }

        $this->values = null;
    }

    public function put(string $key, string $value): void
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            return;
        }

        $existing = $this->db->table('settings')->where('key', $key)->first();

        if ($existing === null) {
            $this->db->table('settings')->insertGetId(['key' => $key, 'value' => $value]);
        } else {
            $this->db->table('settings')->where('key', $key)->updateAll(['value' => $value]);
        }

        if ($this->values !== null) {
            $this->values[$key] = $value;
        }
    }

    private function load(): void
    {
        if ($this->values !== null) {
            return;
        }

        $this->values = [];

        foreach ($this->db->table('settings')->whereIn('key', array_keys(self::DEFAULTS))->get() as $row) {
            $this->values[(string) $row['key']] = (string) $row['value'];
        }
    }
}
