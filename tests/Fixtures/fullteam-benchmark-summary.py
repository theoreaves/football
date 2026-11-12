"""Summarize an approved Stage B test log without copying roster/player information."""
import collections
import hashlib
import json
import pathlib
import re
import statistics
import sys

log = pathlib.Path(sys.argv[1]).read_text()
rows = [json.JSONDecoder().raw_decode(log[m.start():])[0]['fullteam_benchmark']
        for m in re.finditer(r'\{"fullteam_benchmark":', log)]
verified = re.findall(r'Tests:\s+(\d+) passed \((\d+) assertions\)', log)
if not verified or re.search(r'Tests:.*failed', log):
    raise SystemExit('The benchmark test run did not finish successfully')
keys = [(r['variant'], r['inputSeed'], r['call'], r['direction']) for r in rows]
if len(keys) != len(set(keys)):
    raise SystemExit('Duplicate benchmark scenario rows')
if len(rows) not in (150, 180):
    raise SystemExit(f'Incomplete scenario matrix: {len(rows)} rows')


def summary(values):
    values = [v for v in values if v is not None]
    return None if not values else {'min': min(values), 'median': statistics.median(values), 'max': max(values)}


def group_summary(group):
    return {
        'snaps': len(group),
        'outcomes': dict(collections.Counter(r['reason'] for r in group)),
        'passesAttempted': sum(r['eventCounts'].get('throw', 0) for r in group),
        'completions': sum(r['passOutcome'] == 'complete' for r in group),
        'gainYards': summary([r['gain'] for r in group]),
        'yardsAfterCatch': summary([r['yac'] for r in group]),
        'liveSeconds': summary([r['seconds'] for r in group]),
        'firstBlockSeconds': summary([r['firstBlockSeconds'] for r in group]),
        'blockContacts': summary([r['blockCount'] for r in group]),
        'blockSheds': summary([r['blockShedCount'] for r in group]),
        'blockContactDistanceYards': summary([c['distance'] for r in group for c in r['contactDistances'] if c['type'] == 'block']),
        'releaseReceiverSeparationYards': summary([(r.get('decision') or {}).get('receiverSeparationNow') for r in group]),
        'catchReceiverSeparationYards': summary([r.get('catchSeparation') for r in group]),
        'selectedReceiverRoles': dict(collections.Counter((r.get('decision') or {}).get('key') for r in group if (r.get('decision') or {}).get('key'))),
        'blockHoldSeconds': summary([b['seconds'] for r in group for b in r.get('blockDurations', [])]),
        'censoredBlockHolds': sum(b['censored'] for r in group for b in r.get('blockDurations', [])),
    }

base = {(r['inputSeed'], r['call'], r['direction']): r for r in rows if r['variant'] == 'baseline'}
paired = {}
for variant in sorted({r['variant'] for r in rows} - {'baseline', 'saved_roster'}):
    pairs = [(r, base[(r['inputSeed'], r['call'], r['direction'])]) for r in rows if r['variant'] == variant]
    paired[variant] = {
        'pairedSnaps': len(pairs),
        'changedTerminalOutcomes': sum(a['reason'] != b['reason'] for a, b in pairs),
        'gainDeltaYards': summary([a['gain'] - b['gain'] for a, b in pairs]),
        'liveTimeDeltaSeconds': summary([a['seconds'] - b['seconds'] for a, b in pairs]),
        'changedRecordingHashes': sum(a['recordingHash'] != b['recordingHash'] for a, b in pairs),
    }
files = ['resources/js/arcade/full-team.js', 'resources/js/arcade/full-team-plays.js',
         'resources/js/arcade/recording.js', 'resources/js/arcade/tuning.js',
         'app/Services/Arcade/FullTeamSetup.php', 'app/Services/Arcade/FullTeamLivePlayContract.php',
         'tests/Fixtures/fullteam-benchmark-physics.mjs', 'tests/Fixtures/fullteam-benchmark-support.php',
         'tests/Unit/FullTeamBenchmarkTest.php']
result = {
    'description': 'Stage B single-snap implementation QA. Exploratory, not a balance baseline or acceptance threshold.',
    'scope': 'Five calls, both attacking directions, three fixed seeds. No complete games, season integration, defensive UI or Stage C work.',
    'command': 'FULLTEAM_BENCHMARK_REPORT=1 FULLTEAM_SAVED_ROSTER_FIXTURE=/path/to/approved-export.json php artisan test --compact tests/Unit/FullTeamBenchmarkTest.php',
    'inputPolicy': 'Visible-state route-leading reads with short outlets, deadline/pressure fallback, then forward YAC/run steering toward visible gaps. No future state, hidden throw target, RNG inspection, Coach resolution or speculative candidate replay.',
    'rosters': 'Synthetic equal-70 snapshots through production GamePersonnel/FullTeamSetup, plus an optional approved read-only saved-roster export. Saved-roster names, appearances, source IDs and raw snapshots are not copied into this report.',
    'interventions': 'Four isolated one-field changes: home LG blocking, QB throwing, WR1 catching, or away LB2 tackling, from 70 to 90. No speed changes or balance tuning.',
    'limitations': [
        'Scripted inputs are not human decisions. Thirty baseline samples include mirrored pairs, not thirty independent games.',
        'YAC uses actual catch contact to terminal runner position, so a behind-line outlet can have YAC greater than net gain.',
        'Block hold durations ending at dead ball are right-censored, not proven sheds.',
        'Headless JavaScript simulation timings include policy decision cost; exclude browser rendering, networking and Node startup. Capture/serialization and PHP derive are separate measurements.',
        'Wall timings are observations on one Mac and vary with JIT/system scheduling; no FPS or production performance guarantee.',
        'PHP checks validate bounded evidence and finalization, not anti-cheat proof. HTTP persistence/concurrency are covered separately.',
    ],
    'snapCount': len(rows), 'speedScale': .925,
    'verification': {'passedTests': int(verified[-1][0]), 'assertions': int(verified[-1][1]), 'allScenarioRecordingsAcceptedByPhp': True},
    'groups': {variant: {call: group_summary([r for r in rows if r['variant'] == variant and r['call'] == call])
                        for call in sorted({r['call'] for r in rows})}
               for variant in sorted({r['variant'] for r in rows})},
    'pairedRatingEffects': paired,
    'payloadObserved': {k: summary([r[k] for r in rows]) for k in ['bytes', 'nodes', 'frames', 'events', 'ticks']},
    'payloadLimits': {'bytes': 2097152, 'nodes': 250000, 'frames': 858, 'events': 256, 'ticks': 1200},
    'timingObserved': {**{k: summary([r['timing'][k] for r in rows]) for k in rows[0]['timing']}, 'phpValidationMs': summary([r['phpValidationMs'] for r in rows])},
    'sourceSha256': {f: hashlib.sha256(pathlib.Path(f).read_bytes()).hexdigest() for f in files},
    'scenarioRecordingHashesSha256': hashlib.sha256(json.dumps([(key, r['recordingHash']) for key, r in zip(keys, rows)], separators=(',', ':')).encode()).hexdigest(),
}
print(json.dumps(result, indent=2))
