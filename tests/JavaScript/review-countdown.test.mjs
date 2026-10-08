import test from 'node:test';
import assert from 'node:assert/strict';
import { reviewCountdown } from '../../resources/js/practice/play-wizard.js';

test('review waits five seconds and fires only once', () => {
    const timer = reviewCountdown();
    timer.start();
    assert.equal(timer.tick(2, true), 3);
    assert.equal(timer.tick(2.5, true), 1);
    assert.equal(timer.tick(.5, true), 0);
    assert.equal(timer.tick(1, true), null);
});

test('back or close cancels review and returning starts a fresh countdown', () => {
    const timer = reviewCountdown();
    timer.start();
    timer.tick(4, true);
    timer.cancel();
    assert.equal(timer.tick(10, true), null);
    timer.start();
    assert.equal(timer.tick(0, true), 5);
    assert.equal(timer.tick(20, false), null);
    assert.equal(timer.tick(1, true), 4);
});
