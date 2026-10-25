import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {buildFootballPlayer} from '../../resources/js/practice/player-model.js';
import {sidelineNumbers} from '../../resources/js/practice/sideline-numbers.js';

function drawingDocument() {
    const draws=[];
    const context={strokeText(text){draws.push(['outline',text,this.strokeStyle]);},fillText(text){draws.push(['fill',text,this.fillStyle]);}};
    return {draws,document:{createElement(){return {getContext:()=>context};}}};
}

test('shoulder numbers share the real jersey number texture and remain on shoulder pads during arm swings',()=>{
    const f=drawingDocument();
    const model=buildFootballPlayer({number:'00',height_inches:77,weight_pounds:300},{number:'#123abc',number_outline:'#fedcba'},f.document);
    const left=model.getObjectByName('jersey-number-shoulder-left'),right=model.getObjectByName('jersey-number-shoulder-right');
    assert.ok(left&&right);assert.equal(left.parent,right.parent);assert.ok(left.parent.isMesh);
    assert.equal(left.material,right.material);
    const matching=[];model.traverse(mesh=>{if(mesh.isMesh&&mesh.material===left.material)matching.push(mesh);});
    assert.equal(matching.length,4);assert.deepEqual(f.draws,[['outline','00','#fedcba'],['fill','00','#123abc']]);
    assert.ok(left.position.x<-.15);assert.ok(right.position.x>.15);
    assert.equal(left.rotation.x,-Math.PI/2);assert.equal(right.rotation.x,-Math.PI/2);
    assert.equal(left.rotation.z,-Math.PI/2);assert.equal(right.rotation.z,Math.PI/2);
    model.updateMatrixWorld(true);const before=left.getWorldPosition(new THREE.Vector3());
    model.userData.arms[0].rotation.x=.8;model.updateMatrixWorld(true);
    assert.ok(before.distanceTo(left.getWorldPosition(new THREE.Vector3()))<.001);
    const unnumbered=buildFootballPlayer({}, {},f.document);
    assert.equal(unnumbered.getObjectByName('jersey-number-shoulder-left'),undefined);
});

test('bench and tunnel numbers include outward-facing sleeve faces in the existing atlas batch',()=>{
    const f=drawingDocument(),{geometry}=sidelineNumbers([{id:1,number:87}],{},f.document);
    assert.equal(geometry.attributes.position.count,16);
    const normal=geometry.attributes.normal,position=geometry.attributes.position;
    for(let i=8;i<16;i++) {
        assert.ok(Math.abs(normal.getX(i))>.99);assert.ok(Math.abs(position.getX(i))>.675);
        assert.ok(position.getY(i)<1.5&&position.getY(i)>1.3);
    }
    assert.deepEqual([...geometry.attributes.numberCell.array],[7,1]);
});
