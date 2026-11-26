<?php

declare(strict_types=1);

use Bnomei\Janitor;
use Kirby\CLI\CLI;

return [
    'description' => 'Pipe `data` to `open` arg in Janitor',
    'args' => [] + Janitor::ARGS, // page, file, user, site, data, model
    'command' => static function (CLI $cli): void {
        $cli->success('open => '.$cli->arg('data'));

        janitor()->data($cli->arg('command'), [
            'status' => 200,
            // urls forwared to janitor in `open` will trigger a location.href change.
            // you can use `intab: true` in blueprint to open it in a new tab.
            'open' => $cli->arg('data'),
        ]);
    },
];
