# Planned rotation and formation personnel

Default RB workload targets 65/30/5 across the top three available running backs
in a repeating 20-RB-snap schedule, with stretches of up to four lead-back snaps
and two backup snaps. The first four slots start with the lead back. The third
back receives one slot. With two healthy backs the third-back allocation moves
to the second back. These are opportunities to be on the field, not forced
carries or catches; play calls and game situations still determine touches.

Team settings > Coaching > Running back workload adjusts the normal schedule:
0 = 50/45/5 committee, 50 = 65/30/5 balanced, 100 = 80/15/5 workhorse. The setting
is captured with team coaching preferences when a new game starts. Games missing
the setting use balanced rotation. Injuries and existing fatigue substitutions
remain safeguards. An explicit game RB selection takes priority, as does an
eligible optional third-down/short-yardage back on its assigned downs.

Counters advance only on actual offensive scrimmage snaps. Penalties with no
snap, timeouts, special-team kicks and conversion simulations do not consume
normal rotation slots. An Empty play without an RB advances offensive usage but
does not consume an RB slot. Helpers do not mutate saved roster snapshots or
read live team settings. Legacy history without recorded animation continues
using the original lineup selection, without retroactive planned rotation.

## Packages

| Formation | Skill personnel besides QB |
| --- | --- |
| Singleback | RB, two WRs, two TEs |
| I formation | RB, FB/second back, two WRs, TE |
| Goal line | RB, FB/second back, WR, two TEs |
| Empty | Four WRs, TE |
| Shotgun, Pistol, Spread, Trips, Bunch | RB, three WRs, TE |

The FB slot prefers a rostered fullback, then another RB. TE2, FB and WR4 are
supplemental optional depth slots. Missing or unavailable supplemental personnel
fall back to the standard eleven-player unit. Offensive line roles remain five.
Pass targets are restricted to eligible players actually in the selected package.
TE2, FB and WR4 use their real identity, ratings, dimensions, uniform and artwork.
Their statistics follow the identity recorded in the saved animation, including
when an older game's original roster snapshot lacks the supplemental role key.

WR1 receives a two-snap rest window per 20 offensive snaps, WR2 per 12, and the
primary TE per 10. These windows only apply when an available fresh reserve can
replace the player without reusing another active player's identity. A manual
selection suppresses planned rest for that role. A package can put two TEs on
simultaneously; TE2 share is not simply TE1's remaining percentage. Starting CBs
and safeties have no new planned rest schedule; defensive formation changes and
existing injury/fatigue selection still apply.

Existing individual/unit exchange animations and Skip show actual identity
changes. Formation diagrams and motion choices now name the package players.
Presentation time remains independent of the game clock. Repeating the same
state, roster and call gives the same lineup and outcome; saved replays retain
their recorded tracks. New lineup choices naturally change future outcomes.

## Research baseline

Own analysis of 2025 regular-season PFR snap counts distributed by nflverse:
median most-used RB = 63% of offensive snaps; second RB = 30%. WR rank medians
were 87/75/52%, TE 76/43%, CB 100/94/56%, and safety 100/97%. Rank is per-team,
per-game usage, not an official depth chart. Injuries and game situations are
included. HB was grouped with RB; generic DB rows were mapped using the seasonal
roster where possible, otherwise excluded. Snap shares do not measure the number
of substitutions and multiple players at one position can play together.

These observations motivate the model; they are not enforced quotas. Workload,
formation frequency, health, specialist choices and coaching change actual shares.
This first implementation does not add a separate WR/TE workload slider or a
full personnel-package editor.

Sources:
- https://github.com/nflverse/nflverse-data/releases/tag/snap_counts
- https://nflreadr.nflverse.com/reference/load_snap_counts.html
- https://nflreadr.nflverse.com/articles/dictionary_snap_counts.html
