import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import { addJerseyName, jerseyLastName } from '../../resources/js/practice/jersey-names.js';

test('jersey names use the full last name on the back above the number in the selected color', () => {
    const draws = [], context = { fillText(...args) { draws.push([this.fillStyle, ...args]); } };
    const document = { createElement: () => ({ getContext: () => context }) };
    const group = new THREE.Group();
    addJerseyName(group, { lastname: 'Van Buren', name: 'Jim Van Buren' }, document, { name_enabled: true, name_color: '#123abc' });
    const label = group.getObjectByName('jersey-name');
    assert.equal(label.userData.text, 'VAN BUREN');
    assert.equal(label.rotation.y, Math.PI); assert.ok(label.position.z < 0); assert.ok(label.position.y > 1.5);
    assert.deepEqual(draws[0], ['#123abc', 'VAN BUREN', 256, 48, 490]);
    assert.equal(jerseyLastName({ name: 'Older Saved Player' }), 'Player');
});

test('names can be disabled and missing names do not produce labels', () => {
    const document = { createElement() { throw new Error('should not create a label'); } };
    const group = new THREE.Group();
    addJerseyName(group, { lastname: 'Smith' }, document, { name_enabled: false });
    addJerseyName(group, {}, document, { name_enabled: true });
    assert.equal(group.children.length, 0);
});
