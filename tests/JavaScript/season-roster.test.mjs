import test from 'node:test';
import assert from 'node:assert/strict';
import { rosterMatches } from '../../resources/js/season-roster.js';

test('roster filters combine partial case insensitive names with exact positions', () => {
 assert.equal(rosterMatches('Theo Reaves', 'QB', ' REAV ', 'QB'), true);
 assert.equal(rosterMatches('Theo Reaves', 'QB', 'theo', 'RB'), false);
 assert.equal(rosterMatches('Theo Reaves', 'QB', 'Smith', ''), false);
 assert.equal(rosterMatches('Theo Reaves', 'QB', '', ''), true);
});
