<?php

use Bnomei\Janitor;
use Kirby\CLI\CLI;

return [
    'description' => 'Send random notification to the Panel',
    'args' => [] + Janitor::ARGS, // page, file, user, site, data, model
    'command' => static function (CLI $cli): void {
        janitor()->data($cli->arg('command'), [
            'status' => 200,
            'notification' => [
                rand(0, 1) ? 'success' : 'error',
                $cli->arg('model'),
            ],
        ]);
    },
];
