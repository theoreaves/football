import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
test('creation selector offers mode default without overriding explicit durations or relying on click JavaScript',()=>{
 const source=readFileSync(new URL('../../resources/views/exhibitions/index.blade.php',import.meta.url),'utf8');
 assert.match(source,/config\('arcade.enabled'\) \? 'default' : 180/);
 assert.match(source,/value="default"[^>]*.*Mode default · Coach 3 minutes \/ Play 15 minutes/);
 for(const seconds of [180,300,600,900])assert.ok(source.includes(`value="${seconds}" @selected((string) old('quarter_length', $defaultQuarterLength) === '${seconds}')`));
 assert.ok(source.includes('name="play_mode" value="1"'));
});
