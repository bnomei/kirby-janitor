<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Bnomei\Janitor;
use Bnomei\JanitorDownload;
use Bnomei\JanitorField;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Kirby\Data\Data;
use Kirby\Filesystem\Dir;
use Kirby\Form\Field;
use Kirby\Panel\Ui\Button\ViewButton;

it('exports a non-writing Kirby field and keeps command queries lazy unless marked eager', function () {
    $class = Field::resolve('janitor');
    $home = page('home');
    $field = new $class(
        model: $home,
        command: 'janitor:pipe --to message --data "{< page.slug >}: {{ page.title }}"',
        label: ['*' => 'Run {{ page.slug }}'],
        help: 'Page: **{{ page.slug }}**',
        confirm: 'Proceed?',
        icon: 'bell',
        autosave: 'false',
        clipboard: 'on',
        intab: 1,
        unsaved: false,
    );

    $props = $field->toArray();
    $id = $home->uuid()->toString();
    expect($field)->toBeInstanceOf(JanitorField::class)
        ->and($field->hasValue())->toBeFalse()
        ->and($props)->toMatchArray([
            'type' => 'janitor', 'saveable' => false,
            'label' => 'Run home', 'confirm' => 'Proceed?', 'icon' => 'bell',
            'autosave' => false, 'clipboard' => true, 'intab' => true, 'unsaved' => false,
            'cooldown' => 2000,
            'command' => 'janitor:pipe --to message --data "home: {( page.title )}" --page '.$id.' --model '.$id.' --quiet',
        ])
        ->and((string) $props['help'])->toBe('<p>Page: <strong>home</strong></p>')
        ->and(janitor()->command($props['command'])['message'])->toBe('home: '.$home->title());
});

it('keeps a usable command when legacy cosmetic settings are empty or malformed', function () {
    $button = (new JanitorField(model: site(), command: 'whistle', cooldown: '', icon: ['en' => 'star'],
        color: false, backgroundColor: 17, unsaved: null))->toArray();

    expect($button)->toMatchArray(['command' => 'whistle --site --model site:// --quiet', 'cooldown' => 0,
        'icon' => '', 'color' => '', 'backgroundColor' => '', 'unsaved' => true]);
});

it('resolves the same button options on a single field, a list entry and a model-query entry', function () {
    $model = new class(['slug' => 'catalog', 'content' => ['title' => 'Catalog']]) extends Page
    {
        public function tools(): array
        {
            return [
                ['text' => ['en' => 'Open {{ page.title }}'], 'link' => '/items/{{ page.slug }}', 'target' => '_blank',
                    'icon' => 'cart', 'title' => 'View {{ page.slug }}', 'theme' => 'purple-icon',
                    'badge' => ['text' => 3, 'theme' => 'info'], 'disabled' => true],
                ['text' => 'Run', 'command' => 'whistle'],
            ];
        }
    };
    $definitions = $model->tools();
    $single = (new JanitorField(...['model' => $model] + $definitions[0]))->toArray();
    $list = (new JanitorField(model: $model, buttons: $definitions, label: 'Actions'))->toArray();
    $query = (new JanitorField(model: $model, buttons: 'page.tools'))->toArray();

    foreach ([$single, $list['buttons'][0], $query['buttons'][0]] as $button) {
        expect($button)->toMatchArray([
            'text' => 'Open Catalog', 'link' => '/items/catalog', 'target' => '_blank', 'title' => 'View catalog',
            'icon' => 'cart', 'theme' => 'purple-icon', 'badge' => ['text' => 3, 'theme' => 'info'], 'disabled' => true,
            'command' => '',
        ]);
    }
    expect($single)->not->toHaveKey('buttons')
        ->and($list['label'])->toBe('Actions')
        ->and($query['buttons'][1]['command'])->toBe('whistle --page catalog --model catalog --quiet')
        ->and((new JanitorField(model: $model, buttons: []))->toArray()['buttons'])->toBe([]);
});

