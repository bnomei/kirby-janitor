<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\CLI\CLI;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Panel\Site;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

final class Janitor
{
    public const ARGS = [
        'page' => [
            'prefix' => 'p',
            'longPrefix' => 'page',
            'description' => 'Page UUID or ID',
            'castTo' => 'string',
        ],
        'file' => [
            'prefix' => 'f',
            'longPrefix' => 'file',
            'description' => 'File UUID or ID',
            'castTo' => 'string',
        ],
        'user' => [
            'prefix' => 'u',
            'longPrefix' => 'user',
            'description' => 'User UUID or ID',
            'castTo' => 'string',
        ],
        'site' => [
            'prefix' => 's',
            'longPrefix' => 'site',
            'description' => 'Site',
            'noValue' => true,
        ],
        'data' => [
            'prefix' => 'd',
            'longPrefix' => 'data',
            'description' => 'Data',
        ],
        'model' => [
            'prefix' => 'm',
            'longPrefix' => 'model',
            'description' => 'Model (Page, File, User, Site) UUID or ID',
        ],
    ];

    private static array $data = [];

    public function data(string $command, ?array $data = null): ?array
    {
        if ($data) {
            Janitor::$data[$command] = $data;
        }

        return A::get(Janitor::$data, $command);
    }

    private array $options;

    public function __construct(array $options = [])
    {
        $defaults = [
            'debug' => option('debug'),
            'secret' => option('bnomei.janitor.secret'),
        ];
        $this->options = array_merge($defaults, $options);

        foreach ($this->options as $key => $call) {
            if (is_callable($call) && $key == 'secret') {
                $this->options[$key] = $call();
            }
        }
    }

    public function option(?string $key = null): mixed
    {
        if ($key) {
            return A::get($this->options, $key);
        }

        return $this->options;
    }

    public function command(string $command): array
    {
        if (php_sapi_name() !== 'cli' && ! Str::contains($command, ' --quiet')) {
            $command .= ' --quiet';
        }

        [$name, $args] = Janitor::parseCommand($command);
        $args = Janitor::resolveQueriesInCommand($args); // like a "lazy/smart" `{( page.callme )}`

        CLI::command($name, ...$args);

        return $this->data($name) ?? [
            'status' => 200,
            'message' => 'Janitor has no data from command "'.$name.'".',
        ];
    }

    public function model(string $uuid): mixed
    {
        return Janitor::resolveModel($uuid);
    }

    private static ?self $singleton;

    public static function singleton(array $options = []): Janitor
    {
        if (isset(self::$singleton)) {
            return self::$singleton;
        }

        self::$singleton = new Janitor($options);

        return self::$singleton;
    }

    public static function isTrue(mixed $val, bool $return_null = false): bool
    {
        $boolval = (is_string($val) ? filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : (bool) $val);

        return ! ($boolval === null && ! $return_null) && $boolval;
    }

    /**
     * @return (string|string[])[]
     *
     * @psalm-return list{string, list<string>}
     */
    public static function parseCommand(string $command): array
    {
        $groups = explode(' ', $command);
        $name = array_shift($groups);
        $groups = explode(' --', ' '.implode(' ', $groups));
        array_shift($groups); // remove empty first value
        $args = [];

        foreach ($groups as $group) {
            $parts = explode(' ', $group);
            $args[] = '--'.array_shift($parts);
            // remove enclosing " or ' from string like it would happen
            // in terminal so commands in blueprint can be used vice versa
            $args[] = trim(trim(implode(' ', $parts), '"'), "'");
        }

        return [$name, $args];
    }

    public static function query(mixed $template = null, mixed $model = null): string
    {
        // array|Closure|string|null could be passed from I18n::translate
        if (! is_string($template)) {
            return '';
        }

        $page = null;
        $file = null;
        $site = kirby()->site();
        $user = kirby()->user();
        if ($model instanceof Page) {
            $page = $model;
        } elseif ($model instanceof File) {
            $file = $model;
        } elseif ($model instanceof Site || $model === $site) {
            $site = $model;
        } elseif ($model instanceof User) {
            $user = $model;
        }

        return Str::template($template, [
            'kirby' => kirby(),
            'site' => $site,
            'page' => $page,
            'file' => $file,
            'user' => $user,
            'model' => $model,
        ]);
    }

    public static function requestBlockedByMaintenance(?string $request = null): bool
    {
        $request ??= kirby()->request()->url()->toString();
        foreach ([
            kirby()->urls()->panel(),
            kirby()->urls()->api(),
            kirby()->urls()->media(),
        ] as $url) {
            if (str_contains($request, $url)) {
                return false;
            }
        }

        $isBlocked = option('bnomei.janitor.maintenance.check', true);
        if ($isBlocked && ! is_string($isBlocked) && is_callable($isBlocked)) {
            $isBlocked = $isBlocked(); // @codeCoverageIgnore
        }

        return (bool) $isBlocked;
    }

    public static function resolveModel(string $uuid): mixed
    {
        if (Str::startsWith($uuid, 'page://')) {
            return kirby()->page($uuid);
        } elseif (Str::startsWith($uuid, 'file://')) {
            return kirby()->file($uuid);
        } elseif (Str::startsWith($uuid, 'user://') || Str::contains($uuid, '@')) {
            return kirby()->user($uuid);
        } elseif (Str::startsWith($uuid, 'site://') || $uuid === '$') {
            return kirby()->site();
        }

        foreach (['page', 'file', 'user'] as $finder) {
            if ($model = kirby()->{$finder}($uuid)) {
                return $model;
            }
        }

        return null;
    }

    public static function resolveQueriesInCommand(array $args): array
    {
        $model = null;
        $modelKey = array_search('--model', $args);

        if ($modelKey !== false && is_int($modelKey)) {
            $model = $modelKey + 1 < count($args) ? $args[$modelKey + 1] : null;
            if (! $model) {
                return $args; // @codeCoverageIgnore
            }
            $model = Janitor::resolveModel($model);
        }

        if ($modelKey === false && ! $model && $path = get('path')) {
            // infer model (page or page draft) from current panel path
            if (Str::contains($path, 'panel/site')) {
                $model = kirby()->site();
            } elseif (Str::contains($path, 'panel/pages') && array_search('--page', $args) === false) {
                $id = trim(str_replace(['panel/pages', '+'], ['', '/'], $path), '/');
                $model = kirby()->page($id);
                $args[] = '--page';
                $args[] = $model->uuid()?->toString() ?? $model->id();
            } elseif (Str::contains($path, 'panel/users') && array_search('--user', $args) === false) {
                $id = trim(str_replace(['panel/users', '+'], ['', '/'], $path), '/');
                $model = kirby()->user($id);
                $args[] = '--user';
                $args[] = $model->uuid()?->toString() ?? $model->id();
            } elseif (Str::contains($path, 'panel/account') && array_search('--user', $args) === false) {
                // $id = trim(str_replace(['panel/account', '+'], ['', '/'], $path), '/');
                $model = kirby()->user();
                $args[] = '--user';
                $args[] = $model->uuid()?->toString() ?? $model->id();
            }

            $args[] = '--model';
            $args[] = $model->uuid()?->toString() ?? $model->id();
        }

        $args = array_map(function ($value) use ($model) {
            // allows for html even without {< since it is not a blueprint query
            // but just a string inside the command
            $value = str_replace(['{(', ')}'], ['{{', '}}'], $value, $count);
            if ($count > 0) {
                $value = Janitor::query($value, $model);
            }

            return $value;
        }, $args);

        return $args;
    }
}
