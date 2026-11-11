import test from 'node:test';
import assert from 'node:assert/strict';
import {automaticHighlight} from '../../resources/js/arcade/highlights.js';

const recording = (reason, yards = 0, direction = 1) => ({result: {reason, yards}, direction,
    scales: {position: 100}, field: {goalX: direction > 0 ? 110 : 10},
    frames: [[1, 100000, 0, [[direction > 0 ? 10700 : 1300, 2600]], []]], events: []});

test('automatic highlights default off and ignore unfinished recordings or unknown preferences', () => {
    for (const reason of ['touchdown', 'interception', 'tackle']) {
        const data = recording(reason, 30);
        assert.equal(automaticHighlight(data), null);
        assert.equal(automaticHighlight(data, 'off'), null);
        assert.equal(automaticHighlight(data, 'other'), null);
    }
    assert.equal(automaticHighlight({result: null}, 'all'), null);
    assert.equal(automaticHighlight(null, 'all'), null);
});

test('major highlights include touchdowns and interceptions without broadening to other finishes', () => {
    assert.equal(automaticHighlight(recording('touchdown'), 'major').type, 'touchdown');
    assert.equal(automaticHighlight(recording('interception'), 'major').type, 'interception');
    for (const reason of ['tackle', 'sack', 'incomplete', 'out_of_bounds']) assert.equal(automaticHighlight(recording(reason, 30), 'major'), null);
});

test('all highlights identify 25-yard gains, goal-line stops in either direction and broken-tackle stops', () => {
    assert.equal(automaticHighlight(recording('out_of_bounds', 25), 'all').type, 'long_gain');
    assert.equal(automaticHighlight(recording('out_of_bounds', 24.99), 'all'), null);
    for (const direction of [1, -1]) assert.equal(automaticHighlight(recording('tackle', 20, direction), 'all').type, 'notable_tackle');
    const data = recording('tackle', 5); data.frames[0][3][0][0] = 8500;
    assert.equal(automaticHighlight(data, 'all'), null);
    data.events.push([1, 100000, 'broken_tackle', 0, 1, {}]);
    const before = JSON.stringify(data);
    assert.equal(automaticHighlight(data, 'all').label, 'Broken-tackle stop');
    assert.equal(JSON.stringify(data), before);
});
