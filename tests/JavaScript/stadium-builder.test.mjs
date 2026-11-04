import test from 'node:test';
import assert from 'node:assert/strict';
import {buildStadium,STADIUMS,stadiumDesign,stadiumTierColor,stadiumTierWallColor} from '../../resources/js/practice/stadium.js';
import {stadiumCopyFields,buildStadiumPreview} from '../../resources/js/stadium-builder.js';
import * as THREE from 'three';

function mockDocument(){const drawn=[];return {drawn,createElement:()=>({getContext:()=>({fillRect(){},strokeRect(){},fillText(text){drawn.push(text);}})})};}

test('legacy presets remain unchanged and bounded overrides adjust the real seating decks',()=>{
    for(const style of Object.keys(STADIUMS))assert.deepEqual(stadiumDesign({stadium_style:style}),STADIUMS[style]);
    assert.deepEqual(stadiumDesign({stadium:{tiers:99,rows:0,row_height:NaN}}),STADIUMS.classic_oval);
    assert.deepEqual(stadiumDesign({stadium:{tiers:'',rows:null,row_height:''}}),STADIUMS.classic_oval);
    const document=mockDocument(),home={stadium_style:'classic_oval',stadium_seat_color:'#aaaaaa',stadium:{name:'Theo Field',tiers:2,rows:4,row_height:1.5,one_color:false,tier_colors:{lower:'#123456',middle:'#abcdef',upper:'#fedcba'}}};
    const {group}=buildStadium(home,document,{}, {fullness:0});
    assert.equal(group.getObjectByName('seats-1').material.color.getHexString(),'123456');
    assert.equal(group.getObjectByName('seats-2').material.color.getHexString(),'abcdef');
    assert.equal(group.getObjectByName('seating-deck-3'),undefined);
    assert.equal(group.getObjectByName('seating-deck-1').children.filter(part=>part.name==='terrace').length,4);
    assert.ok(document.drawn.includes('Theo Field'));
    home.stadium.one_color=true;
    assert.equal(stadiumTierColor(home,1),'#aaaaaa');
    home.stadium.one_color='0';assert.equal(stadiumTierColor(home,2),'#fedcba');
});

test('copying carries structure and colors while retaining the receiving name and team artwork',()=>{
    const fields=stadiumCopyFields({stadium_style:'indoor_dome',stadium_seat_color:'#123456',stadium:{name:'Other Stadium',tiers:1,rows:6,row_height:1.1,one_color:false,tier_colors:{upper:'#abcdef'}},team_logo:'other.svg',endzone_text:'Other'});
    assert.equal(fields.stadium_style,'indoor_dome');assert.equal(fields['team_settings[stadium][tiers]'],1);
    assert.equal(fields['team_settings[stadium][one_color]'],false);
    assert.equal(fields['team_settings[stadium][tier_colors][lower]'],'#123456');
    assert.equal(fields['team_settings[stadium][tier_colors][upper]'],'#abcdef');
    assert.ok(Object.keys(fields).every(key=>key.startsWith('stadium_') || key.startsWith('team_settings[stadium]')));
    assert.ok(!Object.keys(fields).some(key=>key.includes('[name]') || key.includes('logo') || key.includes('endzone')));
    assert.equal(stadiumCopyFields({})['team_settings[stadium][rows]'],'');
});

test('structural preview builds finite geometry for minimum and maximum bowls with each roof style',()=>{
    for(const stadium_style of ['classic_oval','indoor_dome','retractable_roof','skyline'])for(const tiers of [1,3]){
        const model=buildStadiumPreview({stadium_style,stadium:{tiers,rows:12,row_height:1.75}},mockDocument());
        const bounds=new THREE.Box3().setFromObject(model);
        assert.ok([bounds.min.x,bounds.max.x,bounds.max.y,bounds.max.z].every(Number.isFinite));
        assert.ok(model.getObjectByName(`seats-${tiers}`));
        const fans=model.getObjectByName('stadium-crowd');fans.traverse(part=>{if(part.isInstancedMesh)assert.equal(part.count,0);});
    }
});


