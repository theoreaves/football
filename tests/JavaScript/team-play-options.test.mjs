import test from 'node:test';
import assert from 'node:assert/strict';
import {teamPlayOptions,mountTeamPlaySelections} from '../../resources/js/practice/team-play-options.js';

const homePlay={id:'home-variation',kind:'offense',formation:'goal_line',call:'power',name:'Heavy Push'};
const awayPlay={id:'away-variation',kind:'defense',formation:'nickel',call:'zone',expect:'pass',blitz:true,name:'Hands Defense'};

test('named plays belong only to the current team unit and formation',()=>{
    const books={home:{h:homePlay},away:{a:awayPlay}};
    assert.deepEqual(teamPlayOptions(books,'home','offense','goal_line'),[homePlay]);
    assert.deepEqual(teamPlayOptions(books,'away','defense','nickel'),[awayPlay]);
    assert.deepEqual(teamPlayOptions(books,'home','defense','nickel'),[]);
    assert.deepEqual(teamPlayOptions(books,'home','offense','shotgun'),[]);
    assert.deepEqual(teamPlayOptions({},'home','offense','goal_line'),[]);
});

test('selecting a named variation submits its ID with the real call and defense profile',()=>{
    const original=globalThis.document;
    class Field extends EventTarget {constructor(value=''){super();this.value=value;this.checked=false;}}
    const fields={call:new Field('inside_run'),defense:new Field('man_to_man'),offense_formation:new Field('goal_line'),defense_formation:new Field('nickel'),expect:new Field('balanced'),blitz:new Field('1')};
    const form={elements:{namedItem:name=>fields[name]},append:input=>{fields[input.name]=input;},querySelector:()=>null};
    globalThis.document={createElement:()=>new Field()};
    try {
        const selections=mountTeamPlaySelections({dataset:{teamPlays:JSON.stringify({home:{h:homePlay},away:{a:awayPlay}}),afterState:'{"possession":"home"}'}},form);
        selections.choose(homePlay);selections.choose(awayPlay);
        assert.equal(fields.call.value,'power');assert.equal(fields['team_play[home]'].value,homePlay.id);
        assert.equal(fields.defense.value,'zone');assert.equal(fields.expect.value,'pass');assert.equal(fields.blitz.checked,true);
        assert.equal(fields['team_play[away]'].value,awayPlay.id);
        assert.deepEqual(selections.selected(),[homePlay,awayPlay]);
        fields.defense_formation.dispatchEvent(new Event('change'));
        assert.equal(fields['team_play[away]'].value,'');assert.equal(selections.current('defense'),undefined);
        assert.equal(fields['team_play[home]'].value,homePlay.id);
        fields.call.dispatchEvent(new Event('change'));
        assert.equal(fields['team_play[home]'].value,'');
    } finally {globalThis.document=original;}
});

test('team identities switch with possession while the saved playbook remains intact',()=>{
    const original=globalThis.document;
    class Field extends EventTarget {constructor(value=''){super();this.value=value;}}
    const fields={call:new Field(),defense:new Field(),offense_formation:new Field('goal_line'),defense_formation:new Field('nickel')};
    const form={elements:{namedItem:name=>fields[name]},append:input=>{fields[input.name]=input;},querySelector:()=>null};
    globalThis.document={createElement:()=>new Field()};
    try {
        const books={away:{h:homePlay},home:{a:awayPlay}},source=JSON.stringify(books);
        const selections=mountTeamPlaySelections({dataset:{teamPlays:source,afterState:'{"possession":"away"}'}},form);
        assert.deepEqual(selections.options('offense'),[homePlay]);assert.deepEqual(selections.options('defense'),[awayPlay]);
        selections.choose(homePlay);assert.equal(fields['team_play[away]'].value,homePlay.id);
        assert.equal(JSON.stringify(books),source);
    } finally {globalThis.document=original;}
});

test('named ordinary plays are absent during kick units and conversion phases',()=>{
    const original=globalThis.document;
    class Field extends EventTarget {constructor(value=''){super();this.value=value;}}
    globalThis.document={createElement:()=>new Field()};
    try {
        for (const [phase,call] of [['kickoff','kickoff'],['extra_point','two_point_run'],['scrimmage','fake_punt_run'],['scrimmage','field_goal']]) {
            const fields={call:new Field(call),offense_formation:new Field('goal_line')};
            const form={elements:{namedItem:name=>fields[name]},append(){},querySelector:()=>null};
            const choices=mountTeamPlaySelections({dataset:{teamPlays:JSON.stringify({home:{h:homePlay}}),afterState:JSON.stringify({possession:'home',phase})}},form);
            assert.deepEqual(choices.options('offense'),[]);
        }
    } finally {globalThis.document=original;}
});
