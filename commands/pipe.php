<?php

declare(strict_types=1);

use Bnomei\Janitor;
use Kirby\CLI\CLI;

return [
    'description' => 'Pipe `data` to `pipe` arg in Janitor',
    'args' => [
        'to' => [
            'longPrefix' => 'to',
            'description' => 'String value of output to Janitor (like `href` or `clipboard`)',
            'required' => true,
        ],
    ] + Janitor::ARGS, // page, file, user, site, data, model
    'command' => static function (CLI $cli): void {
        $pipe = $cli->arg('to');
        $cli->success($pipe.' => '.$cli->arg('data'));

        janitor()->data($cli->arg('command'), [
            'status' => 200,
            $pipe => $cli->arg('data'),
        ]);
    },
];
