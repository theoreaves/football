import test from 'node:test';
import assert from 'node:assert/strict';
import {sampleHuddle,sampleBreakHuddle} from '../../resources/js/practice/huddle.js';

for(const possession of ['home','away'])for(const homeDirection of [1,-1]) {
    test(`defenders wait near the next line facing offense: ${possession}, direction ${homeDirection}`,()=>{
        const side=possession==='home'?'away':'home';
        const direction=possession==='home'?homeDirection:-homeDirection;
        const roles=['DE1','DT1','DT2','DE2','LB1','LB2','LB3','CB1','CB2','S1','S2'];
        const formation={players:roles.map((role,id)=>({id,role,side,x:70+direction,z:10+id*3})),ball:{x:70,y:1,z:26.7}};
        const snapshot=JSON.stringify(formation);
        const start=sampleHuddle(formation,70,possession,0,homeDirection);
        const mid=sampleHuddle(formation,70,possession,.5,homeDirection);
        const end=sampleHuddle(formation,70,possession,1,homeDirection);
        end.players.forEach((p,i)=>{
            const depth=(p.x-70)*direction;
            assert.ok(depth>.5 && depth<=6);
            if(i<4)assert.ok(depth<1);
            assert.ok((p.facingX-p.x)*direction<0);
            assert.equal(start.players[i].x,formation.players[i].x);
            assert.ok(Math.abs(mid.players[i].x-(formation.players[i].x+p.x)/2)<1e-9);
        });
        assert.equal(new Set(end.players.map(p=>`${p.x},${p.z}`)).size,11);
        const breaking=sampleBreakHuddle(formation,70,possession,0,homeDirection);
        assert.deepEqual(breaking.players.map(p=>[p.x,p.z]),end.players.map(p=>[p.x,p.z]));
        assert.equal(JSON.stringify(formation),snapshot);
    });
}
