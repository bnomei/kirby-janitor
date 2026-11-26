<?php

declare(strict_types=1);

use Bnomei\Janitor;
use Kirby\CLI\CLI;
use Kirby\Cms\User;

return [
    'description' => 'Prints text to the terminal',
    'args' => [
        'msg' => [
            'longPrefix' => 'msg',
            'description' => 'Message',
            'defaultValue' => '',
        ],
        'level' => [
            'prefix' => 'l',
            'longPrefix' => 'level',
            'description' => 'Message level like out/success/error',
            'defaultValue' => 'out',
        ],
        'time' => [
            'prefix' => 't',
            'longPrefix' => 'time',
            'description' => 'Prefix message with timestamp',
            'noValue' => true,
        ],
    ] + Janitor::ARGS, // page, file, user, site, data, model
    'command' => static function (CLI $cli): void {
        $time = $cli->arg('time') ? '['.date('Y-m-d H:i:s').'] ' : '';
        $level = match ($cli->arg('level')) {
            'success' => 'success',
            'error' => 'error',
            default => 'out',
        };
        $user = Janitor::resolveModel($cli->arg('user'));
        $user = $user instanceof User ? ' ('.$user->email().') ' : '';
        $msg = sprintf('%s%s%s', $time, $user, Janitor::query($cli->arg('msg').$cli->arg('data'), Janitor::resolveModel($cli->arg('model'))));

        $cli->{$level}($msg);

        janitor()->data($cli->arg('command'), [
            'status' => 200,
            'message' => $msg,
        ]);
    },
];
