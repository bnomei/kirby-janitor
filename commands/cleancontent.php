<?php

declare(strict_types=1);

if (! class_exists('Bnomei\Janitor')) {
    require_once __DIR__.'/../classes/Janitor.php';
}

use Bnomei\Janitor;
use Kirby\CLI\CLI;
use Kirby\Cms\Pages;

class JanitorCleanContentCommand
{
    /**
     * based on cookbook by @texnixe
     * https://getkirby.com/docs/cookbook/extensions/content-file-cleanup
     *
     * @param  null|string[]  $ignore
     *
     * @psalm-param list{string, string,...}|null $ignore
     */
    public static function cleanUp(CLI $cli, Pages $collection, ?array $ignore = null, ?string $lang = null): int
    {
        $updated = 0;
        foreach ($collection as $item) {
            // get all fields in the content file
            $contentFields = $item->content($lang)->fields();

            // unset all fields in the `$ignore` array
            foreach ($ignore as $field) {
                if (array_key_exists($field, $contentFields) === true) {
                    unset($contentFields[$field]);
                }
            }

            // get the keys
            $contentFields = array_keys($contentFields);

            // get all field keys from blueprint
            $blueprintFields = array_keys($item->blueprint()->fields());

            // get all field keys that are in $contentFields but not in $blueprintFields
            $fieldsToBeDeleted = array_diff($contentFields, $blueprintFields);

            // update page only if there are any fields to be deleted
            if (count($fieldsToBeDeleted) > 0) {
                // flip keys and values and set new values to null
                $data = array_map(function ($value) {
                    return null;
                }, array_flip($fieldsToBeDeleted));

                // try to update the page with the data
                try {
                    $item->update($data, $lang);
                    $updated++;
                    $cli->green('+++ '.($lang ? '['.$lang.'] ' : '').$item->id());
                } catch (Exception $e) {
                    $cli->red('ERR '.($lang ? '['.$lang.'] ' : '').$item->id().': '.$e->getMessage());
                }
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

        // set the fields to be ignored
        $ignore = array_merge(
            explode(',', $cli->arg('core')),
            explode(',', $cli->arg('ignore'))
        );

        // call the script for all languages if multilang
        if ($kirby->multilang() === true) {
            $count *= $kirby->languages()->count();
            $languages = $kirby->languages();
            foreach ($languages as $language) {
                $updated += JanitorCleanContentCommand::cleanUp($cli, $collection, $ignore, $language->code());
            }
        } else {
            $updated += JanitorCleanContentCommand::cleanUp($cli, $collection, $ignore);
        }

        $data = [
            'status' => $updated > 0 ? 200 : 204,
            'duration' => time() - $time,
            'updated' => $updated,
            'count' => $count,
        ];
        $data['message'] = t(
            'janitor.cleancontent.message',
            str_replace(
                ['{{ updated }}', '{{ count }}'],
                [$data['updated'], $data['count']],
                '{{ updated }} / {{ count }} needed cleaning'
            )
        );

        $cli->blue($data['duration'].' sec');
        $cli->blue($data['count'].' checked');
        $cli->success($data['updated'].' needed cleaning');

        janitor()->data($cli->arg('command'), $data);
    },
];
