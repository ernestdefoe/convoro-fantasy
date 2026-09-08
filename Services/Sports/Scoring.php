<?php

declare(strict_types=1);

namespace Convoro\Extensions\Fantasy\Services\Sports;

/**
 * Scoring rules that make sense in the sport being played.
 *
 * 🚨 The RULES are the same in every sport and the NUMBERS are not, which is
 * the whole of this file. Points scored, points allowed, margin of victory, a
 * win, a shutout and an upset are all meaningful whether the game is football
 * or hockey. But a football team scores about thirty points a game, a
 * basketball team about a hundred and ten, and a football — the other kind —
 * team about one and a half. A rate of 1.0 per point is a sensible week in
 * gridiron, an absurd 110-point week in basketball, and rounding error in
 * soccer.
 *
 * So a new league starts from its own sport's numbers. Everything stays
 * editable: a commissioner who wants football's rates in a basketball league
 * can have them, and nothing here overrides a league that already exists.
 *
 * 🚨 The defaults below are calibrated so a typical starting lineup scores
 * roughly the same TOTAL in any sport — around a hundred points a week for four
 * starters. That is what makes two leagues on the same forum comparable, and
 * what stops a basketball league's standings reading like a phone number.
 */
final class Scoring
{
    /**
     * Per SPORT, not per league. The NFL and college football score the same
     * way and want the same numbers; repeating them per competition is how two
     * leagues of one sport quietly end up scored differently.
     *
     * @var array<string, array<string, float>>
     */
    private const RULES = [
        /* ~30 points a game, ~14 point wins. */
        'gridiron' => [
            'points_per_point' => 1.0,
            'points_per_point_allowed' => -0.5,
            'win_bonus' => 10.0,
            'shutout_bonus' => 8.0,
            'points_per_margin' => 0.25,
        ],

        /*
         * ~112 points a game. A quarter-point per point keeps a good week near
         * thirty rather than near a hundred and twenty.
         *
         * 🚨 The shutout bonus is ZERO because a basketball shutout cannot
         * happen. Leaving football's 8 in would be a rule that never once pays
         * out, which reads as a broken rule rather than an impossible event.
         */
        'hardwood' => [
            'points_per_point' => 0.25,
            'points_per_point_allowed' => -0.125,
            'win_bonus' => 10.0,
            'shutout_bonus' => 0.0,
            'points_per_margin' => 0.5,
        ],

        /* ~4.5 runs a game, and a shutout is a genuine event. */
        'diamond' => [
            'points_per_point' => 4.0,
            'points_per_point_allowed' => -2.0,
            'win_bonus' => 10.0,
            'shutout_bonus' => 12.0,
            'points_per_margin' => 2.0,
        ],

        /* ~3 goals a game; a shutout is the goaltender's whole night. */
        'ice' => [
            'points_per_point' => 6.0,
            'points_per_point_allowed' => -3.0,
            'win_bonus' => 10.0,
            'shutout_bonus' => 15.0,
            'points_per_margin' => 3.0,
        ],

        /*
         * ~1.4 goals a game, so every rate is large.
         *
         * 🚨 The win bonus carries proportionally more here than anywhere else,
         * and that is right rather than an oversight: in a sport where a
         * one-goal win is the ordinary result, the RESULT is most of what
         * happened. A rate-heavy scheme would make a 3–2 defeat outscore a 1–0
         * win, which is not how anybody watches football.
         */
        'soccer' => [
            'points_per_point' => 12.0,
            'points_per_point_allowed' => -6.0,
            'win_bonus' => 15.0,
            'shutout_bonus' => 10.0,
            'points_per_margin' => 6.0,
        ],
    ];

    /**
     * 🚨 The competition key → sport map, kept here rather than reached for
     * from Picks. Fantasy needs one fact about a league — how much a point is
     * worth in it — and taking a dependency on another extension's registry for
     * that would break the day somebody runs a Fantasy without Picks' newest
     * version. An unknown competition falls back to gridiron, which is exactly
     * what every existing league already is.
     *
     * @var array<string, string>
     */
    private const SPORTS = [
        'cfb' => 'gridiron',
        'nfl' => 'gridiron',
        'nba' => 'hardwood',
        'cbb' => 'hardwood',
        'wnba' => 'hardwood',
        'mlb' => 'diamond',
        'nhl' => 'ice',
        'mls' => 'soccer',
        'epl' => 'soccer',
        'ucl' => 'soccer',
    ];

    public const DEFAULT = 'gridiron';

    /** Which vocabulary a competition is scored in. */
    public static function sportOf(?string $league): string
    {
        return self::SPORTS[(string) $league] ?? self::DEFAULT;
    }

    /**
     * The starting rules for a league in a competition.
     *
     * @return array<string, float>
     */
    public static function defaultsFor(?string $league): array
    {
        return self::RULES[self::sportOf($league)] ?? self::RULES[self::DEFAULT];
    }

    /**
     * Whether a shutout is a thing that can happen.
     *
     * 🚨 Used to hide the rule rather than to zero it. A commissioner who
     * deliberately sets a shutout bonus in a basketball league keeps it — this
     * only decides whether the field is worth putting in front of somebody who
     * has not.
     */
    public static function shutoutsHappen(?string $league): bool
    {
        return self::sportOf($league) !== 'hardwood';
    }
}
