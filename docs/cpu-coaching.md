# CPU coaching

Open **Edit Team → Coaching** to configure the team's existing on-field CPU
preferences. These tendencies influence CPU control and Ask Coach suggestions.
They are preferences, not guaranteed percentages or changes to player ratings.

## Presets

Choose a preset and click **Apply preset** to replace all nine tendencies.
Then adjust individual sliders if desired. The profile reads **Custom** when it
no longer matches a named preset. Selecting a name alone does not change sliders.

| Preset | Intended tendencies |
| --- | --- |
| Balanced | All nine sliders at 50; the existing neutral coaching behavior |
| Ground Game | More rushing, a featured back, shorter passes; balanced defense |
| Aerial Attack | More passing, deeper concepts, quicker tempo; balanced defense |
| Ball Control | More rushing, shorter passes, slower tempo, conservative fourth downs |
| Aggressive | Faster tempo, deeper passes, more QB keepers and fourth-down attempts, more pressure |
| Pressure Defense | More blitzing and man coverage; neutral offense |

**Restore saved tendencies** discards unsaved coaching changes and restores the
team's saved values. Staff names, appearance and other team settings are separate.
Choose Balanced and apply it to reset all tendencies to 50. Applying or restoring
does not submit the form: click **Save Changes** to persist the changes.

## Sliders

| Setting | Low → high |
| --- | --- |
| Offensive balance | Run-heavy → pass-heavy |
| Running back workload | Committee → workhorse; 50 targets 65/30/5 among three available backs |
| Designed QB runs | Rare → frequent; QB mobility still matters, and this does not force scrambles |
| Passing approach | Shorter → deeper passing concepts |
| Offensive tempo | Clock control → hurry-up |
| Fourth-down approach | Conservative → aggressive on suitable short fourth downs |
| Defensive emphasis | Defend the pass → stop the run |
| Blitz frequency | Rare → frequent |
| Coverage preference | Zone → man |

Down, distance, field position, personnel, score and clock still govern decisions.
A preference for aggressive passing does not override kneeling out a win or
conservative inside runs while protecting a late lead. Desperation situations,
kicking range and end-of-half needs still take priority. Workload preferences
respect unavailable backs, specialist assignments and manual personnel choices.

## Games and seasons

New exhibitions capture each team's saved coaching values. A season fixture
captures the current values when that game starts; later edits do not alter games
already underway or historical replays. New games from older teams with no
coaching settings use 50 for each missing tendency. Quick Sim uses the game's
saved coaching profile too.

Presets are a convenient editor for the existing preference values. They do not
add new randomness, change the neutral CPU algorithm, or regenerate animations.

## Verification and next scope

Automated checks cover preset application/customization/restoration, validation
redisplay, complete saved profiles, exhibition and season snapshots, directional
play-call distributions, legal formations and late-game victory decisions. Use the
deployed Coaching tab to review preset application, customization and restoration.

Future Franchise GM controls should configure roster-building behavior separately
from these on-field coaching preferences. GM behavior is not implemented here.
