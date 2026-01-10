<?php

namespace App\Services;

class TeamCardParser
{
    public function parse(string $text, string $teamYear): array
    {
        $lines = $this->lines($text);

        $rows = [];
        $section = null;
        $qbDepth = 0;

        foreach ($lines as $line) {
            if (preg_match('/^\s*QB\b/i', $line)) $section = 'QB';
            if (preg_match('/^\s*RB1\b/i', $line)) $section = 'OFF_SKILL'; // start of RB/TE/WR block
            if (preg_match('/^\s*DL1\b/i', $line)) $section = 'DEF';
            if (preg_match('/^\s*K\b/i', $line)) $section = 'K';
            if (preg_match('/^\s*P\b/i', $line)) $section = 'P';
            if (preg_match('/^\s*KR1\b/i', $line)) $section = 'KR';
            if (preg_match('/^\s*PR1\b/i', $line)) $section = 'PR';

            $line = trim($line);
            if ($line === '' || $this->isNoise($line)) continue;

            if ($section === 'QB') {
                $qbDepth++;
            }

            $parsed = match ($section) {
                'QB'        => $this->parseQB($line, $teamYear, $qbDepth),
                'OFF_SKILL' => $this->parseOffSkill($line, $teamYear),
                'DEF'       => $this->parseDefense($line, $teamYear),
                'K'         => $this->parseKicker($line, $teamYear),
                'P'         => $this->parsePunter($line, $teamYear),
                'KR'        => $this->parseReturner($line, $teamYear, 'KR'),
                'PR'        => $this->parseReturner($line, $teamYear, 'PR'),
                default     => null,
            };

            if ($parsed) $rows[] = $parsed;
        }

        return $rows;
    }

