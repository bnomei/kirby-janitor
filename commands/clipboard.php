<?php

declare(strict_types=1);

use Bnomei\Janitor;
use Kirby\CLI\CLI;

return [
    'description' => 'Pipe `data` to `clipboard` arg in Janitor or on CLI use pbcopy',
    'args' => [] + Janitor::ARGS, // page, file, user, site, data, model
    'command' => static function (CLI $cli): void {
        if (PHP_SAPI === 'cli') {
            $text = (string) $cli->arg('data');
            $process = function_exists('proc_open') ? @proc_open(['pbcopy'], [0 => ['pipe', 'r']], $pipes) : false;
            $copied = false;
            if (is_resource($process)) {
                $written = @fwrite($pipes[0], $text);
                fclose($pipes[0]);
                $copied = proc_close($process) === 0 && $written === strlen($text);
            }
            if (! $copied) {
                Janitor::finish($cli, ['status' => 500, 'error' => 'Copy failed. The terminal clipboard requires pbcopy.']);

                return;
            }
            $cli->success('Copied "'.$cli->arg('data').'" to your clipboard.');
        }

        janitor()->data($cli->arg('command'), [
            'status' => 200,
            // anything you forward to janitor in `clipboard` will be copied in panel.
            'clipboard' => $cli->arg('data'),
        ]);
    },
];
