import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {applyAnimationVariety,tackleFallAngle} from '../../resources/js/practice/animation-variety.js';
function model(){const mesh=new THREE.Group();for(const key of ['legs','knees','arms','elbows'])mesh.userData[key]=[new THREE.Group(),new THREE.Group()];mesh.userData.waist=new THREE.Group();return mesh;}
function pose(mesh){return ['legs','knees','arms','elbows'].flatMap(key=>mesh.userData[key].flatMap(joint=>joint.rotation.toArray().slice(0,3))).concat(mesh.userData.waist.rotation.x);}

test('five handling and four finishing options produce twenty distinct pose sequences per family',()=>{
    for(const family of ['run','catch','incomplete','scramble']){
        const signatures=[];
        for(let handling=0;handling<5;handling++)for(let finish=0;finish<4;finish++){
            const mesh=model(),player={team:'offense',role:family==='scramble'?'QB':family==='run'?'RB':'WR1'};
            const animation={carrier:player.role,receiver_role:['run','scramble'].includes(family)?null:player.role,catch_at:3,contact_at:family==='incomplete'?null:5,duration:6,variety:{version:1,family,handling,finish}};
            applyAnimationVariety(mesh,animation,player,'play',family==='scramble'?3:family==='run'?2:3);
            const early=pose(mesh);
            applyAnimationVariety(mesh,animation,player,'play',5.7);
            signatures.push(JSON.stringify([early,pose(mesh)]));
            assert.deepEqual(mesh.position.toArray(),[0,0,0]);
        }
        assert.equal(new Set(signatures).size,20,family);
    }
    assert.equal(new Set(['wrap','side','lunge','drive'].map(tackleFallAngle)).size,4);
});

test('legacy replays other players and presnap poses remain untouched',()=>{
    for(const [animation,player,phase] of [[{}, {team:'offense',role:'RB'},'play'],[{variety:{version:1,handling:2,finish:1},carrier:'RB'}, {team:'defense',role:'LB2'},'play'],[{variety:{version:1,handling:2,finish:1},carrier:'RB'}, {team:'offense',role:'RB'},'set']]){
        const mesh=model(),before=pose(mesh);applyAnimationVariety(mesh,animation,player,phase,3);assert.deepEqual(pose(mesh),before);
    }
});

test('scrambles keep the pocket pose and never use a receiving reach',()=>{
    const animation={carrier:'QB',receiver_role:null,catch_at:3.8,contact_at:5.3,variety:{version:1,family:'scramble',handling:3,finish:2}};
    const player={team:'offense',role:'QB'};
    const mesh=model(),before=pose(mesh);
    applyAnimationVariety(mesh,animation,player,'play',1.55);
    assert.deepEqual(pose(mesh),before);
    applyAnimationVariety(mesh,animation,player,'play',3.8);
    assert.equal(mesh.userData.arms[1].rotation.x,0);
    assert.equal(mesh.userData.arms[0].rotation.x,-.67+.08);
    assert.equal(mesh.userData.elbows[0].rotation.x,-1.3+.09);
});

function expandedFixture(family,scenario='carry'){
    const defense=['interception','fumble','defensive_return'].includes(family) || scenario==='return';
    const player={team:defense?'defense':'offense',role:defense?'CB1':family==='sack'||scenario==='kick'?'QB':'RB'};
    const animation={carrier:player.role,contact_carrier_team:player.team,contact_at:scenario==='contact'?5.3:null,
        duration:6,result_at:5.3,events:[[1.2,'Kick']],ballHolders:[[0,'offense','C'],[.35,'offense','QB'],[2.2,null,null],[3.8,player.team,player.role]],
        variety:{version:1,family,pose_only:true},ball:[[0,1,1,1],[6,10,1,1]]};
    return {animation,player};
}