it('reports conflicting actions instead of offering a button that can do both', function () {
    $ambiguous = ['command' => 'whistle', 'dialog' => 'pages/home/changeTitle'];
    foreach ([$ambiguous, ['buttons' => [$ambiguous]], ['command' => 'whistle', 'buttons' => []]] as $field) {
        expect((new JanitorField(...$field))->toArray()['type'])->toBe('info');
    }
});

it('provides typed model arguments for site, file, and pages without a stored UUID', function () {
    $file = page('home')->files()->first();
    $id = $file->uuid()->toString();
    $virtual = new Page(['slug' => 'virtual-janitor', 'content' => ['title' => 'Virtual']]);
    expect((new JanitorField(model: site(), command: 'notify'))->command())->toBe('notify --site --model site:// --quiet')
        ->and((new JanitorField(model: $file, command: 'notify'))->command())->toBe('notify --file '.$id.' --model '.$id.' --quiet')
        ->and((new JanitorField(model: $virtual, command: 'notify'))->command())->toBe('notify --page virtual-janitor --model virtual-janitor --quiet');
});

it('preserves view button props through the Kirby view button factory', function () {
    foreach ([
        ['type' => 'janitor', 'command' => 'notify'],
        ['component' => 'k-janitor-view-button', 'props' => ['command' => 'notify']],
    ] as $definition) {
        $button = ViewButton::factory(button: $definition, name: 'janitor', model: site())->render();
        expect($button['component'])->toBe('k-janitor-view-button')
            ->and($button['props']['command'])->toBe('notify');
    }
});

it('dispatches view buttons with the typed model unless the command supplies its own', function () {
    kirby()->impersonate('kirby', function () {
        $file = page('home')->file('test.jpg');
        $view = $file->panel()->url(true);
        expect(janitor()->command('uuid --quiet', $view)['message'])->toBe($file->uuid()->toString())
            ->and(janitor()->command('uuid --quiet', '/site')['message'])->toBe('site://')
            ->and(janitor()->command('janitor:call --method slug --page home --quiet', '/site')['message'])->toBe('home')
            ->and(janitor()->command('janitor:pipe --data "{( page.title )}" --to message --page home --quiet', $view)['message'])->toBe('Home')
            ->and(janitor()->command('janitor:pipe --data "{( page.title )}" --to message --model home --quiet', $view)['message'])->toBe('Home');
    });
});

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

