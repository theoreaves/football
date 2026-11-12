import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
test('Arcade entries use separate literal dynamic imports so Vite emits both production entries',()=>{
 const source=readFileSync(new URL('../../resources/js/app.js',import.meta.url),'utf8');
 assert.match(source,/\? import\('\.\/arcade\/persistent-exhibition\.js'\) : import\('\.\/arcade\/exhibition\.js'\)/);
 assert.doesNotMatch(source,/import\(exhibition\.dataset/);
});
