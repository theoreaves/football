# Expanded offensive calling

The formation → play → motion workflow remains. Bunch, Empty and Goal Line join
the existing Singleback, I-Formation, Shotgun, Pistol, Spread and Trips layouts.
Formation-specific personnel packages and planned rotation are now available;
see [personnel-rotation.md](personnel-rotation.md). Special-teams and conversion menus
remain separate. Legacy play calls remain supported.

New choices: inside zone, outside zone, stretch, toss, counter, power, lead,
play-action, mesh, flood, quick pass, QB sneak, QB draw, QB power, QB sweep.
The formation menu is defined once in OffensiveOptions and supplied to the
browser; incompatible new choices are rejected before the game is written.

Run concepts use existing outcome categories with concept adjustments. Zone
uses awareness/edge blocking, counter varies the front matchup and initial cut,
power uses guard blocking and a pull track, lead uses the backfield blocker,
stretch takes a wider lane, and toss has an airborne exchange. Passing concepts
vary target weights and routes: play-action has a fake and a defensive-read
matchup, mesh uses crossing routes against man, flood layers routes against zone.
These are compact concepts, not a fully authored eleven-player route playbook.

Designed QB runs use the active QB's running and ball-security ratings. They are
rushing attempts, not scrambles, sacks or passes; their animations retain QB ball
possession and use the existing acceleration/distance-based rushing time map.
Sneaks are naturally limited to short gains; developer-forced big plays can
still override that limit. QB power includes a pull; sweep follows an edge lane.

CPU coaching and Ask Coach select deterministic, formation-compatible concepts.
Team Settings → Coaching adds Designed QB runs. New game snapshots capture the
setting; existing games retain their snapshotted preferences, with a neutral
fallback if the new setting is absent. Athletic quarterbacks receive more
non-sneak opportunities; short-yardage sneaks can also be called for slower QBs.
Clock-management priorities such as kicking, kneeling and late-game strategy
remain ahead of ordinary concept selection.

No migration or rewrite of saved games. Old saved animation paths continue to
play as recorded. New CPU choices intentionally change future strategy; the same
new game state/seed and choices remain reproducible. Game-clock rules, penalty
handling and statistical categories remain shared with the existing engine.

Defense expansion is a separate next step.
