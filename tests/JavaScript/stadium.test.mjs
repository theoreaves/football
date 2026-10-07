import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import { STADIUMS, buildStadium } from '../../resources/js/practice/stadium.js';
import { buildPlayerFace } from '../../resources/js/practice/player-face.js';
import { scoreboardText } from '../../resources/js/practice/scoreboard.js';

function mockDocument() {
    const drawn = [];
    const context = { fillRect() {}, strokeRect() {}, fillText(text) { drawn.push(text); } };
    return { drawn, createElement: () => ({ getContext: () => context }) };
}

test('all ten stadium designs have three seating decks two scoreboards and valid geometry', () => {
    assert.equal(Object.keys(STADIUMS).length, 10);
    for (const style of Object.keys(STADIUMS)) {
        const document = mockDocument();
        const { group, updateScoreboard } = buildStadium({ stadium_style: style, stadium_seat_color: '#aa1234', stadium_wall_color: '#345678', stadium_roof_color: '#abcdef' }, document);
        assert.ok(group.getObjectByName('seating-deck-3'));
        assert.equal(group.getObjectByName('seats-1').material.color.getHexString(), 'aa1234');
        assert.equal(group.getObjectByName('terrace').material.color.getHexString(), '345678');
        let boards = 0;
        group.traverse(object => {
            if (object.name === 'stadium-scoreboard') boards++;
            if (object.geometry) assert.ok([...object.geometry.attributes.position.array].every(Number.isFinite));
        });
        assert.equal(boards, 2);
        if (style === 'indoor_dome') {
            assert.ok(group.getObjectByName('dome-wall'));
            assert.equal(group.getObjectByName('dome-roof').material.color.getHexString(), 'abcdef');
        }
        const state = { home_score: 7, away_score: 3, clock: 89, quarter: 4, down: 3, distance: 4, spot: 70, possession: 'home', timeouts: { home: 1, away: 2 }, status: 'playing' };
        updateScoreboard(state, { home: 'Home', away: 'Visitors' });
        assert.ok(document.drawn.includes('7')); assert.ok(document.drawn.includes('Q4 · 01:29'));
        assert.ok(document.drawn.includes('Home · Down 3 & 4 · Opponent 30 yard line'));
        group.traverse(object => object.geometry?.dispose());
    }
});

test('generic face has two eyes a nose and mouth and shares player skin material', () => {
    const skin = new THREE.MeshStandardMaterial({ color: '#593b2c' });
    const face = buildPlayerFace({}, skin);
    assert.equal(face.children.filter(mesh => mesh.name === 'eye').length, 2);
    assert.equal(face.getObjectByName('nose').material, skin);
    assert.ok(face.getObjectByName('mouth'));
    assert.equal(face.userData.profile, 'generic');
});

test('compact score strip includes down distance and position without revealing another state', () => {
    const state = { home_score: 0, away_score: 0, clock: 900, quarter: 1, down: 2, distance: 7, spot: 38, possession: 'home', status: 'playing' };
    assert.equal(scoreboardText(state, { home: 'Home', away: 'Away' }).compact, '2nd & 7 · Own 38');
});
