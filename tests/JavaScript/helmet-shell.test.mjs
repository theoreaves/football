import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import { helmetShellGeometry, helmetPoint, helmetLogoGeometry } from '../../resources/js/practice/helmet-shell.js';
import { buildFootballPlayer } from '../../resources/js/practice/player-model.js';

test('helmet has a raised face opening deep sides and smooth outward-facing surfaces', () => {
    const geometry = helmetShellGeometry();
    const positions = geometry.attributes.position;
    const front = helmetPoint(1.35, Math.PI / 2);
    const side = helmetPoint(2.25, 0);
    assert.ok(front.y - side.y > .3);
    assert.ok(positions.count > 1000);
    for (let i = 0; i < geometry.index.count; i += 3) {
        const points = [0, 1, 2].map(offset => new THREE.Vector3().fromBufferAttribute(positions, geometry.index.getX(i + offset)));
        const cross = points[1].clone().sub(points[0]).cross(points[2].clone().sub(points[0]));
        const center = points[0].clone().add(points[1]).add(points[2]).divideScalar(3);
        center.z += .025;
        assert.ok(cross.dot(center) >= -1e-10, 'shell triangles face outward');
    }
});

test('helmet paint is smooth and both logo panels conform to the shell', () => {
    const document = { createElement: () => ({ getContext: () => ({ strokeText() {}, fillText() {} }) }) };
    const texture = new THREE.Texture(); texture.image = { width: 400, height: 100 };
    const player = buildFootballPlayer({}, { helmet_logo_left: 'left', helmet_logo_right: 'right' }, document, () => texture);
    assert.equal(player.getObjectByName('helmet-shell').material.flatShading, false);
    for (const sign of [-1, 1]) {
        const geometry = helmetLogoGeometry(sign);
        const positions = geometry.attributes.position;
        const xs = Array.from({ length: positions.count }, (_, i) => positions.getX(i));
        assert.ok(xs.every(x => x * sign > 0));
        assert.ok(Math.max(...xs) - Math.min(...xs) > .02, 'logo bends around shell');
        const logo = player.getObjectByName(sign < 0 ? 'helmet-logo-left' : 'helmet-logo-right');
        logo.onBeforeRender();
        logo.geometry.computeBoundingBox();
        const size = logo.geometry.boundingBox.getSize(new THREE.Vector3());
        assert.ok(size.z > size.y * 3, 'wide logo retains its aspect ratio');
    }
});
