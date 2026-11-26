<?php

declare(strict_types=1);

use Bnomei\Janitor;
use Kirby\CLI\CLI;

return [
    'description' => 'Call a Janitor v2 job closure',
    'args' => [
        'key' => [
            'longPrefix' => 'key',
            'description' => 'Key of option (not name of a class)',
            'required' => true,
        ],
    ] + Janitor::ARGS, // page, file, user, site, data, model
    'command' => static function (CLI $cli): void {
        $key = $cli->arg('key');
        $job = option($key);
        $result = [
            'status' => 500,
        ];

        $model = null;
        if ($cli->arg('site')) {
            $model = $cli->kirby()->site();
        } elseif (! empty($cli->arg('page'))) {
            $model = $cli->kirby()->page($cli->arg('page'));
        } elseif (! empty($cli->arg('file'))) {
            $model = $cli->kirby()->file($cli->arg('file'));
        } elseif (! empty($cli->arg('user'))) {
            $model = $cli->kirby()->user($cli->arg('user'));
        }

        if ($model) {
            if (! is_string($job) && is_callable($job)) {
                if (empty($cli->arg('data'))) {
                    $result = $job($model);
                } else {
                    $result = $job($model, $cli->arg('data'));
                }
                if (is_null($result)) {
                    $result = ['status' => 200];
                } elseif (is_bool($result)) {
                    $result = ['status' => $result ? 200 : 204];
                } elseif (is_string($result)) {
                    $result = [
                        'status' => 200,
                        'message' => $result,
                    ];
                }
            } else {
                $result['message'] = t('janitor.job-not-found', 'Job "'.$key.'" could not be found.');
            }
        } else {
            $result['message'] = t('janitor.model-not-found', 'No model provided');
            $cli->error('No model provided. Use `--page`, `--file`, `--user` or `--site`.');
        }

        (A::get($result, 'status') === 200 ? $cli->success($key) : $cli->error($key));
        $cli->out(print_r($result, true));

        Janitor::finish($cli, $result);
    },
];
