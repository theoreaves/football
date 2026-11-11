import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('drill, exhibition and canvas help advertise target clicks while retaining drag aiming', () => {
    for (const path of ['resources/js/arcade/index.js', 'resources/js/arcade/view.js',
        'resources/views/arcade/drill.blade.php', 'resources/js/arcade/exhibition.js',
        'resources/views/arcade/exhibition.blade.php']) {
        const text = source(path);
        assert.match(text, /[Cc]lick[^.\n]*(target|throw)/, `${path}: click-to-throw is discoverable`);
        assert.match(text, /[Hh]old[^.\n]*drag/, `${path}: optional drag aiming remains documented`);
        assert.doesNotMatch(text, /QB CONTROL · DRAG TO AIM|Drag and release to pass|Drag → release: pass/);
    }
});