test('independent tier wall colors cover terraces risers and concourses and are copied',()=>{
    const home={stadium_wall_color:'#333333',stadium:{tiers:3,rows:4,wall_one_color:false,tier_wall_colors:{lower:'#123456',middle:'#abcdef',upper:'#fedcba'}}};
    const {group}=buildStadium(home,mockDocument(),{}, {fullness:0});
    const colors=['123456','abcdef','fedcba'];
    for(let i=0;i<3;i++){
        const deck=group.getObjectByName(`seating-deck-${i+1}`);
        assert.equal(deck.getObjectByName('terrace').material.color.getHexString(),colors[i]);
        assert.equal(deck.getObjectByName('riser').material.color.getHexString(),colors[i]);
        assert.equal(group.children.filter(part=>part.name==='concourse')[i].material.color.getHexString(),colors[i]);
    }
    const fields=stadiumCopyFields(home);
    assert.equal(fields['team_settings[stadium][wall_one_color]'],false);
    assert.equal(fields['team_settings[stadium][tier_wall_colors][upper]'],'#fedcba');
    home.stadium.wall_one_color=true;
    assert.equal(stadiumTierWallColor(home,2),'#333333');
    assert.equal(stadiumTierWallColor({stadium_wall_color:'#abcdef'},0),'#abcdef');
});

test('field preview displays independent artwork with fitted end zones and text fallbacks',()=>{
    const document=mockDocument(),loaded=[];
    const textureFor=(url,onLoad)=>{loaded.push(url);const texture=new THREE.Texture();texture.image={width:800,height:200};onLoad?.(texture);return texture;};
    const model=buildStadiumPreview({midfield_logo:'midfield.png',endzone_logo_left:'left.png',endzone_text:'THEO',endzone_transparent:true},document,textureFor);
    assert.deepEqual(loaded,['midfield.png','left.png']);
    assert.ok(model.getObjectByName('midfield-logo'));
    const left=model.getObjectByName('endzone-art-left');
    assert.ok(Math.abs(left.scale.x-28)<1e-10);assert.ok(Math.abs(left.scale.y-7)<1e-10);
    assert.equal(left.rotation.z,Math.PI/2);
    assert.equal(model.getObjectByName('endzone-text-right').rotation.z,-Math.PI/2);
    assert.ok(document.drawn.includes('THEO'));
    assert.equal(model.children.filter(part=>part.position.y===.025).length,0);
    const painted=buildStadiumPreview({endzone_background:'#123456'},mockDocument());
    assert.equal(painted.children.filter(part=>part.position.y===.025 && part.material.color.getHexString()==='123456').length,2);
});

test('fixture options change scoreboard placement and size lighting and tunnels in preview and games',()=>{
    for(const scoreboards of ['both','left','right'])for(const scoreboard_size of ['small','medium','large']){
        const home={primary_color:'#123456',stadium:{scoreboards,scoreboard_size,lighting:'rim',tunnels:false}};
        const game=buildStadium(home,mockDocument(),{}, {fullness:0}).group;
        const preview=buildStadiumPreview(home,mockDocument());
        for(const model of [game,preview]){
            const boards=[];model.traverse(part=>{if(part.name==='stadium-scoreboard')boards.push(part);});
            assert.equal(boards.length,scoreboards==='both'?2:1);
            assert.equal(boards[0].geometry.parameters.width,23*{small:.75,medium:1,large:1.35}[scoreboard_size]);
            if(scoreboards!=='both')assert.equal(Math.sign(boards[0].position.x),scoreboards==='left'?-1:1);
            assert.equal(model.getObjectByName('lighting-tower'),undefined);
            assert.ok(model.getObjectByName('rim-light'));
            assert.equal(model.getObjectByName('tunnel-home-roof'),undefined);
        }
    }
    const defaults=buildStadium({primary_color:'#123456'},mockDocument(),{primary_color:'#abcdef'},{fullness:0}).group;
    assert.ok(defaults.getObjectByName('lighting-tower'));
    assert.equal(defaults.getObjectByName('tunnel-home-roof').material.color.getHexString(),'123456');
    assert.equal(defaults.getObjectByName('tunnel-away-roof').material.color.getHexString(),'657587');
    const copied=stadiumCopyFields({stadium:{scoreboards:'right',scoreboard_size:'large',lighting:'rim',tunnels:false}});
    assert.equal(copied['team_settings[stadium][tunnels]'],false);
    assert.equal(copied['team_settings[stadium][lighting]'],'rim');
});

