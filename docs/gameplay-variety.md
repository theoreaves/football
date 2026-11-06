# Gameplay Variety V1

Status: working, not fully visually tested. Continued gameplay observation is
required before visual acceptance.

Each supported family has 100 compatible combinations, selected without repeats
until all 100 have been used. These are combinations of animated components,
not 100 separately authored complete clips per outcome.

## Supported families

| Family | Components |
| --- | --- |
| Inbounds run tackles, QB scramble tackles, completed-pass tackles, ordinary incompletions | Five lane/route patterns, five handling/reception poses, four finishes |
| Sacks, touchdowns, safeties, interceptions, fumble recoveries, defensive returns, sideline finishes | Five articulated movement rhythms, five free-arm poses, four contact/recovery finishes |
| Punt returns, return touchdowns, touchbacks, fair catches and blocks | Five body rhythms, five arm poses, four follow-through/contact/recovery finishes |
| Kickoff returns, return touchdowns and touchbacks | Same component counts, with independent sequences for each recorded outcome |
| Field goals and extra points: good, missed or blocked | Five body rhythms, five arm poses, four post-contact kick follow-throughs |
| Onside kicks: recovered or failed | Kick follow-through and recovery/carry poses; independent sequences for each result |

The original run/pass families use small lateral path adjustments, preserving
snap, handoff, catch and recorded finish positions. Ball paths follow the carrier.
Scrambles preserve the pocket set before varying escape cuts. Existing timing and
TACK1 pursuit constraints run after variation geometry.

The expansion families use joint poses only (`pose_only` metadata). Their paths,
ball trajectories, possession events, contact styles/times and durations remain
unchanged. Turnover poses begin after recorded defensive possession, so INT2
coverage reactions and fumble recovery timing remain intact. Kicking variation
begins after foot-to-ball contact. Returners use the receiving team's actual holder
role; a touchback has no returner variation. Free-arm motion preserves existing
ball-carry joints. Finishing poses fade during post-play recovery. No additional
meshes, textures, physics or assets are loaded.

## Persistence and compatibility

Each game's state stores a small `animation_variety_counts` counter per family.
A seeded permutation visits IDs 0–99 once before cycling. Every special-teams
outcome has a separate family, so good kicks, blocks, touchbacks and returns do
not consume each other's sequence.

Each eligible saved play stores `animation_variety`; its animation stores
`variety` (version, family, ID, movement, handling and finish). Rebuilding a saved
play uses its saved selection even after the counters advance. Existing saved
animation paths are not regenerated. Older states without counters start at their
seed-derived first selection. Legacy replays without metadata keep their poses.

These are presentation choices and consume no simulation RNG. Tests compare
seeded plays with different counters and require identical football state,
statistics, clocks and personnel. Expansion tests additionally require identical
animation geometry and events, and 100 distinct rendered joint sequences per
supported family/scenario.

Kneels, spikes, throwaways, fake kicks and no-snap clock events retain their
existing dedicated presentation.

## Visual review

Watch newly generated plays after deployment, including sacks, scoring runs and
catches, interceptions/returns, fumble recoveries, sideline exits, kicks and
returns. Check ball attachment, reaction timing, kick contact, falls and recovery.
Replaying a play should repeat its chosen look. Subtle differences and physical
constraints take priority over making every combination dramatic.

CPU coaching controls and presets are documented in `cpu-coaching.md`. GM
controls for roster-management behavior belong to future Franchise development,
separately from on-field coaching decisions.
