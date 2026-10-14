# One-play personnel

In a human-controlled game, finish the play-calling wizard and open Personnel on
its review screen. It lists the actual eleven players in the selected formation,
including depth-chart overrides, situational backs, rotation, and injury/fatigue
substitutions. Search by name, number, or roster position to find replacements.
Desktop uses two columns; narrow screens use one column and full-width selects.

Choose Automatic to keep existing lineup selection. A replacement can come from
any position in the game's captured roster. Its original attributes, number,
appearance, and player identity remain intact. Moving a starter to another role
also frees their old role for normal selection. A player cannot be explicitly
assigned to two roles in the same unit. CPU teams cannot be edited.

Assignments take priority over in-game persistent lineup preferences, situational
specialists, and planned rotation. They belong to one called play and expire even
when the attempt ends without a snap. Persistent lineup choices are untouched.
Changing the selected play or formation clears one-play choices. Injured players
and players at the automatic rest threshold (65% fatigue) cannot be selected.

This initial version supports ordinary scrimmage plays, including kneel/spike.
Kick units, fakes, and conversion plays retain their existing personnel selection.
Existing saved games need no migration; older rosters without captured pools keep
existing play calling. Team-specific named play variations are a separate feature.

The POST personnel endpoint performs read-only selection, not simulation. It does
not advance the game clock, seed, version, statistics, or saved roster. Submission
is disabled while the latest preview loads, and failed requests offer retry/reset.
Final play submission validates the current locked game state, ownership/control,
formation roles, roster membership, availability, and distinct explicit choices.

Transient `_snap_lineup` data is retained in the saved play's before-state and
actual animation tracks. It is removed from the next-play state and every penalty
option. Replay and box-score identity therefore continue to use the saved snap.
Unmodified calls use the same selection and simulation paths as before.

Validation:
- `php -d memory_limit=1G vendor/bin/pest --filter='OnePlayPersonnelTest|GamePersonnelTest|PersonnelRotationTest|CpuCoachTest|ExhibitionPresentationTest|SeasonGamesTest|SpecialTeamsTricksTest|OffensiveOptionsTest'`
- `npm run test:graphics`
- `npm run build`
