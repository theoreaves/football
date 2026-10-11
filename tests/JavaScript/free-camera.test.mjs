import test from 'node:test';
import assert from 'node:assert/strict';
import { captureCamera, restoreCamera, translateCameraAnchor, cameraLimits } from '../../resources/js/practice/camera-state.js';

test('free inspection stays at its absolute location across plays, possession and reload', () => {
    const position = [12, 3, -8], target = [10, 2, -4];
    const saved = captureCamera('free', position, target, [40, 0, 26.7], 1);
    assert.deepEqual(restoreCamera(saved, [85, 0, 26.7], -1), { mode: 'free', position, target });
    assert.deepEqual(translateCameraAnchor(position, target, [40, 0, 26.7], [85, 0, 26.7], 'free'), { position, target });
    assert.deepEqual(translateCameraAnchor(position, target, [40, 0, 26.7], [85, 0, 26.7]), { position: [57, 3, -8], target: [55, 2, -4] });
});

test('free camera rejects invalid stored coordinates and restores ordinary zoom limits', () => {
    for (const position of [[Infinity, 1, 2], [1, 2], [1000, 1, 2], ['1', 2, 3]]) {
        assert.equal(restoreCamera({ mode: 'free', position, target: [0, 0, 0] }, [0, 0, 0]), null);
    }
    assert.equal(cameraLimits('free').minDistance, 0.5);
    assert.equal(cameraLimits('broadcast').minDistance, 16);
    assert.equal(cameraLimits('quarterback').maxDistance, 180);
});
