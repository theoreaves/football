# Special teams: research and model choices

Inspected October 13, 2026. These are NFL references for the default game engine,
not a reconstruction of every historical season's rules.

## Declared onside kicks

Rule 6-1-6 now allows declared onside kicks regardless of quarter or score.
The referee must be notified before the play clock and then informs the receiver.
Kicking-team recoveries are dead immediately; receiving-team recoveries can
advance. Recovery before ten yards requires a receiving-team touch first.

Source: https://static.www.nfl.com/image/upload/fl_attachment/league/tqivdkzt9mu6wdgsh1ku.pdf

This implementation treats choosing "Declare onside kick" as declaration,
displays notification, uses the onside formation/hands team and simulates legal
recoveries at 10–14 yards. It does not introduce additional onside-specific
penalty types, out-of-bounds attempts, pre-ten-yard muffs or return touchdowns.
Existing kickoffs remain unchanged.

Own calculation from nflverse 2025 regular-season play-by-play: 52 descriptions
containing "onside" with kickoff_attempt=1, of which five have
own_kickoff_recovery=1 (9.62%). This is a descriptive extraction, not a new
league-certified statistic. The model uses 5/52 with a small kicker-versus-hands
rating adjustment, bounded to 4–16%. A kicking-team recovery cannot advance.
In overtime, a legally recovered kickoff counts as the receiving team's
opportunity under Rule 16-1-5(c).

CPU choice is a model decision: trailing in Q4 with <=90 seconds, <=150 seconds
with fewer than three timeouts, or down more than eight with <=240 seconds.
Thus the reported down-two, 1:22 case triggers an onside call. Human callers can
declare one in any legal kickoff situation, including overtime.

## Fake punts and field goals

FanIQ's original formation-tagged study of 1999–2025 regular seasons reports:

| Play | Detected sample | Conversion | Frequency reference |
| --- | ---: | ---: | --- |
| Fake punt | 514 | 53.9% | about 19 league-wide per season |
| Fake field goal | 201 | 27.9% | about 3 per season since 2018 |

Conversion means first down or touchdown. The data includes botched snaps and
emergency scrambles; it does not perfectly isolate intentionally called fakes.
The fake-field-goal conversion rate is the long historical pooled rate, not an
estimate based only on the tiny recent sample. The same study reports 62.8%
for short, chosen punt fakes and 46.6% for forced/long punt fakes. A separate
2012 original analysis likewise describes formation detection and small samples.

Sources:
- https://faniqsports.com/research/fakes-decoded.html
- https://www.advancedfootballanalytics.com/2012/11/to-fake-or-not-to-fake-fourth-down.html

The model uses short/long punt reference rates and the pooled FG rate, adjusted
by specialist skill, defensive awareness, distance and whether the defense is
playing a conventional defense instead of a kick unit. Ordinary fumble handling
still applies after the calibrated result. Therefore the final observed success
rate varies with opponents and context; these percentages are not guarantees.
Pass fakes can fail incomplete or complete short; run fakes can be stopped short.
Both can convert or score through the existing scrimmage resolution. The punter
handles punt fakes and serves as the holder on field-goal fakes. Stats count the
actual player's rushing/passing, never a punt or field-goal attempt without a kick.
The CPU presents a kick formation to a human defense without announcing the fake.

## Rare CPU selection

Our 2025 regular-season extraction contains 652 actual punts and 375 fourth-down
field goals meeting the model's filters: <=8 yards to go, field position between
own 30 and opponent 10, and outside the final two minutes of Q2/Q4. Nominal fake
selection rates are 19/652 (2.91%) and 3/375 (0.8%) *within that eligible subset*,
not across all plays or all kicks. Selection also depends on the CPU actually
choosing to kick. This targets the research's league-wide reference volumes;
real simulated seasonal volumes will differ with team tendencies and situations.

Raw source: https://github.com/nflverse/nflverse-data/releases/download/pbp/play_by_play_2025.csv.gz
Provider documentation: https://nflreadr.nflverse.com/reference/load_pbp.html
Reproduce our extraction with `python3 scripts/research-special-teams.py`.
No downloads occur during gameplay. CPU and outcome rolls remain seed-based.
