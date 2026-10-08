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

test('saved face colors hair and beard change the shared player model', () => {
    const skin = new THREE.MeshStandardMaterial({ color: '#593b2c' });
    const face = buildPlayerFace({ appearance: { hair: 'curly', hair_color: '#332211', eye_color: '#2266aa', nose: 'wide', mouth: 'smile', beard: 'goatee' } }, skin);
    assert.equal(face.getObjectByName('eye').material.color.getHexString(), '2266aa');
    assert.equal(face.getObjectByName('hair').material.color.getHexString(), '332211');
    assert.equal(face.children.filter(mesh => mesh.name === 'hair-curl').length, 18);
    assert.ok(face.getObjectByName('beard'));
    assert.equal(face.getObjectByName('nose').scale.x, 1.2);
    assert.equal(face.getObjectByName('mouth').geometry.type, 'TubeGeometry');
    const bald = buildPlayerFace({ appearance: { hair: 'bald', beard: 'none' } }, skin);
    assert.equal(bald.getObjectByName('hair'), undefined);
    assert.equal(bald.getObjectByName('beard'), undefined);
    assert.ok(buildPlayerFace({ appearance: { hair: 'long' } }, skin).getObjectByName('hair-back'));
});

test('appearance thumbnails distinguish all selectable hair and face options', async () => {
    const { featureGraphic } = await import('../../resources/js/player-appearance.js');
    for (const [feature, choices] of Object.entries({hair:['bald','buzz','short','curly','long'],brow:['straight','angled','thick','arched'],nose:['standard','small','wide','long'],mouth:['neutral','wide','thin','smile'],beard:['none','stubble','moustache','goatee','full']})) {
        const graphics = choices.map(choice => featureGraphic(feature, choice));
        assert.equal(new Set(graphics).size, choices.length);
        assert.ok(graphics.every(graphic => graphic.includes('<svg') && !graphic.includes('undefined')));
    }
});

test('hair leaves the eyes and eyebrows uncovered for every hairstyle', () => {
    const skin = new THREE.MeshStandardMaterial();
    for (const hair of ['buzz', 'short', 'curly', 'long']) {
        const face = buildPlayerFace({appearance: {hair}}, skin);
        face.updateMatrixWorld(true);
        const meshes = face.children.filter(mesh => mesh.name.startsWith('hair'));
        for (const x of [-.075, 0, .075]) {
            for (const y of [.045, .075]) {
                const ray = new THREE.Raycaster(new THREE.Vector3(x, 1.97 + y, 1), new THREE.Vector3(0, 0, -1));
                for (const hit of ray.intersectObjects(meshes)) {
                    assert.ok(hit.point.z < .20, `${hair} covers the face at ${x}, ${y}`);
                }
            }
        }
    }
});

test('head shapes resize the face ears and hair together and leave the helmet unchanged', async () => {
    const { buildFootballPlayer } = await import('../../resources/js/practice/player-model.js');
    const models = ['round','oval','square','wide','long'].map(head_shape => buildFootballPlayer({appearance:{head_shape,hair:'short'}}, {}, mockDocument()));
    const round = models[0].getObjectByName('player-head-shape');
    assert.equal(round.scale.x, 1);
    assert.ok(models[3].getObjectByName('player-head-shape').scale.x > round.scale.x);
    assert.ok(models[4].getObjectByName('player-head-shape').scale.y > round.scale.y);
    for (const model of models) {
        const parts = model.getObjectByName('player-head-shape');
        assert.equal(model.getObjectByName('player-face').parent, parts);
        assert.equal(model.getObjectByName('player-head').parent, parts);
        assert.deepEqual(model.getObjectByName('helmet-shell').scale.toArray(), [1,1,1]);
    }
    assert.notDeepEqual([...models[2].getObjectByName('player-head').geometry.attributes.position.array], [...models[0].getObjectByName('player-head').geometry.attributes.position.array]);
    const { featureGraphic } = await import('../../resources/js/player-appearance.js');
    assert.equal(new Set(['round','oval','square','wide','long'].map(shape => featureGraphic('head_shape',shape))).size, 5);
});
