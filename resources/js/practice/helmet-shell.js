import * as THREE from 'three';

// Local coordinates: +Z faces forward. The brow opens above the face;
// the sides and rear extend down around the ears and back of the head.
export function helmetPoint(theta, phi, lift = 0) {
    const x = .355 * Math.sin(theta) * Math.cos(phi);
    const y = .38 * Math.cos(theta);
    const z = .405 * Math.sin(theta) * Math.sin(phi);
    const normal = new THREE.Vector3(x / (.355 ** 2), y / (.38 ** 2), z / (.405 ** 2)).normalize();
    return new THREE.Vector3(x, y, z - .025).addScaledVector(normal, lift);
}

function surface(columns, rows, pointAt) {
    const positions = [], normals = [], uv = [], indices = [];
    for (let row = 0; row <= rows; row++) {
        for (let col = 0; col <= columns; col++) {
            const u = col / columns, v = row / rows;
            const point = pointAt(u, v);
            positions.push(...point.toArray());
            const normal = new THREE.Vector3(point.x / (.355 ** 2), point.y / (.38 ** 2), (point.z + .025) / (.405 ** 2)).normalize();
            normals.push(...normal.toArray()); uv.push(u, 1 - v);
            if (row < rows && col < columns) {
                const a = row * (columns + 1) + col, b = a + columns + 1;
                indices.push(a, a + 1, b, a + 1, b + 1, b);
            }
        }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('normal', new THREE.Float32BufferAttribute(normals, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
    geometry.setIndex(indices);
    return geometry;
}

export function helmetShellGeometry() {
    return surface(48, 24, (u, v) => {
        const phi = u * Math.PI * 2;
        const front = Math.max(0, Math.sin(phi));
        const bottom = 2.25 - .9 * (front ** 4);
        return helmetPoint(v * bottom, phi);
    });
}

export function helmetLogoGeometry(sign, width = .36, height = .29) {
    const center = sign < 0 ? Math.PI : 0;
    return surface(12, 8, (u, v) => helmetPoint(
        1.48 + (v - .5) * height / .38,
        center - (u - .5) * width / .405,
        .005,
    ));
}

export function helmetStripeGeometry() {
    return surface(1, 48, (u, v) => {
        const angle = -2.25 + v * 3.6;
        const point = helmetPoint(Math.abs(angle), angle < 0 ? -Math.PI / 2 : Math.PI / 2, .004);
        point.x = (u - .5) * .052;
        return point;
    });
}
