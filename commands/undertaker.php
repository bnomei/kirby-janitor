<?php

declare(strict_types=1);

if (! class_exists('Bnomei\Janitor')) {
    require_once __DIR__.'/../classes/Janitor.php';
}

use Bnomei\Janitor;
use Kirby\CLI\CLI;
use Kirby\Cms\User;
use Kirby\Filesystem\Dir;

return [
    'description' => 'Creates a Backup ZIP of a single page',
    'args' => [] + Janitor::ARGS, // page, file, user, site, data, model
    'command' => static function (CLI $cli): void {
        $page = $cli->kirby()->page($cli->arg('page'));

        $dir = realpath($cli->kirby()->roots()->accounts().'/../').'/graveyard';
        if (! Dir::exists($dir)) {
            Dir::make($dir);
        }

        /** @var User $user */
        $user = $cli->kirby()->user($cli->arg('user'));

        $filename = implode('-', [
            date('YmdHis'),
            str_replace('/', '+', $page->id()),
            $user->id(),
        ]).'.zip';

        $data = janitor()->command(implode(' ', [
            'janitor:backupzip',
            '--roots '.$page->root(),
            '--output '.$dir.'/'.$filename,
            '--quiet',
        ]));

        janitor()->data($cli->arg('command'), $data);
    },
];
