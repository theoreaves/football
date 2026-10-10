import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {refereeSignal, buildReferees} from '../../resources/js/practice/referees.js';

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
test('signals reset on replay and point in both offensive directions', () => {
    const scene = new THREE.Scene(), refs = buildReferees(scene);
    for (const direction of [-1, 1]) {
        refs.update({line: 50, spot: 58, direction, signal: 'first_down', time: 1, active: true});
        const arms = refs.group.children[0].children.filter(child => child.isGroup);
        assert.equal(arms[direction > 0 ? 1 : 0].rotation.z, direction * Math.PI / 2);
        refs.update({line: 50, signal: 'first_down', time: 0, active: false});
        assert.ok(arms.every(arm => arm.rotation.z === 0));
    }
    refs.update({line: 50, spot: 110, signal: 'touchdown', time: 1, active: true});
    for (const judge of refs.group.children.slice(1)) {
        const arms = judge.children.filter(child => child.isGroup);
        judge.updateMatrixWorld(true);
        for (const arm of arms) {
            const hand = arm.children.at(-1).getWorldPosition(new THREE.Vector3());
            assert.ok(hand.y > 2, 'touchdown hands above head');
        }
    }
    refs.update({line: 50, spot: 110, signal: 'touchdown', time: 0, active: false});
    assert.equal(refs.group.children[1].position.x, 50);
});
