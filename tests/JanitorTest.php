<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Bnomei\Janitor;
use Bnomei\JanitorDownload;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\User;

test('singleton', function () {
    // create
    $janitor = Janitor::singleton();
    expect($janitor)->toBeInstanceOf(Janitor::class);

    // from static cached
    $janitor = Janitor::singleton();
    expect($janitor)->toBeInstanceOf(Janitor::class);
});

test('option', function () {
    $janitor = new Janitor([
        'debug' => true,
        'secret' => function () {
            return 'secret';
        },
    ]);

    expect($janitor->option('debug'))->toBeTrue();
    expect($janitor->option('secret') === 'secret')->toBeTrue();

    expect($janitor->option())->toBeArray();
});

test('public route secrets require strict non-empty string matches', function () {
    expect(Janitor::matchesSecret('secret', 'secret'))->toBeTrue()
        ->and(Janitor::matchesSecret('secret', 'other'))->toBeFalse()
        ->and(Janitor::matchesSecret('0e12345', '0e99999'))->toBeFalse()
        ->and(Janitor::matchesSecret(null, ''))->toBeFalse()
        ->and(Janitor::matchesSecret('', ''))->toBeFalse()
        ->and(Janitor::matchesSecret(12345, '12345'))->toBeFalse()
        ->and(Janitor::matchesSecret(function () {
            return 'secret';
        }, 'secret'))->toBeFalse();
});

test('command names are normalized for permission checks', function () {
    expect(Janitor::commandName('janitor:download --data "https://example.com/file.zip" --quiet'))->toBe('janitor:download')
        ->and(Janitor::commandPermissionCandidates('janitor:download --data "https://example.com/file.zip"'))->toBe([
            'commands.*',
            'commands.janitor.*',
            'commands.janitor.download',
        ])
        ->and(Janitor::commandPermissionCandidates('custom:cache:flush'))->toBe([
            'commands.*',
            'commands.custom.*',
            'commands.custom.cache.*',
            'commands.custom.cache.flush',
        ]);
});

test('command permissions support wildcards and specific overrides', function () {
    expect(Janitor::commandAllowedByPermissions('janitor:download', [
        'commands.*' => true,
        'commands.janitor.download' => false,
    ]))->toBeFalse()
        ->and(Janitor::commandAllowedByPermissions('janitor:pipe', [
            'commands.*' => true,
            'commands.janitor.download' => false,
        ]))->toBeTrue()
        ->and(Janitor::commandAllowedByPermissions('janitor:download', [
            'commands.*' => false,
            'commands.janitor.*' => false,
            'commands.janitor.download' => true,
        ]))->toBeTrue()
        ->and(Janitor::commandAllowedByPermissions('janitor:download', [
            'commands' => [
                '*' => true,
                'janitor' => [
                    'download' => false,
                ],
            ],
        ]))->toBeFalse();
});

test('command allow and deny lists restrict dispatch by command name', function () {
    expect((new Janitor([
        'commands.allow' => null,
        'commands.deny' => ['janitor:download'],
    ]))->canDispatchCommand('janitor:download --data "https://example.com/file.zip"'))->toBeFalse()
        ->and((new Janitor([
            'commands.allow' => null,
            'commands.deny' => ['janitor:download'],
        ]))->canDispatchCommand('janitor:pipe --data hello'))->toBeTrue()
        ->and((new Janitor([
            'commands.allow' => [],
            'commands.deny' => [],
        ]))->canDispatchCommand('janitor:pipe --data hello'))->toBeFalse()
        ->and((new Janitor([
            'commands.allow' => ['janitor:*'],
            'commands.deny' => [],
        ]))->canDispatchCommand('janitor:pipe --data hello'))->toBeTrue()
        ->and((new Janitor([
            'commands.allow' => ['janitor:*'],
            'commands.deny' => [],
        ]))->canDispatchCommand('notify --data hello'))->toBeFalse();
});

