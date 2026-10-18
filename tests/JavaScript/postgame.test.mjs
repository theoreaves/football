import test from 'node:test';
import assert from 'node:assert/strict';
import {exitPose,handshakePose,mascotFarewell,POSTGAME_END} from '../../resources/js/practice/postgame.js';
import {FAN_SCALE} from '../../resources/js/practice/stadium-crowd.js';

test('postgame handshake approaches continuously, greets, and exits through each own tunnel',()=>{
    for(const side of ['home','away'])for(const kind of ['coach','qb']){
        const start={x:48,z:side==='home'?-4:57};
        const initial=handshakePose(side,0,start,0,kind);
        assert.equal(initial.x,start.x);assert.equal(initial.z,start.z);
        const greeting=handshakePose(side,0,start,14,kind);
        assert.ok(greeting.shake);assert.equal(greeting.visible,true);
        const gone=handshakePose(side,0,start,POSTGAME_END,kind);
        assert.equal(gone.visible,false);
        let previous=initial;
        for(let t=.025;t<POSTGAME_END;t+=.025){
            const pose=handshakePose(side,0,start,t,kind);
            assert.ok(Math.hypot(pose.x-previous.x,pose.z-previous.z)<.16);
            previous=pose;
        }
    }
});
test('exit has no teleport and mascot stays to wave after everyone else leaves',()=>{
    const start={x:100,z:27};
    assert.deepEqual([exitPose('home',0,start,0,14).x,exitPose('home',0,start,0,14).z],[100,27]);
    assert.equal(exitPose('home',0,start,60,14).visible,false);
    const farewell=mascotFarewell({x:35,z:-16},60);
    assert.equal(farewell.visible,true);assert.equal(farewell.wave,true);
    assert.equal(mascotFarewell({x:35,z:-16},POSTGAME_END).visible,false);
    assert.ok(FAN_SCALE>1 && FAN_SCALE<1.25);
});