test('debug roles can deny commands by default and allow specific installed commands', function () {
    $app = kirby();
    $whoops = Kirby::$enableWhoops;
    $root = sys_get_temp_dir().'/janitor-roles-'.bin2hex(random_bytes(8));
    try {
        Kirby::$enableWhoops = false;
        Data::write($root.'/editor.yml', ['permissions' => [Janitor::PERMISSION_CATEGORY => [
            'commands.*' => false,
            'commands.uuid' => true,
            'commands.janitor.*' => true,
            'commands.janitor.download' => false,
        ]]]);
        $editor = $app->clone([
            'options' => ['debug' => true],
            'roots' => ['roles' => $root],
            'users' => [['id' => 'editor', 'role' => 'editor', 'email' => 'editor@example.test']],
        ]);
        $editor->impersonate('editor', function () {
            expect(janitor()->canDispatchCommand('uuid'))->toBeTrue()
                ->and(janitor()->canDispatchCommand('whistle'))->toBeFalse()
                ->and(janitor()->canDispatchCommand('janitor:pipe'))->toBeTrue()
                ->and(janitor()->canDispatchCommand('janitor:download'))->toBeFalse();
        });
    } finally {
        $app->clone();
        Kirby::$enableWhoops = $whoops;
        Dir::remove($root);
    }
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

test('failed commands signal cron without treating an intentional no-op as an error', function () {
    $secret = bin2hex(random_bytes(16));
    $janitor = new Janitor(['secret' => $secret, 'public.commands' => ['janitor:call']]);
    foreach ([['slug', 1, 500], ['boolberry', 0, 204]] as [$method, $exit, $status]) {
        $args = ['janitor:call', '--method', $method, '--quiet'];
        if ($method === 'boolberry') {
            array_push($args, '--page', 'home');
        }
        $process = proc_open([PHP_BINARY, __DIR__.'/../vendor/bin/kirby', ...$args], [], $pipes, __DIR__);
        expect(proc_close($process))->toBe($exit);

        $response = $janitor->webhook($secret, implode(' ', $args));
        expect($response->code())->toBe($status >= 400 ? $status : 200)
            ->and(json_decode($response->body(), true)['status'])->toBe($status);
    }
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

    expect($result['status'])->toBe(500)
        ->and($result)->not->toHaveKey('download')
        ->and(file_exists($target))->toBeFalse();
});

test('cleancontent removes obsolete fields from each stored translation without copying fallback fields', function () {
    $app = kirby();
    $whoops = Kirby::$enableWhoops;
    $root = sys_get_temp_dir().'/janitor-cleancontent-'.bin2hex(random_bytes(8));
    $home = $root.'/content/home/default';
    $englishOnly = $root.'/content/guide/default';
    $en = ['title' => 'Home', 'text' => 'English body', 'uuid' => 'home1234', 'keep' => 'English only'];
    $de = ['title' => 'Startseite', 'text' => 'Deutscher Text', 'slug' => 'startseite'];
    $guide = ['title' => 'Guide', 'text' => 'No German translation'];

    try {
        // The disposable Kirby app must not replace PHPUnit's error handlers.
        Kirby::$enableWhoops = false;
        Data::write($home.'.en.txt', $en + ['old' => 'remove']);
        Data::write($home.'.de.txt', $de + ['alt' => 'entfernen']);
        Data::write($englishOnly.'.en.txt', $guide + ['old' => 'remove']);
        $app->clone(['roots' => ['content' => $root.'/content', 'cache' => $root.'/cache']]);

        $command = 'janitor:cleancontent --ignore keep --quiet';
        $result = janitor()->command($command);
        expect(glob($home.'.*.txt'))->toBe([$home.'.de.txt', $home.'.en.txt'])
            ->and(Data::read($home.'.en.txt'))->toEqual($en)
            ->and(Data::read($home.'.de.txt'))->toEqual($de)
            ->and(Data::read($englishOnly.'.en.txt'))->toEqual($guide)
            ->and(is_file($englishOnly.'.de.txt'))->toBeFalse()
            ->and($result)->toMatchArray(['status' => 200, 'updated' => 3])
            ->and(janitor()->command($command))->toMatchArray(['status' => 204, 'updated' => 0]);
    } finally {
        $app->clone();
        Kirby::$enableWhoops = $whoops;
        Dir::remove($root);
    }
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

    expect($janitor->command('janitor:call --method isHomePage --page home --quiet'))->toBe(['status' => 200])
        ->and($janitor->command('janitor:call --method slug --page home --quiet'))->toBe(['status' => 200, 'message' => 'home']);
});

it('can resolve models', function () {
    kirby()->impersonate('kirby');
    $user = kirby()->users()->create([
        'email' => uniqid('test-', true).'@bnomei.com',
        'password' => bin2hex(random_bytes(24)),
        'role' => 'admin',
    ]);
    $janitor = new Janitor;

    expect($janitor->model('page://vf0xqIlpU0ZlSorI'))->toBeInstanceOf(Page::class)
        ->and($janitor->model('site://'))->toBeInstanceOf(Site::class)
        ->and($janitor->model($user->uuid()->toString()))->toBeInstanceOf(User::class)
        ->and($janitor->model('file://u8X1ZJkCgi2z1vZT'))->toBeInstanceOf(File::class)
        ->and($janitor->model('home')->slug())->toBe('home')
        ->and($janitor->model('rubbish'))->toBeNull();

    $command = (new JanitorField(model: $user, command: 'notify'))->command();
    [, $args] = Janitor::parseCommand($command);
    expect($args[0])->toBe('--user')
        ->and($args[2])->toBe('--model')
        ->and($janitor->model($args[1])->id())->toBe($user->id())
        ->and($args[3])->toBe($args[1]);

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
        'password' => bin2hex(random_bytes(24)),
        'role' => 'admin',
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
