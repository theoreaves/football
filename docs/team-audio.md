# Team stadium audio

Team Settings → Audio supports per-event MP3, WAV, and OGG uploads up to 15 MB.
The eight events are home entrance, anthem, home touchdown, home defensive turnover,
visiting offense third down, drive break, quarter break, and halftime.
Upload previews remain local until Save. Reset uses the bundled clip. Game Master
and Stadium sliders apply to custom and bundled sounds alike.

The host team's profile controls stadium audio. Existing event timing and skip
controls are retained. Unavailable custom recordings fall back to bundled files.
New exhibition and season games snapshot the selected URLs with team appearance.
Replacing or resetting a clip keeps the old immutable file for saved games/replays.
Audio endpoints use the existing Team world scope and private team_art disk;
files from other saves are not served. No migration is necessary.

Upload limits also depend on PHP upload_max_filesize and post_max_size. Several
uploads in a single save must fit the server's overall request limit.
