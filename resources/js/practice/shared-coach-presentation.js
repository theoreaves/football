// Shared actors and the ball belong to the host, never the disposable overlay.
// Hiding old Coach markers must not hide retained players during a camera move.
export function setCoachLayerVisible(layer, visible) {
    layer.visible = Boolean(visible);
}

export function prepareCoachBall(host) {
    const ball = host.ball;
    // Coach tracks are world coordinates; Arcade carries use a waist parent.
    if (ball.parent !== host.scene) host.scene.attach(ball);
    ball.scale.set(1.6, .85, .85);
    // The existing Coach sampler supplies the current position and spin below.
}
