# Arcade full-team checkpoint (Stage B)

Stage B is an opt-in exhibition implementation for local review. Stage C performance,
pacing, difficulty and long-session acceptance are **not** complete. Coach and season
engines are unchanged. Nothing here enables seasons, playoffs or defensive Arcade controls.

## Local review

Use the feature branch, PHP dependencies, Node 22 and a separate local database.
Set `ARCADE_ENABLED=true`, run the existing migrations, and run `npm run build`.
Sign in to an owned football save with complete real rosters, then create an exhibition
in Play mode. New Play exhibitions retain 900-second quarters; Coach defaults and
existing games retain their saved settings. A lineup without 22 distinct available real
players is rejected with a Coach fallback rather than filled with fictitious players.

On your offensive possession:

1. Choose **Inside run**, **Outside run**, **Short pass**, **Medium pass** or **Deep pass**.
2. **Reserve play** pins the saved state, lineup, fatigue-adjusted ratings and selected call.
3. **Start play**. WASD/arrows move; Q/E juke; Escape pauses; Space resumes.
4. Runs physically hand off QB to RB before control transfers. Passes begin under QB
   control: click the field to throw, or hold and drag to preview aim. After a catch,
   control transfers to that receiver. Every passing call includes a short outlet.
5. Replay if desired, then **Submit / retry same result**. Only an accepted result
   advances the official game. Do not replay a snap to retry a failed network request.
6. Cancel an unfinished reservation to change calls. A local 20-second recording limit
   is not a football result: cancel and reserve again.

Coach Defense, Watch and Fast Sim remain the existing authoritative systems. Required
special-team/coin/penalty decisions still appear. **Continue in Coach** remains available
for manual decisions such as a fourth-down punt. The automatic ordinary offensive
Coach picker is suppressed only during Play's human Arcade offense.

The defense choice is a saved preference for opponent possessions, not permission to
skip your kickoff, try or penalty decision. Choose the required football decision
first. On an opponent possession, use **Watch next play** or **Fast Sim to next decision**
to begin. Both use the same broadcast stadium; they do not open a different game screen.
Manual **Continue in Coach** keeps Punt/Field Goal choices available under every defense
preference, while the ordinary offensive picker never opens automatically over Arcade.

## Runtime and existing ratings

`full-team.js` is separate from the legacy running/passing drill runtimes and Coach.
The server selects a shotgun offense (QB, RB, three WRs, TE, five linemen) and base 4–3
(4 DL, 3 LB, 2 CB, 2 S), with real saved identities and uniforms. The selected call is
retained in the official saved play.

- Speed and acceleration reuse the accepted movement functions and **0.925** scale.
- Blocking/strength oppose strength/awareness for contact hold and shedding.
- Defensive roles use delayed observations: DL rush, LB short zones, CB man leverage,
  safety deep support, then bounded geometric pursuit. They do not read a hidden pass
  target or rerun candidate futures.
- Coverage affects leverage/tracking and contested catches; awareness affects reads
  and pursuit prediction. Tackling opposes carrier strength and approach/juke geometry.
- Throwing affects accuracy, including pressure/movement; catching affects real
  ball-contact attempts. Breakups/interceptions require reachable ball contact.
- Existing authoritative personnel selection already adjusts ratings for fatigue.
  The runtime does not apply a second stamina/speed penalty. All 22 participate in the
  existing finalizer exactly once, including substitutions and injuries where enabled.

Inside and outside calls use distinct mesh/blocking assignments. All three passing
calls have receiver routes plus 3–6-yard outlets. Catches allow running after the catch.
Interceptions end at the actual catch; returns, fumbles, laterals and QB Sneak are later work.

## Versioned result and replay contract

New full-team reservations explicitly request `live-recording-2`; omitted versions
still reserve v1. Active v1 reservations and historical v1 drill/exhibition recordings
are not upgraded. Reservation retry identity includes contract, kind, tempo and call.

The v2 binding pins the catalog/tuning version, seed, lineup, eligible receiver keys,
formation/setup hash, game version, session/result IDs, expiration and payload limits.
Server validation checks all identities, initial positions, ordered sampled/fractional
contact frames, bounded movement, handoff, throwing/catching/block/tackle evidence,
ball possession and terminal boundaries. It derives the actual outcome for the existing
accounting/finalization boundary, **not a second Coach-simulated outcome**.

