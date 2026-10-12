# NFL presentation defaults (2026)

`football:seed-nfl` now names its league **National Football League**, sets each
team's stadium name, and supplies one mascot profile per team. Profiles use the
existing generic 3D characters, not exact replicas of the NFL costumes. Raider
Rusher uses the knight model as a helmeted-human approximation. Cleveland uses
Chomps; New Orleans uses Gumbo; Seattle uses Blitz; Baltimore uses Poe.

The Chargers, Giants, Jets and Packers have no official costumed mascot in this
mapping. Their profiles have `enabled: false`; Team Settings → Mascot can enable
or customize one. Existing game snapshots keep the presentation they captured.
New games use the updated team profiles.

## Existing saves

Run `php artisan football:apply-nfl-presentation WORLD_ID`. It targets only teams
marked as ESPN NFL imports in that save. Missing stadium names and mascot fields
are filled; custom values remain. Use `--replace-custom` to explicitly restore
the supplied stadium and mascot profiles. Stadium geometry/colors, coaches,
uniforms, rosters, ratings, results and statistics remain untouched.

The original `NFL` league label becomes `National Football League`. Generic
conference/division labels in configured seasons are repaired **only when the
existing memberships already match the NFL conference or division**. The command
never regroups teams or rebuilds schedules, including with `--replace-custom`.
Mixed groups need a new season configured with the correct memberships.

For a save containing exactly the 32 imported NFL teams, season setup defaults
to AFC/NFC, East/North/South/West, correct division assignments, the name NFL 2026,
and the current 14-team playoff format. Other saves retain generic defaults.
Changing to a smaller league continues to use its standard presets. These are
presentation/setup defaults, not the official real-world NFL fixture list.

No migration or new roster download is required. The command is repeatable and
restores the current-world context after success or failure.

## Sources and review

Reviewed October 12, 2026. Data is intentionally fixed in
`config/nfl-presentation.php` so future sponsorship changes do not silently alter
existing saves. Body colors are visual approximations; shirts use team primary
colors. No new mascot assets are downloaded.

- Stadium and mascot overview: https://en.wikipedia.org/wiki/List_of_current_NFL_stadiums
  and https://en.wikipedia.org/wiki/List_of_NFL_mascots
- Buffalo's 2026 stadium: https://www.buffalobills.com/stadium/
- Houston's current **Reliant Stadium** name (changed back from NRG in August
  2026): https://www.houstontexans.com/stadium/
- Kansas City's 2026 field name: https://www.chiefs.com/news/2026-regular-season-schedule-finalized-chiefs-to-play-six-primetime-contests
- Cincinnati stadium: https://www.bengals.com/stadium/
- Washington mascot: https://www.commanders.com/major-tuddy/
- Cleveland mascots: https://www.clevelandbrowns.com/fans/mascots
- Baltimore's 2026 Poe appearance: https://www.baltimoreravens.com/news/ravens-the-uprising-pregame-show-poe-flight-zipline-game-entertainment-band-cheerleaders-2026
- Raiders mascot appearances: https://vegas.raiders.com/events/festival-of-football
