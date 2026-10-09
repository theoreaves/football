# Seasons v1 — setup milestone

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
- League and team hub tabs; saved schedule, opening standings, and the current world roster for the selected year.
- Editable Human/CPU control for any number of participating teams.

## Scheduling

Circle-method opponent rounds are ordered to prefer division/conference matchups. Full opponent cycles precede repeated cycles, so no opponent occurs more than `ceil(games / (teams - 1))` times. Euler orientation balances home/away counts. An optional bye splits one round over two weeks, giving every team exactly one bye without double-booking any team.

This generic schedule is not the NFL opponent-selection formula. Division home-and-away series are not guaranteed for every season length. Setup previews show all opponents and home/away balance.

## Next milestones

1. Connect fixtures to the existing engine, watched games, Quick Sim, and week advancement. Apply team-control changes only to unstarted games.
2. Record results exactly once; update standings and tiebreakers.
3. Capture season rosters/depth charts; add 53-player rosters, 16-player practice squads, injured reserve, and weekly injury recovery.
4. Attribute player/team statistics, leader qualifications, player pages, and weekly recaps.
5. Qualify and seed playoff brackets, use playoff overtime, and archive completed seasons.

Existing exhibitions remain independent. Season fixture rows contain no simulated game state yet. Stats/injuries/playoff tabs explicitly indicate their pending integration.

## Validation

- `php artisan migrate`
- `php -d memory_limit=512M vendor/bin/pest --compact`
- `npm run test:graphics`
- `npm run build`

## Player appearance

The roster player editor includes a helmet-free portrait and an expandable appearance panel. The full-body preview rotates and zooms, wearing the team's home uniform. Visual choices cover head shape (round, oval, square, wide, long), hair (bald, buzz, short, curly, long), eyebrows, nose, mouth, and beard; colors cover skin, eyes, and hair/brows/beard. Height is in inches and weight in pounds. Height scales stature while weight changes torso and limb proportions more than head width.

Use appearance closes the panel with the draft values retained; Cancel restores the values from when the panel opened. Save player persists the entire form and closes the season roster/depth-chart popup, refreshing the underlying list. Validation errors keep the editor open. Appearance is captured when a new game is created and included in saved animation tracks, so later player edits do not alter existing game portraits or replays. Existing players and replays use the generic face with no hair until customized.
