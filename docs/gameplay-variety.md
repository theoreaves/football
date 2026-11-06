# Gameplay Variety V1

The first pass covers inbounds run tackles, QB scramble tackles, completed-pass
tackles and ordinary incompletions. Each family has 100 compatible combinations:
five movement patterns,
five handling/reception poses and four finishing poses. This is a combinatorial
animation system, not 100 separately authored full animations per play.

Movement uses small lane/route adjustments, preserving the snap, handoff, catch
and recorded finish positions. Run, scramble and yards-after-catch ball paths
follow the carrier. Scrambles preserve the snap and pocket set before varying the escape
and upfield cuts, using carry poses rather than pass-catching poses. Existing
passing/rushing timing and TACK1 pursuit constraints run after variation geometry. Catch/miss, ball-protection, contact and recovery poses use
existing joints. No extra meshes, textures, random physics or assets are loaded.

Each game's state stores four small `animation_variety_counts` counters. A
seeded permutation visits every ID from 0 to 99 once per family before cycling.
Different families advance independently. This limits repetition during an actual
game rather than relying on random selections that can repeatedly pick one look.

Each eligible saved play stores `animation_variety`; its animation stores
`variety` (version, family, ID, movement, handling and finish). Rebuilding that play
uses the saved selection even if the current game's counters have advanced.
Existing saved animation paths are not regenerated. Older states without counters
start at their seed-derived first selection. Legacy replays without variety
metadata retain their existing joint poses.

The counters and selections are presentation metadata only. No simulation RNG is
consumed. Tests compare identical seeded plays with different visual counters and
require matching outcomes, gains, targets, clocks, scores, statistics and personnel.

## Scope and review

This pass deliberately excludes kneels, spikes, sacks, scoring plays, turnovers,
fakes, special teams and out-of-bounds finishes. Their dedicated behavior remains
intact. These families can be expanded after visual review of this first pass.

Watch newly generated plays after deployment. Check route/carry variation, catch
and miss poses, contact finishes and recovery. Replaying the same play should
repeat its chosen look. A combination may look more subtle on a very short gain
than on an open-field run; context and physical constraints take priority over
making every selection dramatic.