Recording bounds: 22 actors, 20 seconds, 60 Hz simulation / 30 Hz ordinary samples,
858 frames including event boundaries, 256 events, 2 MiB recording, 250,000 JSON nodes.
The existing 4 MiB request cap remains. v2 local retry storage includes envelope overhead
within that cap; v1 retains its former limit. Input bounds are correctness checks, not
competitive anti-cheat guarantees.

All 22 actors, poses, ball motion and events play back in the existing persistent
stadium/renderer/canvas. Returning restores the live view without committing again.

## Checks and reproducible exploratory observations

```sh
php -d memory_limit=1G vendor/bin/pest --compact
npm run test:graphics
npm run build
# Optional single-snap matrix, not Stage C game balance acceptance:
FULLTEAM_BENCHMARK_REPORT=1 php artisan test --compact tests/Unit/FullTeamBenchmarkTest.php
```

An optional `FULLTEAM_SAVED_ROSTER_FIXTURE` points to an explicitly approved read-only
roster/appearance export. Never point tests at a person's live application database.
`tests/Fixtures/fullteam-benchmark-observation.json` contains sanitized aggregate results
and source hashes for 150 controlled synthetic-rating and 30 saved-roster snapshots.
It contains no raw saved roster or saved-game identity.

The scripted completed passes remain unusually explosive, especially the saved-roster
deep route. These are signals for hands-on review, not evidence to change speed, clocks
or broad difficulty. The main scripted matrix produces no interceptions/sacks; separate
contact/interception and held-pocket tests cover those paths. Browser visual checks are
reported separately on issue #4; headless timings are not browser FPS guarantees.

## Focused Stage B review correction

The pursuit calculation now projects a delayed observation through its sample age
using only the already-observed velocity before computing the bounded interception.
Previously, treating that old position as current could make a faster defender brake
behind the carrier. Reaction delays, ratings, acceleration, prediction limits and the
0.925 scale are unchanged. Saved plays remain recorded history and are not resimulated.
This corrects a specific movement defect; it is not Stage C balance acceptance.

`node tests/Fixtures/fullteam-observed-run-comparison.mjs` compares the original
`790a27a` runtime with the current runtime using anonymous ratings/geometry and movement
inferred from one recorded run. Its original 77-yard touchdown is unchanged. The
approximation produces a 77-yard baseline touchdown versus a current 53.26-yard safety
tackle; it is not an exact keypress replay or a target for future balancing.

The PHP command above invokes the test runner directly so its process receives the
1 GiB limit. Passing that flag only to the Artisan wrapper can leave its child at the
machine's default 128 MiB and abort the full exhibition-history tests.

## Submit memory regression

Arcade canonical fingerprints are now hashed incrementally rather than allocating a
second recursively sorted copy of every saved recording. Canonical JSON bytes, hash
values, session bindings, expiry checks and exactly-once behavior are unchanged. Flow
status also releases its full-history temporary after extracting the latest notice.

A disposable copy of a 13-play review history reproduced an HTTP 500 under a 128 MiB
PHP worker with the old implementation. The same HTTP request succeeded after the
fix at 107.4 MiB peak, and its identical retry kept one accepted result at 63.3 MiB.
The request recording was generated from the reserved setup, not recovered from the
user browser; the live game was never submitted or modified by the reproduction.
A database-independent synthetic 22-player history test guards against reintroducing
the recursive allocation, alongside canonical hash and encoding/depth parity tests.
This is not a claim that all possible full-game histories fit a 128 MiB worker.

A local recording timeout now consistently says that no football result was saved
and directs the player to cancel the reservation instead of offering submission.
This wording correction is separate from the HTTP 500. A reservation that expires
while diagnosing a failed submission must still be cancelled; do not extend it or
manufacture an accepted result. The saved game remains at the prior accepted play.


## Extended-history memory reliability

The extended disposable-game check found two further allocation problems after the
initial fingerprint correction: Fast Sim retained full decoded before/after histories
while calling Coach, and a later result save decoded both old and new histories for
Eloquent dirty checking. These were request failures, not lost client clicks. Failed
transactions left the prior state and result receipts unchanged.

