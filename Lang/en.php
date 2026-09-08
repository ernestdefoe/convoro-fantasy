<?php

declare(strict_types=1);

/*
 * Every human string Fantasy puts on a screen.
 *
 * 🚨 `{name}` for a placeholder, and exactly TWO forms for a plural. Nothing
 * here is assembled out of fragments in a template: a sentence a translator
 * cannot see whole is a sentence they cannot translate, and word order is not a
 * constant across languages.
 */

return [
    'name' => 'Fantasy',
    'nav' => 'Fantasy',
    'title' => 'Fantasy',
    'admin_title' => 'Fantasy',

    /*
     * 🚨 "Teams", not "college football teams". A league plays whatever
     * competition its season is — the NFL, the NBA, the Premier League — and
     * naming one sport on the index is wrong on every board that follows
     * another. The number of starters is a per-league setting too, so it is not
     * stated here either.
     */
    'intro' => 'Draft teams, start a few of them a week, and play somebody new every week.',
    'not_found' => 'There is nothing here.',
    'needs_picks' => 'Fantasy needs Picks switched on to know the fixtures.',
    'save' => 'Save',
    'saved' => 'Saved.',
    'cancel' => 'Cancel',

    /* --------------------------------------------------------- permissions */

    'may_not_view' => 'You cannot see the fantasy leagues.',
    'may_not_play' => 'You cannot play fantasy.',
    'may_not_create' => 'You cannot start a league.',
    'not_in_this_league' => 'You are not in this league.',
    'not_the_commissioner' => 'Only the commissioner can do that.',
    'no_such_league' => 'There is no league here.',
    'no_such_franchise' => 'There is no team here.',
    'no_such_member' => 'There is nobody called {name}.',

    /* -------------------------------------------------------------- lobby */

    'lobby_title' => 'Fantasy leagues',
    'lobby_empty' => 'Nobody has started a league yet.',
    'lobby_empty_hint' => 'Start one and invite the forum.',
    'no_season' => 'There is no season to play. Set one up in Picks first.',
    'start_a_league' => 'Start a league',
    'league_name' => 'League name',
    'league_description' => 'What is this league?',
    'league_size' => 'How many teams',
    'roster_size' => 'Squad size',
    'starters' => 'Started each week',
    'draft_type' => 'Draft order',
    'draft_snake' => 'Snake — the order reverses every round',
    'draft_linear' => 'Linear — the same order every round',
    'visibility' => 'Who can join',
    'public' => 'Anybody on the forum',
    'private' => 'Only with the join code',
    'league_created' => 'Your league is ready. Invite people, then start the draft.',
    'league_needs_a_name' => 'Give the league a name.',
    'too_many_owned' => 'You already run {count} league.|You already run {count} leagues.',

    'season_label' => 'Season',
    'commissioner' => 'Commissioner',
    'members_count' => '{count} team|{count} teams',
    'spaces_left' => '{count} space left|{count} spaces left',

    /* ------------------------------------------------------------- joining */

    'join' => 'Join',
    'join_code' => 'Join code',
    'joined' => 'You are in. Give your team a name and wait for the draft.',
    'left' => 'You have left the league.',
    'leave' => 'Leave this league',
    'wrong_code' => 'That code does not match.',
    'league_is_full' => 'This league is full.',
    'draft_has_started' => 'The draft has already started.',
    'cannot_leave_now' => 'You cannot leave once the draft has started. Ask the commissioner.',
    'team_name' => 'Your team name',
    'franchise_renamed' => 'Your team has a new name.',
    'rename_team' => 'Rename my team',

    /* -------------------------------------------------------------- league */

    'standings' => 'Standings',
    'this_week' => 'This week',
    'matchups' => 'Matchups',
    'no_matchups' => 'No fixtures for this week yet.',
    'bye_week' => 'Bye',
    'record' => 'Record',
    'points_for' => 'For',
    'points_against' => 'Against',
    'wins' => 'W',
    'losses' => 'L',
    'ties' => 'T',
    'my_team' => 'My team',

    /*
     * 🚨 Distinct from `my_team`. A standings column headed "My team" above
     * everybody's franchises reads as a bug, and it was one — the same key was
     * used for both because they happened to be the same words in one place.
     */
    'franchise' => 'Team',
    'college_team' => 'Programme',
    'owner' => 'Manager',
    'set_lineup' => 'Set my lineup',
    'go_to_draft' => 'Draft room',
    'recent_moves' => 'Recent moves',
    'no_moves' => 'Nothing has changed hands yet.',
    'move_draft' => 'drafted {team}',
    'move_add' => 'signed {team}',
    'move_drop' => 'released {team}',
    'as_of' => 'Scored {when}.',

    'status_setup' => 'Waiting to draft',
    'status_drafting' => 'Drafting',
    'status_active' => 'Playing',
    'status_complete' => 'Finished',

    /* --------------------------------------------------------------- draft */

    'draft_room' => 'Draft room',
    'draft_not_started' => 'The draft has not started.',
    'draft_not_started_hint' => 'The commissioner starts it once everybody is in.',
    'start_draft' => 'Start the draft',
    'draft_started' => 'The draft is live — {count} pick to make.|The draft is live — {count} picks to make.',
    'draft_finished' => 'The draft is done.',
    'on_the_clock' => '{name} is on the clock',
    'your_turn' => 'You are on the clock',
    'pick_deadline' => 'Their time runs out {when}.',
    'round_number' => 'Round {number}',
    'pick_number' => 'Pick {number}',
    'available_teams' => 'Still available',
    'search_teams' => 'Search teams',
    'draft_this_team' => 'Draft',
    'pick_made' => 'Picked.',
    'pick_made_for_them' => 'Picked for them.',
    'skip_pick' => 'Pick for them',
    'autopicked' => 'auto',
    'nothing_available' => 'There is nobody left to draft.',
    'waiting_to_pick' => 'Waiting for {name}.',

    /* -------------------------------------------------------------- lineup */

    'lineup_title' => 'My lineup',
    'lineup_for_week' => 'Lineup for {week}',
    'starting_count' => '{count} of {total} started',
    'starting' => 'Starting',
    'bench' => 'Bench',
    'start_team' => 'Start',
    'bench_team' => 'Bench',
    'team_started' => 'Started.',
    'team_benched' => 'Benched.',
    'no_week' => 'There is no week to play yet.',
    'no_game_this_week' => 'No game this week',
    'plays' => 'plays {opponent}',
    'at' => 'at {opponent}',
    'locks' => 'Locks {when}',
    'locked_now' => 'Locked',
    'squad_empty' => 'You have no teams yet.',

    /* ---------------------------------------------------------- free agents */

    'free_agents' => 'Free agents',
    'no_free_agents' => 'Every team is taken.',
    'sign' => 'Sign',
    'release_first' => 'Release',
    'roster_full_hint' => 'Your squad is full — choose somebody to release.',
    'roster_changed' => 'Your squad has changed.',

    /* ------------------------------------------------------------ breakdown */

    'week_breakdown' => 'How this week scored',
    'no_breakdown' => 'Nothing has been scored yet.',
    'scored_column' => 'Scored',
    'allowed_column' => 'Allowed',
    'bonus_column' => 'Bonus',
    'points_column' => 'Points',
    'total' => 'Total',
    'roster' => 'Squad',
    'acquired_draft' => 'drafted',
    'acquired_waiver' => 'signed',
    'acquired_trade' => 'traded for',

    /* -------------------------------------------------------------- scoring */

    'scoring_rules' => 'Scoring',
    'points_per_point' => 'Per point scored',
    'points_per_point_allowed' => 'Per point allowed',
    'win_bonus' => 'For a win',
    'shutout_bonus' => 'For a shutout',
    'points_per_margin' => 'Per point of winning margin',
    'upset_bonus' => 'For beating a team drafted higher',

    /* ------------------------------------------------------------- problems */

    'problem_generic' => 'That did not work.',
    'problem_already_started' => 'The draft has already started.',
    'problem_too_few' => 'A league needs at least two teams before it can draft.',
    'problem_not_drafting' => 'This league is not drafting.',
    'problem_draft_over' => 'The draft is finished.',
    'problem_not_your_turn' => 'It is not your turn.',
    'problem_no_such_team' => 'There is no such team.',
    'problem_already_taken' => 'Somebody has already taken that team.',
    'problem_already_picked' => 'That pick has already been made.',
    'problem_nothing_available' => 'There is nobody left to draft.',
    'problem_not_yours' => 'That is not your team.',
    'problem_locked' => 'That game has already kicked off.',
    'problem_lineup_full' => 'Your lineup is full — bench somebody first.',
    'problem_not_active' => 'This league is not playing yet.',
    'problem_same_team' => 'That is the same team.',
    'problem_roster_full' => 'Your squad is full — choose somebody to release.',

    /* ---------------------------------------------------------------- admin */

    'admin_intro' => 'Fantasy leagues are run by their commissioners. What is here is the switch, what a new league starts with, and the tools for a league that has gone wrong.',
    'admin_enabled' => 'Fantasy is on',
    'admin_enabled_hint' => 'When this is off the fantasy pages do not exist.',
    'admin_needs_picks' => 'Picks is switched off. Fantasy reads its fixtures and scores from Picks and will have nothing to show until it is on.',
    'admin_max_owned' => 'Leagues one member may run',
    'admin_defaults' => 'What a new league starts with',
    'admin_autopick' => 'Pick for anybody whose draft clock runs out',
    'admin_autopick_hint' => 'A draft with no way to move on stops the first time somebody goes to work.',
    'admin_carry' => 'Carry a lineup over to the next week',
    'admin_carry_hint' => 'Off by default: a lineup that sets itself is a lineup nobody checks.',
    'admin_saved' => 'Saved.',
    'admin_leagues' => 'Leagues',
    'admin_no_leagues' => 'Nobody has started a league yet.',
    'admin_rescore' => 'Re-score',
    'admin_rescore_hint' => 'Reads every week again. Use this after correcting a result or changing a scoring rule.',
    'admin_rescored' => 'Re-scored {weeks} weeks, {scored} team results.',
    'admin_reschedule' => 'Rebuild fixtures',
    'admin_rescheduled' => 'Wrote fixtures for {weeks} weeks, {matchups} matchups.',
    'admin_adopt' => 'Hand to',
    'admin_adopt_hint' => 'Make somebody else the commissioner, for a league whose owner has gone.',
    'admin_adopted' => '{name} runs that league now.',
    'admin_delete' => 'Delete',
    'admin_delete_hint' => 'Type the league name to confirm. Everything in it goes — squads, lineups, fixtures and a whole season of scores.',
    'admin_name_mismatch' => 'That is not the league name.',
    'admin_deleted' => 'Deleted {name}.',

    /* --------------------------------------------------------------- health */

    'health_label' => 'Fantasy',
    'health_off' => 'Off',
    'health_off_note' => 'Fantasy is switched off.',
    'health_idle' => 'No leagues',
    'health_idle_note' => 'Nobody is playing yet.',
    'health_drafting' => 'Drafting',
    'health_drafting_note' => '{count} league is mid-draft.|{count} leagues are mid-draft.',
    'health_ok' => 'Running',
    'health_ok_note' => '{count} league playing. Scored {when}.|{count} leagues playing. Scored {when}.',
];