test('expanded families render 100 distinct articulated sequences with roots and ball data unchanged',()=>{
    for(const [family,scenario] of [['sack','contact'],['touchdown','carry'],['safety','contact'],['interception','carry'],['fumble','carry'],['defensive_return','carry'],['sideline','carry'],
        ['special_punt_return','return'],['special_punt_return_touchdown','return'],['special_punt_fair_catch','return'],['special_punt_touchback','kick'],['special_punt_blocked','kick'],
        ['special_kickoff_return','return'],['special_kickoff_return_touchdown','return'],['special_kickoff_touchback','kick'],
        ['special_field_goal_good','kick'],['special_field_goal_missed','kick'],['special_field_goal_blocked','kick'],
        ['special_extra_point_good','kick'],['special_extra_point_missed','kick'],['special_extra_point_blocked','kick'],['special_onside_recovered','carry'],['special_onside_failed','return']]){
        const signatures=[];
        for(let id=0;id<100;id++){
            const {animation,player}=expandedFixture(family,scenario);
            animation.variety={...animation.variety,id,movement:id%5,handling:Math.floor(id/5)%5,finish:Math.floor(id/25)};
            if(family.startsWith('special_onside'))animation.declared_onside=true;
            const original=JSON.stringify(animation),sequence=[];
            for(const time of scenario==='kick'?[1.6,1.9,2.2]:[4.3,4.8,5.7,6]){
                const mesh=model();
                applyAnimationVariety(mesh,animation,player,'play',time);
                sequence.push(pose(mesh));
                assert.deepEqual(mesh.position.toArray(),[0,0,0]);
                assert.deepEqual(mesh.rotation.toArray(),[0,0,0,'XYZ']);
            }
            assert.equal(JSON.stringify(animation),original);
            signatures.push(JSON.stringify(sequence));
        }
        assert.equal(new Set(signatures).size,100,family);
    }
});

test('expanded poses respect recovery kick-contact and result recovery boundaries',()=>{
    for(const family of ['interception','fumble','defensive_return']){
        const {animation,player}=expandedFixture(family);
        animation.variety={...animation.variety,movement:3,handling:4,finish:2};
        for(const time of [1,2.2,3.79,3.9]){
            const mesh=model(),before=pose(mesh);
            applyAnimationVariety(mesh,animation,player,'play',time);
            assert.deepEqual(pose(mesh),before,`${family} ${time}`);
        }
        const wrong=model(),before=pose(wrong);
        applyAnimationVariety(wrong,animation,{team:'offense',role:player.role},'play',4.5);
        assert.deepEqual(pose(wrong),before);
        const reset=model(),original=pose(reset);
        applyAnimationVariety(reset,animation,player,'result',6,2);
        assert.deepEqual(pose(reset),original);
    }
    const {animation,player}=expandedFixture('special_field_goal_good','kick');
    animation.variety={...animation.variety,movement:2,handling:3,finish:2};
    for(const time of [0,1.1,1.2,1.31]){
        const mesh=model(),before=pose(mesh);
        applyAnimationVariety(mesh,animation,player,'play',time);
        assert.deepEqual(pose(mesh),before);
    }
});

test('sacks preserve the pocket set and scoring pass poses wait for the actual receiver',()=>{
    const sack=expandedFixture('sack','contact');
    sack.animation.variety={...sack.animation.variety,movement:3,handling:4,finish:2};
    for(const time of [.8,1.3,1.55]){
        const mesh=model(),before=pose(mesh);
        applyAnimationVariety(mesh,sack.animation,sack.player,'play',time);
        assert.deepEqual(pose(mesh),before);
    }
    const scoring=expandedFixture('touchdown');
    scoring.animation.variety={...scoring.animation.variety,movement:3,handling:4,finish:2};
    const mesh=model(),before=pose(mesh);
    applyAnimationVariety(mesh,scoring.animation,{team:'offense',role:'QB'},'play',1.5);
    assert.deepEqual(pose(mesh),before);
});

test('long punt returns use their saved pickup time and scoring finishes appear during celebration',()=>{
    const {animation,player}=expandedFixture('special_punt_return','return');
    animation.ballHolders=[[0,'offense','C'],[1.2,null,null],[7.4,'defense','CB1']];
    animation.result_at=10;
    animation.variety={...animation.variety,movement:4,handling:3,finish:2};
    const early=model(),before=pose(early);
    applyAnimationVariety(early,animation,player,'play',6);
    assert.deepEqual(pose(early),before);
    const running=model();
    applyAnimationVariety(running,animation,player,'play',8);
    assert.notDeepEqual(pose(running),before);
    const scoring=expandedFixture('touchdown');
    const finishes=[];
    for(let finish=0;finish<4;finish++){
        scoring.animation.variety={...scoring.animation.variety,movement:1,handling:2,finish};
        const mesh=model();
        applyAnimationVariety(mesh,scoring.animation,scoring.player,'celebration',6,.6);
        finishes.push(JSON.stringify(pose(mesh)));
    }
    assert.equal(new Set(finishes).size,4);
});
