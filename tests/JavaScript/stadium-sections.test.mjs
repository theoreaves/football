import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {buildStadium, stadiumRow, stadiumAisle} from '../../resources/js/practice/stadium.js';
import {buildChainGang} from '../../resources/js/practice/chain-gang.js';
import {buildSidelinePlayers} from '../../resources/js/practice/sideline-players.js';
const document = {createElement: () => ({getContext: () => ({fillRect(){},fillText(){}})})};

test('oval seating has tight even spacing with clear aisles and five crowd batches', () => {
    const angles = stadiumRow(80,48);
    const points = angles.map(a => ({x:80*Math.cos(a),z:48*Math.sin(a)}));
    const gaps = points.map((p,i) => Math.hypot(p.x-points[(i+1)%points.length].x,p.z-points[(i+1)%points.length].z));
    assert.ok(Math.min(...gaps)>=1.04 && Math.max(...gaps)<1.07);
    assert.ok(angles.filter(a=>!stadiumAisle(a,80,48)).length>330); // Previously 210 slots.
    const {group} = buildStadium({},document,{}, {fullness:100});
    const matrix = new THREE.Matrix4();
    for (let deck=1;deck<=3;deck++) {
        assert.equal(group.getObjectByName(`seating-aisles-${deck}`).count,16*8);
        const seats=group.getObjectByName(`seats-${deck}`);
        for(let i=0;i<seats.count;i++) {
            seats.getMatrixAt(i,matrix);
            const x=matrix.elements[12],z=matrix.elements[14];
            // First row axes; subsequent rows add identical radial offsets.
            const radius = Math.hypot(x,z);
            assert.ok(Number.isFinite(radius));
            const row=Math.round((matrix.elements[13]-.3-1-(deck-1)*(8*1.05+2))/1.05);
            const offset=(deck-1)*(8*1.8*.8+4)+row*1.8*.8+.72;
            const a=(Math.atan2(z/(48+offset),x/(80+offset))+Math.PI*2)%(Math.PI*2);
            assert.equal(stadiumAisle(a,80+offset,48+offset),false);
        }
    }
    assert.equal(group.getObjectByName('stadium-crowd').children.length,5);
});

test('both sideline benches use stadium home colors regardless of visitor uniform', () => {
    const scene=new THREE.Scene();
    buildSidelinePlayers(scene,{home:[{id:1}],away:[{id:2}]},
        {home:{primary_color:'#112233',secondary_color:'#aabbcc'},away:{uniform:{shirt:'#ff0000',helmet:'#00ff00'}}},document);
    for(const side of ['home','away']) {
        assert.equal(scene.getObjectByName(`${side}-bench-back`).material.color.getHexString(),'112233');
        assert.equal(scene.getObjectByName(`${side}-bench-seat`).material.color.getHexString(),'aabbcc');
    }
});

test('chain crew and markers walk off with officials and restore for replay including goal-to-go', () => {
    const gang=buildChainGang(new THREE.Scene(),document);
    gang.update(50,60,2);
    const initial=gang.group.children.map(c=>({position:c.position.clone(),visible:c.visible}));
    gang.startDeparture();gang.depart(3);
    assert.ok(gang.group.children.some((c,i)=>!c.position.equals(initial[i].position)));
    gang.depart(100);
    assert.ok(gang.group.children.every(c=>!c.visible));
    gang.update(50,60,2);
    gang.group.children.forEach((c,i)=>{assert.ok(c.position.equals(initial[i].position));assert.equal(c.visible,initial[i].visible);});
    gang.update(115,null,1);gang.startDeparture();gang.depart(100);gang.update(115,null,1);
    assert.equal(gang.group.children.filter(c=>c.visible).length,2); // Down box and its operator.
    gang.update(50,60,1,1,false);gang.startDeparture();gang.depart(3);assert.equal(gang.group.visible,false);
});
