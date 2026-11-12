<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Independent process and synthetic recording tuples only: no application/database.
$frame = [1, 16667, 0, array_fill(0, 22, [8210, 2670, 0, 100, 0, 0, 1]), [8210, 90, 2670, 0, 0]];
$json = json_encode(array_fill(0, 13, ['recording' => ['frames' => array_fill(0, 180, $frame)]]), JSON_THROW_ON_ERROR);
unset($frame);
$history = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
unset($json);
$before = memory_get_usage(true);
$hash = App\Services\Arcade\ArcadeContext::hash($history);
echo json_encode(['hash' => $hash, 'extra_peak' => memory_get_peak_usage(true) - $before], JSON_THROW_ON_ERROR);
