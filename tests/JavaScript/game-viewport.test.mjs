import test from 'node:test';
import assert from 'node:assert/strict';
import {gameViewport} from '../../resources/js/practice/game-viewport.js';

test('visible viewport changes resize the stage in both orientations without touching dialogs', () => {
    const win = new EventTarget(), viewport = new EventTarget(), properties = new Map();
    win.visualViewport = viewport;win.innerHeight=844;viewport.height=780;
    const root={style:{setProperty:(k,v)=>properties.set(k,v),removeProperty:k=>properties.delete(k)}};
    const dispose=gameViewport(root,win);
    assert.equal(properties.get('--game-viewport-height'),'780px');
    viewport.height=340;viewport.dispatchEvent(new Event('resize'));
    assert.equal(properties.get('--game-viewport-height'),'340px');
    viewport.height=390;win.dispatchEvent(new Event('orientationchange'));
    assert.equal(properties.get('--game-viewport-height'),'390px');
    viewport.height=780;viewport.dispatchEvent(new Event('scroll'));
    assert.equal(properties.get('--game-viewport-height'),'780px');
    dispose();viewport.height=320;viewport.dispatchEvent(new Event('resize'));win.dispatchEvent(new Event('resize'));
    assert.equal(properties.size,0);
});

test('browsers without visualViewport use window height and ignore invalid measurements', () => {
    const win=new EventTarget(), properties=new Map();win.innerHeight=350;
    const dispose=gameViewport({style:{setProperty:(k,v)=>properties.set(k,v),removeProperty:k=>properties.delete(k)}},win);
    assert.equal(properties.get('--game-viewport-height'),'350px');
    win.innerHeight=0;win.dispatchEvent(new Event('resize'));assert.equal(properties.get('--game-viewport-height'),'350px');
    dispose();
});