test('public command dispatch requires an explicit allow list', function () {
    expect((new Janitor([
        'public.commands' => [],
    ]))->canDispatchCommand('janitor:thumbs --quiet', 'public'))->toBeFalse()
        ->and((new Janitor([
            'public.commands' => ['janitor:thumbs'],
        ]))->canDispatchCommand('janitor:thumbs --quiet', 'public'))->toBeTrue()
        ->and((new Janitor([
            'public.commands' => ['janitor:thumbs'],
        ]))->canDispatchCommand('janitor:backupzip --quiet', 'public'))->toBeFalse();
});

test('download command builds wget argv without shell expansion', function () {
    $target = sys_get_temp_dir().'/janitor-download-'.uniqid('', true);

    expect(JanitorDownload::wgetArguments(
        'https://example.com/file.zip;touch'.$target,
        $target.'; touch '.$target.'.pwned'
    ))->toBe([
        'wget',
        '-O',
        $target.'; touch '.$target.'.pwned',
        'https://example.com/file.zip;touch'.$target,
    ])
        ->and(JanitorDownload::wgetArguments('ftp://example.com/file.zip'))->toBeNull()
        ->and(JanitorDownload::wgetArguments('https://example.com/file.zip; touch '.$target))->toBeNull();
});

test('download command does not execute shell payloads from web-style command data', function () {
    $target = sys_get_temp_dir().'/janitor-download-'.uniqid('', true);
    $result = (new Janitor)->command('janitor:download --data "https://example.com/file.zip; touch '.$target.'" --output "'.$target.'" --quiet');

    expect($result['download'])->toBe('https://example.com/file.zip; touch '.$target)
        ->and(file_exists($target))->toBeFalse();
});

test('render command ignores Kirby changes folders', function () {
    $changes = kirby()->roots()->content().'/home/_changes';
    $changeFile = $changes.'/default.en.txt';
    $createdDirectory = is_dir($changes) === false;
    $previousContent = is_file($changeFile) ? file_get_contents($changeFile) : null;

    if ($createdDirectory) {
        mkdir($changes, 0777, true);
    }
    file_put_contents($changeFile, 'Title: Unsaved changes');

    try {
        $result = (new Janitor)->command('janitor:render --quiet');
    } finally {
        if ($previousContent !== null) {
            file_put_contents($changeFile, $previousContent);
        } elseif (is_file($changeFile)) {
            unlink($changeFile);
        }

        if ($createdDirectory && is_dir($changes)) {
            rmdir($changes);
        }
    }

    expect($result['status'])->toBe(200)
        ->and($result['count'])->toBe(2)
        ->and($result['renderFailed'])->toBe(0);
});

test('construct', function () {
    $janitor = new Janitor;
    expect($janitor)->toBeInstanceOf(Janitor::class);
});

test('job', function () {
    $janitor = new Janitor;
    expect($janitor->command('janitor:job  --key some.key.to.task --site --quiet')['status'])->toEqual(200);

    expect($janitor->command('janitor:job  --key some.key.to.task --site --data "some data" --quiet')['message'])->toEqual('site:// some data');
});

test('method', function () {
    $janitor = new Janitor;
    expect($janitor->command('janitor:call --method whoAmI --page page://vf0xqIlpU0ZlSorI --quiet')['status'])->toEqual(200);

    expect($janitor->command('janitor:call --method repeatAfterMe --data hello --page page://vf0xqIlpU0ZlSorI --quiet')['message'])->toEqual('Repeat after me: hello');

    expect($janitor->command('janitor:call --method nullberry --page page://vf0xqIlpU0ZlSorI --quiet')['status'])->toEqual(200);

    expect($janitor->command('janitor:call --method boolberry --page page://vf0xqIlpU0ZlSorI --quiet')['status'])->toEqual(204);
});

it('can resolve models', function () {
    kirby()->impersonate('kirby');
    $user = kirby()->users()->create([
        'email' => uniqid('test-', true).'@bnomei.com',
        'password' => 'password123',
    ]);
    $janitor = new Janitor;

    expect($janitor->model('page://vf0xqIlpU0ZlSorI'))->toBeInstanceOf(Page::class)
        ->and($janitor->model('site://'))->toBeInstanceOf(Site::class)
        ->and($janitor->model($user->uuid()->toString()))->toBeInstanceOf(User::class)
        ->and($janitor->model('file://u8X1ZJkCgi2z1vZT'))->toBeInstanceOf(File::class)
        ->and($janitor->model('home')->slug())->toBe('home')
        ->and($janitor->model('rubbish'))->toBeNull();

    if (kirby()->users()->count() > 1) {
        $user->delete();
    }
});

