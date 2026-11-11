<?php

$speedScale = env('ARCADE_SPEED_SCALE', 0.925);

return [
    'enabled' => env('ARCADE_ENABLED', false),
    'speed_scale' => is_numeric($speedScale) && is_finite((float) $speedScale)
        ? max(0.75, min(1.1, (float) $speedScale))
        : 0.925,
];
