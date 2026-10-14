# Season Team Plays

Open Seasons → Team → Team Plays to create, edit, or remove named variations.
Choose offense or defense, a formation, and an existing base play. Defense entries
also capture run/pass expectation and blitz choice. Assign optional personnel to
formation roles using any player in that team's roster for the season's year.
The editor searches names, numbers, and positions and uses stacked rows on phones.
A player can be assigned to only one role. Names are unique within a unit/formation.
Each team can save up to forty variations per season. No migration is required.

New season games capture the playbook alongside their roster. Started games retain
that captured playbook, including definitions later edited or deleted. Other teams,
seasons, exhibitions, and permanent roster depths are unchanged.

In a human play-calling wizard, select the formation and find Team Plays beneath
standard offensive plays or defensive coverage choices. A defensive variant skips
the separate type step because expectation and blitz were saved with it. Choosing
standard calls, changing formation/type, or using Coach Pick clears the named play.
CPU and Ask Coach use standard plays; automatic custom-play selection is not added.
Initial scope is ordinary scrimmage plays, including kneel/spike. Kick units, fakes,
and conversion plays retain existing selection and cannot use these variations.

Named assignments take priority over persistent game lineup choices, situational
specialists, and rotation. Review-screen one-play replacements take priority over
named assignments. Automatic inherits the named assignment when one is selected.
An explicit replacement can move a named player out of another role; that old role
falls back to normal selection. Injured, tired (65% fatigue), missing, or inactive
formation assignments fall back to Automatic with a notice in review/results.

One-play validation checks the final selected combination against the locked game
state. Forged IDs, other teams' plays, mismatched calls/formations/defense profiles,
duplicate explicit assignments, and CPU-controlled edits are rejected. A named
package applies to one called play and uses actual captured attributes, identity,
appearance, and stats. Existing substitution animation handles changed players.

Saved results and both penalty choices include the chosen variation names. Names
also appear in play summaries/logs. Actual participants remain in animation tracks
and the saved before-state; next-play states retain no transient assignment.
Standard calls without a selected variation retain their deterministic behavior.

Validation:
- `php -d memory_limit=1G vendor/bin/pest --filter='TeamPlaysTest|OnePlayPersonnelTest|GamePersonnelTest|PersonnelRotationTest|CpuCoachTest|ExhibitionPresentationTest|SeasonGamesTest|SpecialTeamsTricksTest|OffensiveOptionsTest|TeamSettingsTest'`
- `npm run test:graphics`
- `npm run build`
