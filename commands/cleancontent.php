<?php

declare(strict_types=1);

use Bnomei\Janitor;
use Kirby\CLI\CLI;
use Kirby\Cms\Pages;

class JanitorCleanContentCommand
{
    /**
     * based on cookbook by @texnixe
     * https://getkirby.com/docs/cookbook/extensions/content-file-cleanup
     *
     * @param  string[]  $ignore
     */
    public static function cleanUp(CLI $cli, Pages $collection, array $ignore, ?string $lang = null, int &$failed = 0): int
    {
        $updated = 0;
        foreach ($collection as $item) {
            // Read only stored fields, not Kirby's merged default-language fallbacks.
            $version = $item->version();
            $contentFields = array_keys($version->read($lang ?? 'default') ?? []);

            // Kirby keeps the declared blueprint case, but content field keys are lowercase.
            $blueprintFields = array_map(fn ($key) => strtolower((string) $key), array_keys($item->blueprint()->fields()));

            // get all field keys that are in $contentFields but not in $blueprintFields
            $fieldsToBeDeleted = array_diff($contentFields, $blueprintFields, $ignore);

            // update page only if there are any fields to be deleted
            if (count($fieldsToBeDeleted) > 0) {
                try {
                    $version->update(array_fill_keys($fieldsToBeDeleted, null), $lang ?? 'default');
                } catch (Exception $e) {
                    $failed++;
                    $cli->red('ERR '.($lang ? '['.$lang.'] ' : '').$item->id().': '.$e->getMessage());

                    continue;
                }
                $updated++;
                $cli->green('+++ '.($lang ? '['.$lang.'] ' : '').$item->id());
            } else {
                $cli->white('=== '.($lang ? '['.$lang.'] ' : '').$item->id());
            }
        }

        return $updated;
    }
}

return [
    'description' => 'Remove all content data that has no blueprint setup',
    'args' => [
        'core' => [
            'longPrefix' => 'core',
            'description' => 'core fields in content files',
            'defaultValue' => 'title,slug,template,sort,uuid',
            'castTo' => 'string',
        ],
        'ignore' => [
            'longPrefix' => 'ignore',
            'description' => 'ignored fields in content files as comma seperated list',
            'castTo' => 'string',
        ],
    ] + Janitor::ARGS, // page, file, user, site, data, model
    'command' => static function (CLI $cli): void {
        $kirby = $cli->kirby();

        // Authenticate as almighty
        $kirby->impersonate('kirby');

        // Define your collection
        // Don't use `$site->index()` for thousands of pages
        $collection = $kirby->site()->index();

        $time = time();
        $count = $collection->count();
        $updated = 0;
        $failed = 0;

        // set the fields to be ignored
        $ignore = array_merge(
            explode(',', $cli->arg('core')),
            explode(',', $cli->arg('ignore') ?? '')
        );

        // call the script for all languages if multilang
        if ($kirby->multilang() === true) {
            $count *= $kirby->languages()->count();
            $languages = $kirby->languages();
            foreach ($languages as $language) {
                $updated += JanitorCleanContentCommand::cleanUp($cli, $collection, $ignore, $language->code(), $failed);
            }
        } else {
            $updated += JanitorCleanContentCommand::cleanUp($cli, $collection, $ignore, failed: $failed);
        }

        $data = [
            'status' => $failed > 0 ? 500 : ($updated > 0 ? 200 : 204),
            'duration' => time() - $time,
            'updated' => $updated,
            'failed' => $failed,
            'count' => $count,
        ];
        $data['message'] = $failed > 0
            ? tt('janitor.cleancontent.failed', '{{ updated }} / {{ count }} cleaned, {{ failed }} failed', $data)
            : tt('janitor.cleancontent.message', '{{ updated }} / {{ count }} needed cleaning', $data);

        $cli->blue($data['duration'].' sec');
        $cli->blue($data['count'].' checked');
        if ($failed === 0) {
            $cli->success($data['updated'].' needed cleaning');
        }

        Janitor::finish($cli, $data);
    },
];
