<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\CLI\CLI;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\User;
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

    public const PERMISSION_CATEGORY = 'bnomei.janitor';

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
            'commands.allow' => option('bnomei.janitor.commands.allow', null),
            'commands.deny' => option('bnomei.janitor.commands.deny', []),
            'public.commands' => option('bnomei.janitor.public.commands', []),
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

    public static function matchesSecret(mixed $configured, string $provided): bool
    {
        return is_string($configured) && $configured !== '' && hash_equals($configured, $provided);
    }

    public static function commandName(string $command): string
    {
        [$commandName] = self::parseCommand($command);

        return $commandName;
    }

    /**
     * @return array<int, string>
     */
    public static function commandPermissionCandidates(string $command): array
    {
        $command = self::normalizeCommandName($command);

        if ($command === '') {
            return [];
        }

        $candidates = ['commands.*'];
        $parts = explode('.', $command);
        $prefix = [];

        foreach (array_slice($parts, 0, -1) as $part) {
            $prefix[] = $part;
            $candidates[] = 'commands.'.implode('.', $prefix).'.*';
        }

        $candidates[] = 'commands.'.$command;

        return array_values(array_unique($candidates));
    }

    /**
     * @param array<mixed, mixed> $permissions
     */
    public static function commandAllowedByPermissions(string $command, array $permissions): bool
    {
        $allowed = true;
        $permissions = self::flattenCommandPermissions($permissions);

        foreach (self::commandPermissionCandidates($command) as $candidate) {
            if (array_key_exists($candidate, $permissions)) {
                $allowed = $permissions[$candidate] === true;
            }
        }

        return $allowed;
    }

    public static function commandMatches(string $command, mixed $rules): bool
    {
        $command = self::normalizeCommandName($command);

        if ($command === '') {
            return false;
        }

        foreach (self::commandRules($rules) as $rule) {
            $rule = self::normalizeCommandRule($rule);

            if ($rule === '') {
                continue;
            }

            $pattern = '/^'.str_replace('\*', '.*', preg_quote($rule, '/')).'$/';

            if (preg_match($pattern, $command) === 1) {
                return true;
            }
        }

        return false;
    }

    public function canDispatchCommand(string $command, string $origin = 'panel'): bool
    {
        $commandName = self::commandName($command);

        if ($commandName === '') {
            return false;
        }

        if (self::commandMatches($commandName, $this->option('commands.deny')) === true) {
            return false;
        }

        $allow = $this->option('commands.allow');

        if ($allow !== null && self::commandMatches($commandName, $allow) === false) {
            return false;
        }

        if ($origin === 'public' && self::commandMatches($commandName, $this->option('public.commands')) === false) {
            return false;
        }

        if ($origin === 'panel') {
            $user = kirby()->user();

            if ($user instanceof User) {
                $permissions = $user->role()->permissions()->toArray()[self::PERMISSION_CATEGORY] ?? [];

                if (is_array($permissions) && self::commandAllowedByPermissions($commandName, $permissions) === false) {
                    return false;
                }
            }
        }

        return true;
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

    private static function modelIdentifier(Page|File|User|Site $model): string
    {
        $uuid = $model->uuid()->toString();
        if ($uuid !== '') {
            return $uuid;
        }

        if ($model instanceof Site) {
            return 'site://';
        }

        return $model->id();
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

    /**
     * @return array<int, string>
     */
    private static function commandRules(mixed $rules): array
    {
        if ($rules === true) {
            return ['*'];
        }

        if ($rules === false || $rules === null) {
            return [];
        }

        if (is_string($rules)) {
            return [$rules];
        }

        if (! is_array($rules)) {
            return [];
        }

        $commands = [];

        foreach ($rules as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $commands[] = $value;
                continue;
            }

            if (is_string($key) && $value === true) {
                $commands[] = $key;
            }
        }

        return $commands;
    }

    private static function normalizeCommandName(string $command): string
    {
        return self::normalizeCommandRule(self::commandName($command));
    }

    private static function normalizeCommandRule(string $command): string
    {
        $command = strtolower(trim($command));

        if (str_starts_with($command, 'commands.')) {
            $command = substr($command, 9);
        }

        return trim(str_replace([':', '/', '\\'], '.', $command), '.');
    }

    /**
     * @param  array<mixed, mixed>  $permissions
     * @return array<string, mixed>
     */
    private static function flattenCommandPermissions(array $permissions, string $prefix = ''): array
    {
        $flat = [];

        foreach ($permissions as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $path = $prefix === '' ? $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat = array_merge($flat, self::flattenCommandPermissions($value, $path));
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
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
        } elseif ($model instanceof Site) {
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

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    public static function resolveQueriesInCommand(array $args): array
    {
        $model = null;
        $modelKey = array_search('--model', $args);

        if ($modelKey !== false) {
            $modelId = $modelKey + 1 < count($args) ? $args[$modelKey + 1] : null;
            if (! is_string($modelId) || $modelId === '') {
                return $args; // @codeCoverageIgnore
            }
            $model = Janitor::resolveModel($modelId);
        }

        $path = get('path');
        if ($modelKey === false && ! $model && is_string($path)) {
            // infer model (page or page draft) from current panel path
            if (Str::contains($path, 'panel/site')) {
                $model = kirby()->site();
            } elseif (Str::contains($path, 'panel/pages') && array_search('--page', $args) === false) {
                $id = trim(str_replace(['panel/pages', '+'], ['', '/'], $path), '/');
                $page = kirby()->page($id);
                if ($page instanceof Page) {
                    $model = $page;
                    $args[] = '--page';
                    $args[] = Janitor::modelIdentifier($page);
                }
            } elseif (Str::contains($path, 'panel/users') && array_search('--user', $args) === false) {
                $id = trim(str_replace(['panel/users', '+'], ['', '/'], $path), '/');
                $user = kirby()->user($id);
                if ($user instanceof User) {
                    $model = $user;
                    $args[] = '--user';
                    $args[] = Janitor::modelIdentifier($user);
                }
            } elseif (Str::contains($path, 'panel/account') && array_search('--user', $args) === false) {
                // $id = trim(str_replace(['panel/account', '+'], ['', '/'], $path), '/');
                $user = kirby()->user();
                if ($user instanceof User) {
                    $model = $user;
                    $args[] = '--user';
                    $args[] = Janitor::modelIdentifier($user);
                }
            }

            if ($model instanceof Page || $model instanceof User || $model instanceof Site) {
                $args[] = '--model';
                $args[] = Janitor::modelIdentifier($model);
            }
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
