<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Bnomei\Janitor;

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

test('construct', function () {
    $janitor = new Janitor();
    expect($janitor)->toBeInstanceOf(Janitor::class);
});

test('job', function () {
    $janitor = new Janitor();
    expect($janitor->command('janitor:job  --key some.key.to.task --site')['status'])->toEqual(200);

    expect($janitor->command('janitor:job  --key some.key.to.task --site --data "some data"')['message'])->toEqual('site:// some data');
});

test('method', function () {
    $janitor = new Janitor();
    expect($janitor->command('janitor:call --method whoAmI --page page://vf0xqIlpU0ZlSorI')['status'])->toEqual(200);

    expect($janitor->command('janitor:call --method repeatAfterMe --data hello --page page://vf0xqIlpU0ZlSorI')['message'])->toEqual('Repeat after me: hello');

    expect($janitor->command('janitor:call --method nullberry --page page://vf0xqIlpU0ZlSorI')['status'])->toEqual(200);

    expect($janitor->command('janitor:call --method boolberry --page page://vf0xqIlpU0ZlSorI')['status'])->toEqual(204);
});

it('can resolve models', function () {
    kirby()->impersonate('kirby');
    $user = kirby()->users()->create([
        'email' => 'test@bnomei.com',
    ]);
    $janitor = new Janitor();

    expect($janitor->model('page://vf0xqIlpU0ZlSorI'))->toBeInstanceOf(Kirby\Cms\Page::class)
        ->and($janitor->model('site://'))->toBeInstanceOf(Kirby\Cms\Site::class)
        ->and($janitor->model($user->uuid()->toString()))->toBeInstanceOf(Kirby\Cms\User::class)
        ->and($janitor->model('file://u8X1ZJkCgi2z1vZT'))->toBeInstanceOf(Kirby\Cms\File::class)
        ->and($janitor->model('home')->slug())->toBe('home')
        ->and($janitor->model('rubbish'))->toBeNull();

    $user->delete();
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
        'email' => 'test@bnomei.com',
    ]);

    expect(Janitor::query('Hello {{ kirby.version }}'))->toEqual('Hello '.kirby()->version())
        ->and(Janitor::query(null))->toEqual('')
        ->and(Janitor::query('URL {{ site.url }}', site()))->toEqual('URL '.kirby()->site()->url())
        ->and(Janitor::query('Title {{ page.title }}', $home))->toEqual('Title '.$home->title())
        ->and(Janitor::query('Email {{ user.email }}', $user))->toEqual('Email '.$user->email())
        ->and(Janitor::query('File {{ file.filename }}', $home->files()->first()))->toEqual('File '.$home->files()->first()->filename());

    $user->delete();
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
    $command = 'janitor:pipe --data "{( site.title )}" --to "message"';
    [$name, $args] = Janitor::parseCommand($command);
    $args = Janitor::resolveQueriesInCommand($args);

    expect($name)->toEqual('janitor:pipe')
        ->and($args)->toBe([
            '--data',
            'Janitor Tests',
            '--to',
            'message',
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
