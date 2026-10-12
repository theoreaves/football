# Quick Sim and drive history

Quick Sim offers Sim Drive, Sim Quarter, Sim Half and Sim Game. All use the
existing deterministic engine, preserve saved history/highlights, resolve CPU
choices and penalties, and retain the existing version check and database lock.

- Drive: finish the current offensive possession, stopping on a score, punt,
  turnover, downs, halftime or game end. From kickoff, include the ensuing
  offensive drive. A touchdown stops before the conversion choice; when invoked
  during a conversion, finish the conversion and stop before kickoff.
- Quarter: finish the current quarter, including required untimed downs/tries.
- Half: finish Q2 or Q4, depending on the current half. In overtime this means
  finish the current overtime period, rather than assume two overtime periods.
- Game: finish through applicable overtime, as before.

Partial simulations restore the original team controls and quick-sim flag;
season results are recorded only when the game becomes final. A drive already
ended by a pending penalty decision stops after resolving that decision. The
confirmation describes the selected scope and the progress overlay blocks
interaction while the request runs.

Stats → Drives shows team, start period/clock, play count, offensive yards and
result for every completed drive, plus the current drive as In progress. It uses
saved stat deltas, excludes kickoffs/conversions from offensive drive totals,
and hides the unrevealed last play using the same rule as Team Stats. Quick Sim
plays are included without changing statistics or adding database records.

Midfield artwork now sits above permanent field lines/hash marks. The home
tunnel logo is fitted to its top surface; the visitor tunnel remains unbranded.
The previous 10-second play-result pause is retained.
