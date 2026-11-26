<?php

declare(strict_types=1);

use Bnomei\Janitor;
use Bnomei\JanitorDownload;
use Kirby\CLI\CLI;

return [
    'description' => 'Pipe `data` to `download` arg in Janitor or download valid http(s) URLs on CLI via wget',
    'args' => [
        'output' => [
            'prefix' => 'o',
            'longPrefix' => 'output',
            'description' => 'output filename',
            'defaultValue' => '',
            'castTo' => 'string',
        ],
    ] + Janitor::ARGS, // page, file, user, site, data, model
    'command' => static function (CLI $cli): void {
        if (PHP_SAPI === 'cli' && ! JanitorDownload::run($cli->arg('data'), $cli->arg('output'))) {
            Janitor::finish($cli, ['status' => 500, 'error' => 'Download failed. Check the URL, destination and wget.']);

            return;
        }

        $cli->success('download => '.$cli->arg('data'));
        janitor()->data($cli->arg('command'), [
            'status' => 200,
            // urls forwarded to janitor in `download` will trigger a download in panel.
            'download' => $cli->arg('data'),
        ]);
    },
];
