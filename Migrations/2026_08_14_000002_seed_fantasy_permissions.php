<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;

/**
 * Who may look at a league, who may play in one, and who may start one.
 *
 * Three rather than two, and the third is the one worth arguing about. Picks
 * splits view from play because reading without playing is what makes a game
 * safe to leave open on a site with unverified members, and that split applies
 * here unchanged. Creating a LEAGUE is different again: a league is a durable
 * thing with other people's seasons inside it, and a forum that lets anybody
 * make one ends up with forty abandoned leagues and no way to tell which are
 * real. So `fantasy.create` is granted to members here — this site wants it —
 * but it exists as its own switch for the day that stops being true.
 *
 * Guests may look, for the reason Picks gives: a league table nobody can see
 * until they sign up is a league table nobody signs up for.
 *
 * Running a league — the draft, the schedule, forcing a lineup — is not a
 * permission. It belongs to the commissioner of that particular league and to
 * admins, which is a question about a ROW rather than about a group, and
 * permissions cannot express it. It is checked in the controller instead.
 */
return new class extends Migration {
    /** Group ids seeded by core: 1 administrators, 2 moderators, 3 members, 4 guests. */
    private const GRANTS = [
        ['fantasy.view', 3],
        ['fantasy.view', 4],
        ['fantasy.play', 3],
        ['fantasy.create', 3],
    ];

    public function up(): void
    {
        $table = $this->db->prefixed('permissions');

        foreach (self::GRANTS as [$permission, $group]) {
            $exists = $this->db->table('permissions')
                ->where('group_id', $group)
                ->where('permission', $permission)
                ->where('resource', '')
                ->exists();

            if ($exists) {
                continue;
            }

            $this->db->query(
                "INSERT INTO `{$table}` (`group_id`, `permission`, `resource`, `granted`) VALUES (?, ?, '', 1)",
                [$group, $permission]
            );
        }
    }

    public function down(): void
    {
        $this->db->table('permissions')
            ->whereIn('permission', ['fantasy.view', 'fantasy.play', 'fantasy.create'])
            ->deleteAll();
    }
};
