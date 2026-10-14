#!/usr/bin/env python3
"""Reproduce 2025 regular-season onside and eligible-kick counts (network required)."""
import csv
import gzip
import hashlib
import io
import json
import urllib.request
from collections import Counter

URL = 'https://github.com/nflverse/nflverse-data/releases/download/pbp/play_by_play_2025.csv.gz'

def main():
    with urllib.request.urlopen(URL, timeout=60) as response:
        data = response.read()
    eligible = Counter()
    onside = recovered = 0
    for row in csv.DictReader(io.TextIOWrapper(gzip.GzipFile(fileobj=io.BytesIO(data)))):
        if row['season_type'] != 'REG':
            continue
        if row['kickoff_attempt'] == '1' and 'onside' in row['desc'].lower():
            onside += 1
            recovered += row['own_kickoff_recovery'] == '1'
        if row['play_type'] not in ('punt', 'field_goal') or row['down'] != '4':
            continue
        try:
            distance = float(row['ydstogo'])
            spot = 100 - float(row['yardline_100'])
            quarter = float(row['qtr'])
            clock = float(row['quarter_seconds_remaining'])
        except ValueError:
            continue
        if distance <= 8 and 30 <= spot <= 90 and not (quarter in (2, 4) and clock <= 120):
            eligible[row['play_type']] += 1
    print(json.dumps({'source': URL, 'sha256': hashlib.sha256(data).hexdigest(),
                      'onside_attempts': onside, 'own_recoveries': recovered,
                      'onside_recovery_rate': recovered / onside if onside else None,
                      'eligible_kicks': dict(eligible)}, indent=2))

if __name__ == '__main__':
    main()
