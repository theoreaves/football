# Private NFL 2026 startup command

Create a NEW save owned by an existing account (exact account name or email):

```bash
php artisan football:seed-nfl "Theo Reaves"
```

If a name matches multiple accounts, use the email. The app has a `name` field,
not a separate username field. The command is CLI-only; the public startup
picker keeps its fictional template. Existing saves and current-save selection
are preserved. Select the new save from Saved games afterward, and use roster
year 2026 when configuring season play.

## Included

- All 32 real team identities, conferences/divisions, colors, and team logos.
- Current 2026 roster/depth responses from ESPN, checked for team and season.
- Source first/last names, ages, heights, weights, available jersey numbers.
- Head-coach names; source depth orders the roster ahead of unlisted reserves.
- Logos on team pages, both helmet sides, and midfield.
- Dallas and Miami default to white at home; editable in Team Settings.
- All 14 engine ratings, deterministic and editable. These are *estimates*
  derived from position, measurements, experience, and depth-chart rank.
  They are not scouting measurements, Madden ratings, or stat-calibrated NFL
  ratings. Rating algorithm: `nfl-depth-physical-v1`. Source IDs and provenance
  are kept in player appearance metadata and team settings.
- Generic existing player models; no player likeness photos are downloaded.

Team position groups are normalized for the engine (OT/C/G → OL, DE/DT → DL,
PK → K, etc.). Original positions and numbers remain in import metadata.
No fictitious players are added to fill gaps; incomplete teams cause an error.
Practice squads are excluded by default. The optional `--include-practice`
requires every included player's source age and measurements to be present.
Source injury/status information is retained as metadata; it does not create
season injury records automatically.

Missing/conflicting reserve numbers remain NULL to satisfy the team's unique
jersey constraint. Depth-listed players get priority over unlisted reserves;
the original number remains in metadata. The command reports how many numbers
were left unassigned. No source number is replaced with a fabricated one.

## Download once, preview, reuse

```bash
php artisan football:seed-nfl "Theo Reaves" --dry-run --snapshot="$HOME/Downloads/nfl-2026-rosters.json"
php artisan football:seed-nfl "Theo Reaves" --from="$HOME/Downloads/nfl-2026-rosters.json" --name="My NFL 2026"
```

`--snapshot` writes a new file and refuses to overwrite one. It captures source
rosters and depth, not logo bytes. `--from` reuses that capture instead of fetching
rosters; logos still download unless `--no-logos` is provided. Repeatable ratings
use source player IDs, not database IDs. `--dry-run` does not write a save or
fetch logos. Normal startup can take several minutes; progress is printed.

All roster responses are validated and all requested logos are downloaded before
the DB transaction begins. Failure rolls back the new save and removes any newly
written logo files. The previous CurrentWorld context is restored.

Source endpoints:
- https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams/{id}/roster?season=2026
- https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams/{id}/depthcharts?season=2026
- https://a.espncdn.com/i/teamlogos/nfl/500/{abbreviation}.png

Washington's 2026 home default remains burgundy. Its actual uniform schedule
includes one home white game and two black games; this importer uses the game's
existing single home-uniform preference, not per-game uniform scheduling:
https://www.commanders.com/news/commanders-announce-2026-jersey-schedule

No migration or frontend build is required. Tests use mocked responses and do
not request live data. Run:

```bash
php -d memory_limit=1G vendor/bin/pest --filter='NflStartupTest|ProLeagueTemplateTest'
```
