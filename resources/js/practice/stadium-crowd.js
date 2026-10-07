import * as THREE from 'three';

export function crowdPlan(seatCount, settings = {}) {
    let seed = (Number(settings.seed) || 2026) >>> 0;
    const random = () => { seed = (Math.imul(seed, 1664525) + 1013904223) >>> 0; return seed / 4294967296; };
    const percent = (value, fallback) => Math.max(0, Math.min(100, Number.isFinite(Number(value)) ? Number(value) : fallback));
    const occupied = Math.round(seatCount * percent(settings.fullness ?? 80, 80) / 100);
    const visitors = Math.round(occupied * percent(settings.visitors ?? 10, 10) / 100);
    const neutral = Math.round((occupied - visitors) * .3);
    const seats = Array.from({ length: seatCount }, (_, i) => i);
    for (let i = seats.length - 1; i > 0; i--) {
        const j = Math.floor(random() * (i + 1)); [seats[i], seats[j]] = [seats[j], seats[i]];
    }
    return seats.slice(0, occupied).map((seat, index) => ({ seat,
        allegiance: index < visitors ? 'away' : index < visitors + neutral ? 'neutral' : 'home',
        shade: random(), skin: Math.floor(random() * 5), clothing: Math.floor(random() * 8),
    }));
}

export function buildStadiumCrowd(seats, settings, home, away) {
    const group = new THREE.Group(); group.name = 'stadium-crowd';
    const plan = crowdPlan(seats.length, settings);
    group.userData.attendance = plan.length; group.userData.capacity = seats.length;
    group.userData.visitors = plan.filter(fan => fan.allegiance === 'away').length;
    if (!plan.length) return group;
    const paint = () => new THREE.MeshStandardMaterial({ color: '#ffffff', roughness: .9 });
    const bodies = new THREE.InstancedMesh(new THREE.BoxGeometry(.48, .55, .35), paint(), plan.length);
    const heads = new THREE.InstancedMesh(new THREE.SphereGeometry(.145, 8, 6), paint(), plan.length);
    const legs = new THREE.InstancedMesh(new THREE.BoxGeometry(.42, .2, .42), paint(), plan.length);
    bodies.name = 'fan-shirts'; heads.name = 'fan-heads'; legs.name = 'fan-pants';
    const neutral = ['#e7e1d8', '#716589', '#579176', '#d48649', '#526477', '#d36c76', '#b8a75e', '#424650'];
    const skin = ['#edc5a3', '#dca778', '#bf855c', '#925c3c', '#593b2c'];
    const dummy = new THREE.Object3D();
    plan.forEach((fan, i) => {
        const seat = seats[fan.seat]; dummy.rotation.y = seat.yaw;
        dummy.position.set(seat.x, seat.y + .55, seat.z); dummy.updateMatrix(); bodies.setMatrixAt(i, dummy.matrix);
        const team = fan.allegiance === 'away' ? away : home;
        const teamColor = fan.shade > .75 ? team.secondary_color || team.primary_color : team.primary_color;
        const color = fan.allegiance === 'neutral' ? neutral[fan.clothing] : teamColor || team.uniform?.shirt || team.stadium_seat_color || (fan.allegiance === 'home' ? '#3997ff' : '#eeeeee');
        bodies.setColorAt(i, new THREE.Color(color).lerp(new THREE.Color(fan.shade < .5 ? '#222222' : '#ffffff'), .12));
        dummy.position.y = seat.y + .98; dummy.updateMatrix(); heads.setMatrixAt(i, dummy.matrix); heads.setColorAt(i, new THREE.Color(skin[fan.skin]));
        dummy.position.set(seat.x + Math.sin(seat.yaw) * .1, seat.y + .16, seat.z + Math.cos(seat.yaw) * .1);
        dummy.updateMatrix(); legs.setMatrixAt(i, dummy.matrix); legs.setColorAt(i, new THREE.Color(fan.clothing % 2 ? '#354759' : '#676257'));
    });
    group.add(bodies, heads, legs); return group;
}
