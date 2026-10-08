import test from 'node:test';
import assert from 'node:assert/strict';
import { submissionUrl } from '../../resources/js/practice/game-navigation.js';

test('penalty and other action inputs cannot replace the submission URL', () => {
    const form = {
        action: { name: 'action', value: 'penalty' },
        getAttribute: name => name === 'action' ? '/exhibitions/42/play' : null,
    };
    assert.equal(submissionUrl(form, 'https://football.example/exhibitions/42?watch=1').href,
        'https://football.example/exhibitions/42/play');
});

test('a form without an action submits to the current page', () => {
    const url = 'https://football.example/exhibitions/42';
    assert.equal(submissionUrl({ getAttribute: () => null }, url).href, url);
});
