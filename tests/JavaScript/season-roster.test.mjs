import test from 'node:test';
import assert from 'node:assert/strict';
import { rosterMatches } from '../../resources/js/season-roster.js';

test('roster filters combine partial case insensitive names with exact positions', () => {
 assert.equal(rosterMatches('Theo Reaves', 'QB', ' REAV ', 'QB'), true);
 assert.equal(rosterMatches('Theo Reaves', 'QB', 'theo', 'RB'), false);
 assert.equal(rosterMatches('Theo Reaves', 'QB', 'Smith', ''), false);
 assert.equal(rosterMatches('Theo Reaves', 'QB', '', ''), true);
});

test('successful embedded saves close the editor but validation failures and standalone pages stay open', async () => {
 const {closeSavedPlayerEditor} = await import('../../resources/js/season-roster.js');
 const messages=[];
 const window={parent:{postMessage:(...args)=>messages.push(args)},location:{origin:'https://football.test'}};
 closeSavedPlayerEditor({querySelector:()=>null},window);
 assert.equal(messages.length,0);
 closeSavedPlayerEditor({querySelector:()=>({})},window);
 assert.deepEqual(messages,[[{type:'football:close-player-editor'},'https://football.test']]);
 window.parent=window;
 closeSavedPlayerEditor({querySelector:()=>({})},window);
 assert.equal(messages.length,1);
});
