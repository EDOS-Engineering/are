<?php

// Spike #177 (not for main): the production-like environment both scripts
// run in. Set before the app boots, so Dotenv does not override it.
$spikeEnv = [
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'LOG_CHANNEL' => 'null',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => dirname(__DIR__).'/storage/spike-177.sqlite',
    'SESSION_DRIVER' => 'database',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'BROADCAST_CONNECTION' => 'log',
    'TWITCH_CHANNEL_ID' => '1000',
    'TWITCH_BROADCASTER_IDS' => '',
    'DEBUGBAR_ENABLED' => 'false',
];

foreach ($spikeEnv as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
