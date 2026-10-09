# Seasons v1 — regular-season game integration

Branch: `feature/seasons-v1`.

## Available

- World → Seasons → Start new season.
- 4, 8, 12, 16, 24, 28, or 32 teams selected from the current world.
- Flat, two-conference, or two-conference/division layouts; renamed groups and explicit team assignments.
- 28-team division preset: 5/5/4 teams per conference. 32-team preset: four divisions of four per conference.
- Four-team seasons: 3 or 6 games. Other sizes: 7, 10, 11, 14, 16, or 17 games.
- Optional one bye per team; home/away counts balanced within one.
- Preview before saving. League/year uniqueness prevents duplicate schedules; an existing unconfigured starting season is reused.
- Saved playoff selection, with incompatible formats rejected. No bracket progression yet.
- League and team hub tabs; saved schedule, live standings, and the current world roster for the selected year.
- Editable Human/CPU control for any number of participating teams.

## Scheduling

Circle-method opponent rounds are ordered to prefer division/conference matchups. Full opponent cycles precede repeated cycles, so no opponent occurs more than `ceil(games / (teams - 1))` times. Euler orientation balances home/away counts. An optional bye splits one round over two weeks, giving every team exactly one bye without double-booking any team.

This generic schedule is not the NFL opponent-selection formula. Division home-and-away series are not guaranteed for every season length. Setup previews show all opponents and home/away balance.

## Season games

Current-week fixtures offer Play game or Quick Sim. Starting a fixture creates one engine game; repeated submissions reopen the same game. In-progress games show Resume, and final games link to the box score, play log, and saved replays. Season games are excluded from the exhibition list and cannot be deleted through its delete action. The game scoreboard links back to the season.

Each game captures the season roster year, season depth order, team controls, and quarter length. Later edits apply only to unstarted games. Depth overrides change the captured pool without writing permanent roster depths, and fatigue/injury substitutions use that pool. Quick Sim uses CPU decisions while retaining the configured owners on its final summary. Season games currently use modern regular-season overtime, penalties and injuries enabled, and 80% attendance with 10% visiting fans. The visitor calls heads on the opening toss; a human toss winner chooses kick/receive.

Final scores are written alongside the final engine update, once per fixture. Provisional penalties do not count as final. Standings derive W/L/T, winning percentage (ties count as half a win), and points for/against from final fixtures, with basic percentage/point-differential/points-scored ordering. Full playoff tiebreakers are pending.

Advance to next week is available when every current-week fixture is final. A versioned week submission prevents a repeated click from skipping a week. Finishing the last week marks a no-playoff season completed, or a playoff season playoffs_pending until brackets are implemented. Existing seasons can use these features immediately after migration.

## Next milestones

1. Cross-week injuries/recovery, practice squads, injured reserve, and season roster management.
2. Attribute season player/team statistics, leader qualifications, player pages, and weekly recaps.
3. Complete playoff tiebreakers, qualify and seed brackets, use playoff overtime, and archive completed seasons.

Existing exhibitions remain independent. Injuries and fatigue currently belong to each game; no multi-week injury carryover yet. Stats/injuries/playoff tabs indicate their pending integration.

## Validation

- `php artisan migrate`
- `php -d memory_limit=512M vendor/bin/pest --compact`
- `npm run test:graphics`
- `npm run build`

## Player appearance

The roster player editor includes a helmet-free portrait and an expandable appearance panel. The full-body preview rotates and zooms, wearing the team's home uniform. Visual choices cover head shape (round, oval, square, wide, long), hair (bald, buzz, short, curly, long), eyebrows, nose, mouth, and beard; colors cover skin, eyes, and hair/brows/beard. Height is in inches and weight in pounds. Height scales stature while weight changes torso and limb proportions more than head width.

Use appearance closes the panel with the draft values retained; Cancel restores the values from when the panel opened. Save player persists the entire form and closes the season roster/depth-chart popup, refreshing the underlying list. Validation errors keep the editor open. Appearance is captured when a new game is created and included in saved animation tracks, so later player edits do not alter existing game portraits or replays. Existing players and replays use the generic face with no hair until customized.

Quarter length can be selected at season creation and changed in league Settings: 3, 5, 10, or 15 minutes. It is stored in seconds as `settings.quarter_length`, defaulting to 900 for existing seasons. Fixture game creation captures this value; changing it does not rewrite games already started.