    private function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", " "], $text);
        $text = preg_replace('/[ ]{2,}/', ' ', $text);
        $raw = array_map('trim', explode("\n", $text));
        return array_values(array_filter($raw, fn($l) => $l !== ''));
    }

    private function isNoise(string $line): bool
    {
        return (bool) preg_match(
            '/^(2025 |Points Scored|Points Allowed|Rush Evade|Rush Power|Tackle Sack|Field Goals|Season Ticket Football|OL ensOffensive Lineens|↓|uU|d )/i',
            $line
        );
    }

    private function parseQB(string $line, string $teamYear, string $depth): ?array
    {
        // QB Kyler Murray 5 5 6 3 4 +6 7 1-4 ...
        if (!preg_match('/^(QB)\s+([A-Za-z\'.-]+)\s+([A-Za-z\'.-]+)\s+(.*)$/', $line, $m)) {
            return null;
        }

        [$posToken, $first, $last, $rest] = [$m[1], $m[2], $m[3], $m[4]];

        $tokens = $this->tokens($rest);

        // Expect: rush, evade, accy, deep, fum, spd, ctrl, rushRange
        $rush = $this->intTok($tokens[0] ?? '-');
        $evade = $this->intTok($tokens[1] ?? '-');
        $accy = $this->intTok($tokens[2] ?? '-');
        $deep = $this->intTok($tokens[3] ?? '-');
        $fum  = $this->intTok($tokens[4] ?? '-');
        $spd  = $this->intTok($tokens[5] ?? '-'); // +6 supported
        $ctrl = $this->intTok($tokens[6] ?? '-');
        $range = $this->rangeTok($tokens[7] ?? null);

        $player = $this->blankPlayer($first, $last);
        $player['position'] = 'QB';
        $player['rush'] = $rush;
        $player['pass_evade'] = $evade;
        $player['pass_accuracy'] = $accy;
        $player['pass_deep'] = $deep;
        $player['fumble'] = $fum;
        $player['speed'] = $spd;
        $player['pass_control'] = $ctrl;

        $pivot = $this->blankPivot($teamYear);
        $pivot['position'] = 'QB';
        $pivot['depth_chart_position'] = 'QB' . $depth;
        if ($range) {
            [$pivot['rush_from'], $pivot['rush_to']] = $range;
        }

        return ['player' => $player, 'pivot' => $pivot];
    }

    private function parseOffSkill(string $line, string $teamYear): ?array
    {
        // RB1 James Conner 6 6 5 - 5 +2 1-4 - 6-15 ...
        // WR1 Marquise Brown - - 6 2 6 +3 12-13 4-8 - ...
        if (!preg_match('/^(RB\d|WR\d|TE\d)\s+([A-Za-z\'.-]+)\s+([A-Za-z\'.-]+)\s+(.*)$/', $line, $m)) {
            return null;
        }

        $depth = strtoupper($m[1]); // RB1 etc.
        $pos = preg_replace('/\d+$/', '', $depth); // RB/WR/TE
        $first = $m[2];
        $last  = $m[3];
        $rest  = $m[4];

        $tokens = $this->tokens($rest);

        // Template columns:
        // rush, power, rec, deep, fumBase, spdMod, catchRange, catchPlusRange, rushRange
        $rush = $this->intTok($tokens[0] ?? '-');
        $power = $this->intTok($tokens[1] ?? '-');
        $rec = $this->intTok($tokens[2] ?? '-');
        $deep = $this->intTok($tokens[3] ?? '-');

        // These two are funky in the template:
        // Fum is often a number or '-' (WR shows "6")
        // Spd is often "+3" or "-" (or a plain number)
        $fumBase = $this->intTok($tokens[4] ?? '-');
        $spdMod  = $this->intTok($tokens[5] ?? '-');

        $catch = $this->rangeTok($tokens[6] ?? null);
        $catchPlus = $this->rangeTok($tokens[7] ?? null);
        $rushRange = $this->rangeTok($tokens[8] ?? null);

        $player = $this->blankPlayer($first, $last);
        $player['position'] = $pos;
        $player['rush'] = $rush;
        $player['rush_power'] = $power;
        $player['receive'] = $rec;
        $player['receive_deep'] = $deep;
        $player['fumble'] = $fumBase;
        $player['speed'] = $spdMod;

        $pivot = $this->blankPivot($teamYear);
        $pivot['position'] = $pos;
        $pivot['depth_chart_position'] = $depth;

        if ($catch) {
            [$pivot['catch_from'], $pivot['catch_to']] = $catch;
        }
        if ($catchPlus) {
            [$pivot['catch_plus_from'], $pivot['catch_plus_to']] = $catchPlus;
        }
        if ($rushRange) {
            [$pivot['rush_from'], $pivot['rush_to']] = $rushRange;
        }

        return ['player' => $player, 'pivot' => $pivot];
    }

    private function parseDefense(string $line, string $teamYear): ?array
    {
        // LB1 Isaiah Simmons 3 2 2 6 6 +8 15-17 1-5 6-7 ...
//        dump($line);
        if (!preg_match('/^(DL\d|DL|LB\d|LB|CB\d|S\d|DB)\s+([A-Za-z\'.-]+)\s+([A-Za-z\'.-]+)\s+(.*)$/', $line, $m)) {
//            dump('no match');
            return null;
        }
//        dump('match');

        $depth = strtoupper($m[1]); // DL1 etc, or DB
        $pos = $depth === 'DB' ? 'DB' : preg_replace('/\d+$/', '', $depth);
        $first = $m[2];
        $last  = $m[3];
        $rest  = $m[4];

        if ($depth === 'DL') {
            $depth = 'DL5';
        }
        if ($depth === 'LB') {
            $depth = 'LB5';
        }
        if ($depth === 'DB') {
            $depth = 'DB1';
        }

        $tokens = $this->tokens($rest);

        // tackle, sack, cover, int, strip, spd, sackRange, intRange, tackleRange
        $tackle = $this->intTok($tokens[0] ?? '-');
        $sack   = $this->intTok($tokens[1] ?? '-');
        $cover  = $this->intTok($tokens[2] ?? '-');
        $int    = $this->intTok($tokens[3] ?? '-');
        $strip  = $this->intTok($tokens[4] ?? '-');
        $spd    = $this->intTok($tokens[5] ?? '-');

        $sackR = $this->rangeTok($tokens[6] ?? null);
        $intR  = $this->rangeTok($tokens[7] ?? null);
        $tklR  = $this->rangeTok($tokens[8] ?? null);

        $player = $this->blankPlayer($first, $last);
        $player['position'] = $pos;
        $player['tackle'] = $tackle;
        $player['sack'] = $sack;
        $player['cover'] = $cover;
        $player['interception'] = $int;
        $player['strip'] = $strip;
        $player['speed'] = $spd;

        $pivot = $this->blankPivot($teamYear);
        $pivot['position'] = $pos;
        $pivot['depth_chart_position'] = $depth;

        if ($sackR) { [$pivot['sack_from'], $pivot['sack_to']] = $sackR; }
        if ($intR)  { [$pivot['interception_from'], $pivot['interception_to']] = $intR; }
        if ($tklR)  { [$pivot['tackle_from'], $pivot['tackle_to']] = $tklR; }

        return ['player' => $player, 'pivot' => $pivot];
    }

    private function parseKicker(string $line, string $teamYear): ?array
    {
        // K Matt Prater +6 +8 +5 +9 ...
        if (!preg_match('/^(K)\s+([A-Za-z\'.-]+)\s+([A-Za-z\'.-]+)\s+(.*)$/', $line, $m)) return null;

        $first = $m[2]; $last = $m[3];
        $tokens = $this->tokens($m[4]);

        $player = $this->blankPlayer($first, $last);
        $player['position'] = 'K';
        $player['kick30'] = $this->intTok($tokens[0] ?? '0');
        $player['kick39'] = $this->intTok($tokens[1] ?? '0');
        $player['kick49'] = $this->intTok($tokens[2] ?? '0');
        $player['kick50'] = $this->intTok($tokens[3] ?? '0');

        $pivot = $this->blankPivot($teamYear);
        $pivot['position'] = 'K';
        $pivot['depth_chart_position'] = 'K';

        return ['player' => $player, 'pivot' => $pivot];
    }

    private function parsePunter(string $line, string $teamYear): ?array
    {
        // P Andy Lee +6 ≤50 5 1 ...
        if (!preg_match('/^(P)\s+([A-Za-z\'.-]+)\s+([A-Za-z\'.-]+)\s+(.*)$/', $line, $m)) return null;

        $first = $m[2]; $last = $m[3];
        $tokens = $this->tokens($m[4]);

        $dist = $this->intTok($tokens[0] ?? '0');          // +6
        $poochY = $this->leTokToInt($tokens[1] ?? null);   // ≤50 -> 50
        $pooch = $this->intTok($tokens[2] ?? '0');         // 5
        $blk = $this->intTok($tokens[3] ?? '0');           // 1

        $player = $this->blankPlayer($first, $last);
        $player['position'] = 'P';
        $player['punt_distance'] = $dist;
        $player['punt_pooch_yard'] = $poochY ?? 0;
        $player['punt_pooch'] = $pooch;
        $player['punt_block'] = $blk;

        $pivot = $this->blankPivot($teamYear);
        $pivot['position'] = 'P';
        $pivot['depth_chart_position'] = 'P';

        return ['player' => $player, 'pivot' => $pivot];
    }

    private function parseReturner(string $line, string $teamYear, string $type): ?array
    {
        // KR1 Pharoh Cooper 1-8 +7 +5 - ...
        // PR1 Greg Dortch 1-15 +2 - 9 ...
        if (!preg_match('/^(KR\d|PR\d)\s+([A-Za-z\'.-]+)\s+([A-Za-z\'.-]+)\s+(.*)$/', $line, $m)) return null;

        $depth = strtoupper($m[1]); // KR1/PR1
        $first = $m[2]; $last = $m[3];
        $tokens = $this->tokens($m[4]);

        $range = $this->rangeTok($tokens[0] ?? null); // 1-8
        $yds   = $this->intTok($tokens[1] ?? '0');    // +7
        $spd   = $this->intTok($tokens[2] ?? '0');    // +5
        $fum   = $this->intTok($tokens[3] ?? '0');    // '-' or '4' etc.

        $player = $this->blankPlayer($first, $last);
        $player['position'] = $type; // KR/PR
        $player['return_yards'] = $yds;
        $player['return_speed'] = $spd;
        $player['return_fumble'] = $fum;

        $pivot = $this->blankPivot($teamYear);
        $pivot['position'] = $type;

        if ($type === 'KR') {
            $pivot['kick_return_depth_chart_position'] = $depth;
            $pivot['depth_chart_position'] = $depth;
            if ($range) { [$pivot['kick_from'], $pivot['kick_to']] = $range; }
        } else {
            $pivot['punt_return_depth_chart_position'] = $depth;
            $pivot['depth_chart_position'] = $depth;
            if ($range) { [$pivot['punt_from'], $pivot['punt_to']] = $range; }
        }

        return ['player' => $player, 'pivot' => $pivot];
    }

    private function tokens(string $s): array
    {
        $s = trim($s);
        $s = preg_replace('/\s+/', ' ', $s);
        return explode(' ', $s);
    }

    private function intTok(?string $tok): int
    {
        if ($tok === null) return 0;
        $tok = trim($tok);
        if ($tok === '-' || $tok === '–') return 0;
        // strip leading +
        $tok = ltrim($tok, '+');
        // sometimes stray commas or percent
        $tok = preg_replace('/[^0-9\-]/', '', $tok);
        if ($tok === '' || $tok === '-') return 0;
        return (int)$tok;
    }

    private function rangeTok(?string $tok): ?array
    {
        if (!$tok) return null;
        $tok = trim($tok);
        if ($tok === '-' || $tok === '–') return null;

        // single number like "19" -> treat as 19-19
        if (preg_match('/^\d+$/', $tok)) {
            $n = (int)$tok;
            return [$n, $n];
        }

        if (preg_match('/^(\d+)-(\d+)$/', $tok, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }

        return null;
    }

    private function leTokToInt(?string $tok): ?int
    {
        if (!$tok) return null;
        // ≤50 or <=50
        if (preg_match('/^(?:≤|<=)(\d+)$/u', $tok, $m)) return (int)$m[1];
        return null;
    }

    private function blankPlayer(string $firstname, string $lastname): array
    {
        return [
            'firstname' => $firstname,
            'lastname'  => $lastname,
            'age'       => 20,
            'position'  => '',

            'pass_evade' => 0, 'pass_accuracy' => 0, 'pass_deep' => 0, 'pass_control' => 0,
            'rush' => 0, 'rush_power' => 0,
            'receive' => 0, 'receive_deep' => 0,
            'fumble' => 0, 'speed' => 0,
            'tackle' => 0, 'sack' => 0, 'cover' => 0, 'interception' => 0, 'strip' => 0,
            'kick30' => 0, 'kick39' => 0, 'kick49' => 0, 'kick50' => 0,
            'punt_distance' => 0, 'punt_pooch_yard' => 0, 'punt_pooch' => 0, 'punt_block' => 0,
            'return_yards' => 0, 'return_speed' => 0, 'return_fumble' => 0,
        ];
    }

    private function blankPivot(string $teamYear): array
    {
        return [
            'team_year' => $teamYear,
            'position' => '',
            'depth_chart_position' => '',
            'kick_return_depth_chart_position' => '',
            'punt_return_depth_chart_position' => '',
//            'jersey_number' => null,

            'catch_from' => 0, 'catch_to' => 0, 'catch_plus_from' => 0, 'catch_plus_to' => 0,
            'rush_from' => 0, 'rush_to' => 0,
            'sack_from' => 0, 'sack_to' => 0,
            'interception_from' => 0, 'interception_to' => 0,
            'tackle_from' => 0, 'tackle_to' => 0,
            'kick_from' => 0, 'kick_to' => 0,
            'punt_from' => 0, 'punt_to' => 0,
        ];
    }
}
