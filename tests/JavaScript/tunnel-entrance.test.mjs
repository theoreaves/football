import test from 'node:test';
import assert from 'node:assert/strict';
import {entrancePose} from '../../resources/js/practice/tunnel-entrance.js';
import {sidelineSlot} from '../../resources/js/practice/unit-exchange.js';
test('staggered entrances stay inside the seating bowl and arrive at roster slots without changing targets',()=>{
    for(const side of ['home','away'])for(let index=0;index<60;index++){
        const target=sidelineSlot(side,index),original={...target};let previous=null;
        for(let seconds=0;seconds<=35;seconds+=.1){
            const pose=entrancePose(side,index,target,seconds);
            assert.ok([pose.x,pose.z,pose.yaw,pose.bob].every(Number.isFinite));
            assert.ok(((pose.x-60)/80)**2+((pose.z-26.665)/48)**2<1, `inside bowl: ${side} ${index} ${seconds}`);
            if(previous)assert.ok(Math.hypot(pose.x-previous.x,pose.z-previous.z)<=.601);
            previous=pose;
        }
        assert.deepEqual(target,original);
        const final=entrancePose(side,index,target,35);assert.equal(final.x,target.x);assert.equal(final.z,target.z);assert.equal(final.complete,true);
    }
    assert.equal(entrancePose('home',10,sidelineSlot('home',10),0).visible,false);
    assert.deepEqual(entrancePose('home',3,sidelineSlot('home',3),4),entrancePose('home',3,sidelineSlot('home',3),4));
});

test('both tunnels send entrants straight toward the field before turning toward sidelines',()=>{
    for(const side of ['home','away']){
        const target=sidelineSlot(side,0),start=entrancePose(side,0,target,0),moving=entrancePose(side,0,target,1);
        assert.equal(start.z,moving.z);
        assert.ok(side==='home'?moving.x>start.x:moving.x<start.x);
        assert.equal(moving.yaw,side==='home'?Math.PI/2:-Math.PI/2);
    }
});
