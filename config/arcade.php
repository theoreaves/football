<?php

$speedScale = env('ARCADE_SPEED_SCALE', 0.925);

return [
    'session_ttl' => 180,
    'max_request_bytes' => 4194304,
    'enabled' => env('ARCADE_ENABLED', false),
    'speed_scale' => is_numeric($speedScale) && is_finite((float) $speedScale)
        ? max(0.75, min(1.1, (float) $speedScale))
        : 0.925,
];
