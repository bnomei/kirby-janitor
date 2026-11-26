<?php

declare(strict_types=1);

namespace Bnomei;

use Kirby\Blueprint\Blueprint;
use Kirby\CLI\CLI;
use Kirby\Cms\File;
use Kirby\Cms\Find;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Kirby\Data\Data;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Http\Response;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;
use Kirby\Uuid\Uuids;

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

    private static bool $dispatching = false;

    public static function finish(CLI $cli, array $result): void
    {
        janitor()->data($cli->arg('command'), $result);

        // Kirby turns exceptions into nonzero terminal exits. Embedded callers
        // (including PHP scripts on the CLI) need the result instead.
        if (! self::$dispatching && PHP_SAPI === 'cli' && ($result['status'] ?? 200) >= 400) {
            throw new \RuntimeException($result['error'] ?? $result['message'] ?? 'Command failed');
        }
    }

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

    public static function registerCommandPermissions(): void
    {
        $actions = ['commands.*' => true];
        foreach ((new CLI)->commands() as $commands) {
            foreach ($commands as $name) {
                $actions += array_fill_keys(self::commandPermissionCandidates($name), true);
            }
        }
        kirby()->extend(['permissions' => [self::PERMISSION_CATEGORY => $actions]]);
    }

    /**
     * @param  array<mixed, mixed>  $permissions
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
                // These are role permissions in the plugin's category, not model actions.
                $role = $user->role();
                $permissions = $role->permissions()->toArray()[self::PERMISSION_CATEGORY] ?? [];
                // Kirby fills in all registered actions. Only role overrides should take
                // precedence over commands.* or a namespace wildcard.
                if (in_array(false, $permissions, true)) {
                    $file = kirby()->root('roles').'/'.$role->id().'.yml';
                    $blueprint = Blueprint::extend(is_file($file) ? Data::read($file) : 'users/'.$role->id());
                    $rules = $blueprint['permissions'][self::PERMISSION_CATEGORY] ?? [];
                    $permissions = array_intersect_key($permissions, ['commands.*' => true] + (array) $rules)
                        + array_filter($permissions, static fn ($value) => $value !== ($rules['*'] ?? true));
                }
                if (self::commandAllowedByPermissions($commandName, $permissions) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    public function webhook(string $secret, mixed $command): Response
    {
        $result = match (true) {
            ! self::matchesSecret($this->option('secret'), $secret) => ['status' => 401],
            ! is_string($command) || trim($command) === '' => ['status' => 400, 'info' => 'no command given'],
            ! $this->canDispatchCommand($command, 'public') => ['status' => 403],
            default => $this->command($command),
        };
        $status = $result['status'] ?? 200;

        // Keep the JSON body for successful no-ops (Janitor status 204).
        return Response::json($result, $status >= 400 && $status <= 599 ? $status : 200);
    }

    public function command(string $command, ?string $view = null): array
    {
        if (php_sapi_name() !== 'cli' && ! Str::contains($command, ' --quiet')) {
            $command .= ' --quiet';
        }

        [$name, $args] = Janitor::parseCommand($command);
        $args = Janitor::resolveQueriesInCommand($args, $view); // like a "lazy/smart" `{( page.callme )}`

        $previous = self::$dispatching;
        self::$dispatching = true;
        try {
            CLI::command($name, ...$args);
        } finally {
            self::$dispatching = $previous;
        }

        return $this->data($name) ?? [
            'status' => 200,
            'message' => 'Janitor has no data from command "'.$name.'".',
        ];
    }

    public function model(string $uuid): mixed
    {
        return Janitor::resolveModel($uuid);
    }

    public static function modelIdentifier(Page|File|User|Site $model): string
    {
        if ($model instanceof Site) {
            return 'site://';
        }

        return Uuids::enabled() ? $model->uuid()->toString() : $model->id();
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

    public static function query(mixed $template = null, mixed $model = null, ?string $fallback = null): string
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
        ], [
            // Kirby also accepts single braces. Don't resolve Janitor's {( lazy )} placeholders yet.
            'start' => '(?:{{|{<|{(?=[^(]))',
            'fallback' => $fallback,
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

    public static function resolveModel(?string $uuid): mixed
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

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
    public static function resolveQueriesInCommand(array $args, ?string $view = null): array
    {
        $model = null;
        // parseCommand returns flag/value pairs; a value may itself look like a flag.
        $flags = array_filter($args, static fn (int $i) => $i % 2 === 0, ARRAY_FILTER_USE_KEY);
        $modelKey = array_search('--model', $flags, true);

        if ($modelKey !== false) {
            $modelId = $modelKey + 1 < count($args) ? $args[$modelKey + 1] : null;
            if (! is_string($modelId) || $modelId === '') {
                return $args; // @codeCoverageIgnore
            }
            $model = Janitor::resolveModel($modelId);
        } elseif ($view !== null) {
            $targets = array_intersect(['--site', '--page', '--file', '--user'], $flags);
            if ($targets !== []) {
                $type = reset($targets);
                $id = $args[array_search($type, $flags, true) + 1] ?? '';
                $model = match ($type) {
                    '--site' => kirby()->site(),
                    '--page' => $id !== '' ? kirby()->page($id) : null,
                    '--file' => $id !== '' ? kirby()->file($id) : null,
                    '--user' => $id !== '' ? kirby()->user($id) : null,
                };
                if ($model !== null) {
                    array_push($args, '--model', $model instanceof Site ? 'site://' : $id);
                }
            } else {
                try {
                    $model = Find::parent($view);
                } catch (InvalidArgumentException) {
                    // A custom Panel area need not have a model. Not-found and access errors still propagate.
                }
                if ($model instanceof Page || $model instanceof File || $model instanceof User || $model instanceof Site) {
                    $id = self::modelIdentifier($model);
                    array_push($args, '--'.$model::CLASS_ALIAS, $model instanceof Site ? '' : $id, '--model', $id);
                }
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
