<?php

use Bnomei\Janitor;
use Bnomei\JanitorField;
use Kirby\Cms\Page;
use Kirby\Content\Field;

/*
janitor [noun]
one who keeps the premises of a building (such as an apartment or
office) clean, tends the heating system, and makes minor repairs
*/

Kirby::plugin('bnomei/janitor', [
    'options' => [
        'label.cooldown' => 2000, // ms
        'secret' => null,
        'commands.allow' => null,
        'commands.deny' => [],
        'public.commands' => [],
    ],
    'commands' => [ // https://github.com/getkirby/cli
        'janitor:backupzip' => require __DIR__.'/commands/backupzip.php',
        'janitor:call' => require __DIR__.'/commands/call.php',
        'janitor:cleancontent' => require __DIR__.'/commands/cleancontent.php',
        'janitor:clear' => require __DIR__.'/commands/flush.php', // alias for flush
        'janitor:clipboard' => require __DIR__.'/commands/clipboard.php',
        'janitor:download' => require __DIR__.'/commands/download.php',
        'janitor:flush' => require __DIR__.'/commands/flush.php',
        'janitor:job' => require __DIR__.'/commands/job.php',
        'janitor:maintenance' => require __DIR__.'/commands/maintenance.php',
        'janitor:open' => require __DIR__.'/commands/open.php',
        'janitor:out' => require __DIR__.'/commands/out.php',
        'janitor:pipe' => require __DIR__.'/commands/pipe.php',
        'janitor:render' => require __DIR__.'/commands/render.php',
        'janitor:thumbs' => require __DIR__.'/commands/thumbs.php',
        'janitor:tinker' => require __DIR__.'/commands/tinker.php',
        'janitor:trash' => require __DIR__.'/commands/trash.php',
        'janitor:undertaker' => require __DIR__.'/commands/undertaker.php',
    ],
    'fields' => [
        'janitor' => JanitorField::class,
    ],
    'hooks' => [
        'system.loadPlugins:after' => function () {
            Janitor::registerCommandPermissions();
        },
        // maintenance
        'route:before' => function () {
            if (Janitor::requestBlockedByMaintenance() &&
                F::exists(kirby()->roots()->index().'/.maintenance')) {
                snippet('maintenance');
                exit;
            }
        },
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => 'plugin-janitor/(:all)', // using (:all) fixes issues with kirbys routing for : and /
                'action' => function (string $command) {
                    $janitor = Janitor::singleton();
                    $command = urldecode($command);

                    if ($janitor->canDispatchCommand($command) === false) {
                        return [
                            'status' => 403,
                        ];
                    }

                    return $janitor->command($command);
                },
            ],
            [
                'pattern' => 'plugin-janitor',
                'method' => 'POST',
                'action' => function () {
                    $janitor = Janitor::singleton();
                    $command = get('command');
                    if (! is_string($command)) {
                        return [
                            'status' => 200,
                            'info' => 'no command given',
                        ];
                    }

                    if ($janitor->canDispatchCommand($command) === false) {
                        return [
                            'status' => 403,
                        ];
                    }

                    $view = get('view');

                    return $janitor->command($command, is_string($view) ? $view : null);
                },
            ],
            [
                'pattern' => 'plugin-janitor',
                'method' => 'GET',
                'action' => function () {
                    return [
                        'status' => 200,
                        'info' => 'no command given',
                    ];
                },
            ],
        ],
    ],
    'routes' => [
        [
            'pattern' => 'plugin-janitor/(:any)/(:all)', // using (:all) fixes issues with kirbys routing for : and /
            'action' => function (string $secret, string $command) {
                return janitor()->webhook($secret, urldecode($command));
            },
        ],
        [
            'pattern' => 'plugin-janitor/(:any)',
            'method' => 'POST',
            'action' => function (string $secret) {
                return janitor()->webhook($secret, get('command'));
            },
        ],
    ],
    'fieldMethods' => [
        'ecco' => function ($field, string $a, string $b = ''): string {
            if ($field->isEmpty()) {
                return $b;
            }

            return empty($field->value()) || strtolower($field->value()) === 'false' ? $b : $a;
        },
    ],
    'siteMethods' => [
        'isUnderMaintenance' => function (): Field {
            return new Field(null, 'isUnderMaintenance', F::exists(kirby()->roots()->index().'/.maintenance'));
        },
    ],
    'snippets' => [
        'maintenance' => __DIR__.'/snippets/maintenance.php',
    ],
    'translations' => [
        'en' => [
            // defined inline as fallbacks
        ],
        'de' => [
            'janitor.cleancontent.message' => '{{ updated }} / {{ count }} bereinigt',
            'janitor.maintenance.label' => 'Website ist {{ status }}',
            'janitor.maintenance.down' => 'im Wartungs-Modus',
            'janitor.maintenance.up' => 'online',
            'janitor.maintenance.notice' => 'Diese Webseite ist bald wieder online.', // in snippet
            'janitor.render.message' => '{{ count }} Seiten gerendert, {{ failed }} fehlgeschlagen, {{ thumbs }} Vorschaubilder gefunden',
        ],
    ],
]);

if (! function_exists('janitor')) {
    function janitor(): Janitor
    {
        return Janitor::singleton();
    }
}

if (! function_exists('undertaker')) {
    function undertaker(Page $page): array
    {
        return janitor()->command(implode(' ', [
            'janitor:undertaker',
            '--page '.Janitor::modelIdentifier($page),
            '--user '.kirby()->user()?->id(),
            '--quiet',
        ]));
    }
}
