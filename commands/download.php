<?php

declare(strict_types=1);

if (! class_exists('Bnomei\Janitor')) {
    require_once __DIR__.'/../classes/Janitor.php';
}

if (! class_exists('Bnomei\JanitorDownload')) {
    require_once __DIR__.'/../classes/JanitorDownload.php';
}

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
        $cli->success('download => '.$cli->arg('data'));

        if (php_sapi_name() === 'cli') {
            $arguments = JanitorDownload::wgetArguments($cli->arg('data'), $cli->arg('output'));

            if ($arguments !== null) {
                JanitorDownload::run($arguments);
            }
        }

        janitor()->data($cli->arg('command'), [
            'status' => 200,
            // urls forwarded to janitor in `download` will trigger a download in panel.
            'download' => $cli->arg('data'),
        ]);
    },
];
