import test from 'node:test';
import assert from 'node:assert/strict';
import { scoreboardText } from '../../resources/js/practice/scoreboard.js';

test('goal to go labels preserve shortened distances away from the goal', () => {
    const state = { phase: 'scrimmage', status: 'playing', down: 1, spot: 95, distance: 5, clock: 120, quarter: 1, possession: 'home', home_score: 0, away_score: 0 };
    const names = { home: 'Home', away: 'Away' };
    assert.match(scoreboardText(state, names).compact, /1st & Goal/);
    assert.match(scoreboardText({ ...state, spot: 35 }, names).compact, /1st & 5/);
    assert.match(scoreboardText({ ...state, spot: 85, distance: 15, down: 2 }, names).compact, /2nd & Goal/);
});