it('can check variables to be like `true`', function () {
    expect(Janitor::isTrue('true'))->toBeTrue()
        ->and(Janitor::isTrue(1))->toBeTrue()
        ->and(Janitor::isTrue('1'))->toBeTrue()
        ->and(Janitor::isTrue('yes'))->toBeTrue()
        ->and(Janitor::isTrue('on'))->toBeTrue()
        ->and(Janitor::isTrue('TRUE'))->toBeTrue()
        ->and(Janitor::isTrue(false))->toBeFalse()
        ->and(Janitor::isTrue('false'))->toBeFalse()
        ->and(Janitor::isTrue(0))->toBeFalse()
        ->and(Janitor::isTrue('0'))->toBeFalse()
        ->and(Janitor::isTrue('no'))->toBeFalse()
        ->and(Janitor::isTrue('off'))->toBeFalse()
        ->and(Janitor::isTrue('random string'))->toBeFalse()
        ->and(Janitor::isTrue('FALSE'))->toBeFalse();
});

it('can parse a query', function () {
    $home = page('home');
    kirby()->impersonate('kirby');
    $user = kirby()->users()->create([
        'email' => uniqid('test-', true).'@bnomei.com',
        'password' => 'password123',
    ]);

    expect(Janitor::query('Hello {{ kirby.version }}'))->toEqual('Hello '.kirby()->version())
        ->and(Janitor::query(null))->toEqual('')
        ->and(Janitor::query('URL {{ site.url }}', site()))->toEqual('URL '.kirby()->site()->url())
        ->and(Janitor::query('Title {{ page.title }}', $home))->toEqual('Title '.$home->title())
        ->and(Janitor::query('Email {{ user.email }}', $user))->toEqual('Email '.$user->email())
        ->and(Janitor::query('File {{ file.filename }}', $home->files()->first()))->toEqual('File '.$home->files()->first()->filename());

    if (kirby()->users()->count() > 1) {
        $user->delete();
    }
});

it('resolve queries in commands with a model', function () {
    $command = 'janitor:example --model page://vf0xqIlpU0ZlSorI --data "{( page.title )}" --example "{{ page.slug }}"';
    [$name, $args] = Janitor::parseCommand($command);
    $args = Janitor::resolveQueriesInCommand($args);

    expect($name)->toEqual('janitor:example')
        ->and($args)->toBe([
            '--model',
            'page://vf0xqIlpU0ZlSorI',
            '--data',
            'Home', // this was the instant replacement using {( page.title )}
            '--example',
            '{{ page.slug }}', // will be resolved after receiving the command
        ]);
});

it('resolve queries in commands without proving a model when executing', function () {
    $command = 'janitor:pipe --data "{( site.title )}" --to "message" --quiet';
    [$name, $args] = Janitor::parseCommand($command);
    $args = Janitor::resolveQueriesInCommand($args);

    expect($name)->toEqual('janitor:pipe')
        ->and($args)->toBe([
            '--data',
            'Janitor Tests',
            '--to',
            'message',
            '--quiet',
            '',
        ]);

    $result = (new Janitor)->command($command);
    expect($result['message'])->toEqual(kirby()->site()->title()->value());
});

it('can check if the current request should be blocked in mainteneace mode', function () {
    expect(Janitor::requestBlockedByMaintenance(site()->url()))->toBeTrue()
        ->and(Janitor::requestBlockedByMaintenance(page('home')->url()))->toBeTrue()
        ->and(Janitor::requestBlockedByMaintenance(kirby()->urls()->panel()))->toBeFalse()
        ->and(Janitor::requestBlockedByMaintenance(kirby()->urls()->api()))->toBeFalse()
        ->and(Janitor::requestBlockedByMaintenance(kirby()->urls()->media()))->toBeFalse();
});
