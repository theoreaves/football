# Helmet designs and NFL defaults

Team Settings → Uniforms adds independent home/away Standard, Cat stripes, and
Ram horns choices. Patterns use the existing helmet stripe color. Patterned
helmets replace the center stripe; side decals remain independent. Mirror Left
and Mirror Right change decal UVs without modifying the shared logo texture.
Optional decal color uses the original alpha silhouette; leave blank for artwork
colors. Player previews, active players, and instanced sidelines share the design.

New NFL saves apply these defaults automatically. For an existing NFL save:

```bash
php artisan football:apply-nfl-helmets SAVE_ID
```

Use the ID printed by the NFL startup command. To list saved-game IDs:

```bash
php artisan tinker --execute='dump(App\Models\World::get(["id", "name"])->toArray());'
```

The command only targets ESPN-imported NFL teams in that save. It preserves
custom uploads/profiles by default. `--replace-custom` explicitly replaces those
helmet settings too. Rosters, ratings, simulation outcomes, and games are not
changed. New games snapshot the updated uniform profile; preview in Team Settings
first. Old game snapshots retain their existing pattern/mirroring settings.

Defaults covered:
- Chiefs: paired arrow decals with readable KC letters. Arrow outline is an
  approximation; letter paths derive from the source vector.
- Ravens: paired head decals; opposite-side adaptation keeps B readable.
- Falcons/Bills/Broncos/Lions/Dolphins: forward-facing opposite-side mirror.
- Steelers: right decal only. Browns: no decal.
- Giants/Jets: white decal silhouette.
- Bengals: orange helmet, black cat stripes, no side emblem.
- Rams: blue helmet, gold ram horns, no primary LA side emblem.
- Eagles: paired wing silhouettes, rather than the primary bird-head logo.
  Wings are an approximation, not a downloaded official helmet decal.

These are starter helmet adaptations, not an exact 2026 equipment asset pack.
Independent left/right uploads can replace them. Team-page and midfield logos
stay independent. Artwork sources and adaptation details are in
resources/nfl-helmet-decals/CREDITS.txt.

References:
https://www.bengals.com/uniforms/current-era
https://www.therams.com/news/rams-unveil-uniform-brand-refresh-ahead-of-2026-season
https://shop.steelers.com/helmet-speed-authentic-flat-black
https://static.www.nfl.com/image/upload/league/f60ftapcsbynqgymw72q.pdf

No migration. Clear compiled views and rebuild frontend assets after applying.
