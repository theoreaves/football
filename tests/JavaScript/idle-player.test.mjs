import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {idlePlayerPose} from '../../resources/js/practice/idle-player.js';
import {periodSummary} from '../../resources/js/practice/break-summaries.js';
test('idle pose adds subtle breathing without moving field coordinates or disturbing fallen poses',()=>{
    const model=new THREE.Group();model.position.set(40,0,20);model.userData.arms=[new THREE.Group()];
    idlePlayerPose(model,2,1);assert.equal(model.position.x,40);assert.equal(model.position.z,20);assert.ok(Math.abs(model.position.y)<=.012);assert.ok(Math.abs(model.userData.arms[0].rotation.x)<=.035);
    model.rotation.z=1;const y=model.position.y;idlePlayerPose(model,3,1);assert.equal(model.position.y,y);
});
test('game team recap includes each quarter and excludes pending penalty stats',()=>{
    const plays=[1,2,3,4].map(quarter=>({before:{quarter,stats:{home:{plays:0,yards:0}},home_score:0,away_score:0},after:{stats:{home:{plays:1,yards:5}},home_score:3,away_score:0}}));
    plays.push({before:{quarter:4},after:{penalty_pending:true,stats:{home:{plays:1000}}}});
    const summary=periodSummary(plays,null);assert.equal(summary.home.plays,4);assert.equal(summary.home.yards,20);assert.equal(summary.home.points,12);
});