test('only the home tunnel carries a fitted team logo while visitor tunnel uses concrete',()=>{
    const texture=new THREE.Texture();texture.image={width:400,height:200};
    const {group}=buildStadium({primary_color:'#123456',team_logo:'home.png',stadium_wall_color:'#abcdef'},mockDocument(),{primary_color:'#ff0000',team_logo:'away.png'},{fullness:0},()=>texture);
    assert.ok(group.getObjectByName('tunnel-home-logo'));
    assert.equal(group.getObjectByName('away-tunnel-logo'),undefined);
    assert.equal(group.getObjectByName('tunnel-away-roof').material.color.getHexString(),'abcdef');
    const emblem=group.getObjectByName('tunnel-home-logo');emblem.onBeforeRender();assert.equal(emblem.rotation.x,-Math.PI/2);assert.ok(emblem.position.y>group.getObjectByName('tunnel-home-roof').position.y+.3);
    assert.ok(emblem.geometry.parameters.width/emblem.geometry.parameters.height-2<1e-8);
});


test('STAD2 separates exterior field and tunnel colors for all ten stadiums',()=>{
    for(const stadium_style of Object.keys(STADIUMS)) {
        const home={stadium_style,primary_color:'#123456',stadium_wall_color:'#abcdef',stadium:{exterior_color:'#998877',field_wall_color:'#112233',home_tunnel_color:'#223344',away_tunnel_color:'#334455'}};
        for(const group of [buildStadium(home,mockDocument(),{}, {fullness:0}).group,buildStadiumPreview(home,mockDocument())]) {
            assert.equal((group.getObjectByName('stadium-exterior-shell') || group.getObjectByName('dome-wall')).material.color.getHexString(),'998877');
            assert.equal(group.getObjectByName('field-retaining-wall').material.color.getHexString(),'112233');
            assert.equal(group.getObjectByName('terrace').material.color.getHexString(),'abcdef');
            assert.equal(group.getObjectByName('tunnel-home-roof').material.color.getHexString(),'223344');
            assert.equal(group.getObjectByName('tunnel-away-roof').material.color.getHexString(),'334455');
        }
    }
    const defaults=buildStadium({primary_color:'#123456'},mockDocument(),{}, {fullness:0}).group;
    assert.equal(defaults.getObjectByName('stadium-exterior-shell').material.color.getHexString(),'a8a29e');
    assert.equal(defaults.getObjectByName('field-retaining-wall').material.color.getHexString(),'123456');
});

test('STAD2 custom art replaces wordmarks and tunnel logos without altering entrances or geometry',()=>{
    const home={team_logo:'team.png',stadium:{field_wall_art:'wall.png',home_tunnel_art:'home.png',away_tunnel_art:'visitor.png'}};
    const loaded=[],textureFor=url=>{loaded.push(url);const texture=new THREE.Texture();texture.image={width:800,height:200};return texture;};
    const group=buildStadium(home,mockDocument(),{}, {fullness:0},textureFor).group;
    assert.deepEqual(loaded,['wall.png','home.png','visitor.png']);
    for(const side of ['home','away']) {
        const logo=group.getObjectByName(`tunnel-${side}-logo`);logo.onBeforeRender();
        assert.equal(logo.geometry.parameters.width,6);assert.equal(logo.geometry.parameters.height,1.5);
        assert.equal(logo.rotation.x,-Math.PI/2);
    }
    const baseline=buildStadium({},mockDocument(),{}, {fullness:0}).group;
    assert.deepEqual([...group.getObjectByName('field-retaining-wall').geometry.index.array],[...baseline.getObjectByName('field-retaining-wall').geometry.index.array]);
    for(const side of ['home','away'])assert.deepEqual(group.getObjectByName(`tunnel-${side}-roof`).position.toArray(),baseline.getObjectByName(`tunnel-${side}-roof`).position.toArray());
    assert.equal(baseline.getObjectByName('field-wall-wordmark').material.map.isCanvasTexture,true);
    const fields=stadiumCopyFields(home);
    assert.ok(!Object.keys(fields).some(key=>key.includes('_art')));
});
