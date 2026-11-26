<?php

declare(strict_types=1);

use Kirby\CLI\CLI;

return [
    'description' => 'Flush a cache',
    'args' => [
        'name' => [
            'prefix' => 'n',
            'longPrefix' => 'name',
            'description' => 'Name of the cache',
            'defaultValue' => 'pages',
        ],
    ],
    'command' => static function (CLI $cli): void {
        $name = $cli->arg('name');
        $name = empty($name) ? 'pages' : $name;

        $cli->kirby()->cache($name)->flush();

        $cli->success('The cache "'.$name.'" has been cleared.');

        janitor()->data($cli->arg('command'), [
            'status' => 200,
        ]);
    },
];
