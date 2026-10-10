import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {refereeSignal, buildReferees, buildRefereePaths, refereeFormation} from '../../resources/js/practice/referees.js';

const before = {phase: 'scrimmage', possession: 'home', down: 2, distance: 6, home_score: 0, away_score: 0};
const after = {...before, down: 1};
const animation = {gain: 8, outcome: 'run', events: []};
test('referee rulings use committed scores and earned first downs', () => {
    assert.equal(refereeSignal(animation, before, after), 'first_down');
    assert.equal(refereeSignal({...animation, gain: 3}, before, after), null);
    assert.equal(refereeSignal(animation, before, {...after, home_score: 6}), 'touchdown');
    assert.equal(refereeSignal(animation, before, {...after, away_score: 6}), 'touchdown');
    for (const score of [1, 2, 3]) assert.equal(refereeSignal({...animation, gain: 0}, before, {...after, phase: 'kickoff', home_score: score}), null);
    assert.equal(refereeSignal(animation, before, {...after, possession: 'away'}), null);
    assert.equal(refereeSignal({...animation, no_snap: true}, before, after), null);
    assert.equal(refereeSignal(animation, before, {...after, penalty_pending: true}), null);
    assert.equal(refereeSignal({...animation, events: [[0, 'Touchdown nullified']]}, before, {...after, home_score: 6}), null);
});
test('seven officials signal and reset poses in both directions', () => {
    const refs = buildReferees(new THREE.Scene());
    assert.deepEqual(refs.group.children.map(ref => ref.name), ['R', 'U', 'DJ', 'LJ', 'FJ', 'SJ', 'BJ']);
    for (const direction of [-1, 1]) {
        const poses = refereeFormation(50, direction);
        refs.update({poses, direction, signal: 'first_down', time: 1, active: true});
        const arms = refs.group.children[0].children.filter(child => child.isGroup).slice(2);
        assert.equal(arms[direction > 0 ? 1 : 0].rotation.z, direction * Math.PI / 2);
        refs.update({poses, direction, signal: 'first_down', time: 0, active: false});
        assert.ok(arms.every(arm => arm.rotation.z === 0));
        poses.forEach(pose => pose.x = direction > 0 ? 110 : 10);
        refs.update({poses, direction, signal: 'touchdown', time: 1, active: true});
        for (const judge of refs.group.children.slice(2)) {
            judge.updateMatrixWorld(true);
            const arms = judge.children.filter(child => child.isGroup).slice(2);
            for (const arm of arms) assert.ok(arm.children.at(-1).children.at(-1).getWorldPosition(new THREE.Vector3()).y > 2);
        }
    }
});

test('officials run continuously, with bounded speed, through dead ball and huddle', () => {
    for (const direction of [-1, 1]) {
        const line = direction > 0 ? 20 : 100;
        const animation = {line, direction, duration: 12, result_at: 11.3,
            ball: [[0,line,1,26.7], [1,line,1,26.7], [11.3,line + direction * 85,1,26.7], [12,line + direction * 85,1,26.7]]};
        const paths = buildRefereePaths(animation);
        assert.deepEqual(paths.sample(0), refereeFormation(line, direction));
        let previous = paths.sample(0);
        for (let tick = 1; tick <= 800; tick++) {
            const current = paths.sample(tick * .05);
            current.forEach((pose, i) => {
                assert.ok(Math.abs(pose.x - previous[i].x) <= .300001);
                assert.ok(Math.abs(pose.velocity) <= 6.000001);
                assert.ok(Math.abs(pose.velocity - previous[i].velocity) <= .175001);
                assert.equal(pose.z, previous[i].z);
            });
            previous = current;
        }
        assert.ok(Math.abs(paths.sample(8)[2].x - line) > 10, 'judge follows during live play');
        const saved = paths.sample(7.123); paths.sample(0); paths.sample(30);
        assert.deepEqual(paths.sample(7.123), saved, 'seeking is deterministic');
        assert.ok(Math.abs(paths.sample(11.30001)[2].x - paths.sample(11.29999)[2].x) < .001);
        const reset = paths.reset(15, 50, -direction);
        assert.deepEqual(reset(0), paths.sample(15), 'no huddle transition jump');
        let previousReset = reset(0);
        for (let tick = 1; tick <= 800; tick++) {
            const current = reset(tick * .05);
            current.forEach((pose, i) => assert.ok(Math.abs(pose.x - previousReset[i].x) <= .300001));
            previousReset = current;
        }
        const formation = refereeFormation(50, -direction);
        reset(40).forEach((pose, i) => assert.ok(Math.abs(pose.x - formation[i].x) < .05));
    }
});