`ExhibitionHistory` now reads the existing JSON one play at a time and appends or
replaces only the required raw segment. Normal model events, timestamps, ownership,
transactions and existing storage format remain in place. Session/flow fingerprints
retain the exact canonical hashes, and result retries reuse the original receipt.
Presentation, historical replay and box-score paths consume bounded history reads;
the screen keeps only compact log/highlight/break metadata plus the selected play.
No recordings are truncated, reconstructed or resimulated. No schema migration,
clock/speed/difficulty adjustment or PHP worker-limit increase is part of this fix.

The implementation bounds decoded history, not all memory: raw JSON, the current
play/receipt and the response still occupy memory. Legacy object-root histories keep
their prior compatibility behavior. Synthetic storage-growth tests are memory
regressions, not gameplay-balance benchmarks or a guarantee for unlimited histories.


The longer-game run also exposed a separate full-team validation edge: a broken
tackle and final tackle can be recorded at distinct fractional times in the same
simulation tick. Terminal ownership validation now identifies the actual last
frame by index instead of treating every frame in that tick as terminal. Legal
recordings are accepted; forged final holders and premature dead-ball states remain
rejected. This does not alter movement, contact resolution or official accounting.


Use `APP_DEBUG=false` for long-game memory verification and local gameplay review.
Laravel's debug exception renderer retains prepared SQL bindings for its query
trace, including successive multi-megabyte history updates during Fast Sim. This
instrumentation can exceed a 128 MiB worker even when the application streams the
history correctly. It is independent of Laravel's optional database query log.
The diagnostic comparison changes debug collection only in the disposable test
process; do not change someone's local app settings or live game as part of a test.
No vendor/debug-listener patch or transaction-boundary change is included.


The extended regression also exposed a pre-existing randomized season test: it
left the game in opening-kickoff phase at Q4 0:00 but assumed the final snap could
never score. A legal return touchdown made the saved result 14–6, not 14–0.
The test now uses explicit deterministic non-scoring and scoring final snaps,
checks each exact score against the saved season fixture, and retains the pending
penalty guard. Both cases also pass with the previous `4d3971d` engine. No Coach,
clock, kickoff, season-finalization or RNG rule was changed to satisfy the test.


Final regression coverage for this follow-up:

- Unit suite: 181 passed, 2 opt-in benchmark skips.
- Feature suite: 660 passed, 3 opt-in pacing skips across an interrupted run and
  its verified remaining tail. The interrupted log contained 641 passes and
  3 skips; the remaining 19 cases passed in complete-file reruns, with 6 boundary
  cases and both deterministic season-finalization cases also repeated.
- All 148 Coach characterization cases are included and pass.
- Graphics: 529 passed. Production build and Pint pass; the existing large-chunk
  build warning remains.

Earlier full PHP runs exposed the obsolete whole-history-cast assertion and the
randomized final-kickoff fixture described above. Both are corrected and covered
by the final checks; do not describe the interrupted Feature process itself as a
completed aggregate run. No fresh Chrome interaction is claimed for this backend
follow-up. Live browser/performance and hands-on balance remain separate checks.


The disposable continuation from the preserved 21-play checkpoint reached the
final whistle normally with 900-second quarters, 184 saved history entries and
17,236,533 bytes of raw history. After that checkpoint it added 42 accepted Arcade
results and 53 flow-command receipts, with 163 new history entries. Fresh PHP
HTTP-kernel requests used `APP_DEBUG=false` and the unchanged 128 MiB worker cap;
the observed maximum was 110.95 MiB. Final current-view, presentation and both old
and larger saved-replay reads returned 200. An aged identical result retry returned
the original receipt; complete saved-state/history hashes remained unchanged
through the final read/retry checks. No live review-game result was submitted.

This is continuation testing across preserved checkpoints, not a fresh browser run
from the opening. The harness interruption was reconciled on a recovered disposable
copy before its known-uncommitted body was retried. Manual timeout/mode-selection
UI and overtime were not exercised by this long-game script; the focused suites
cover those paths separately. Performance is not yet optimized: a late Fast Sim
request took about 16.7 seconds, and final read endpoints took about 5–7 seconds
in the fresh-process checks. These are server timings, not browser FPS measurements
or gameplay-balance acceptance. No Stage C work or live-app configuration change is
included.
