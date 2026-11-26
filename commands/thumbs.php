<?php

declare(strict_types=1);

use Bnomei\Janitor;
use Kirby\CLI\CLI;
use Kirby\Cms\Media;
use Kirby\Data\Data;
use Kirby\Filesystem\Dir;
use Kirby\Toolkit\A;
use Symfony\Component\Finder\Finder;

return [
    'description' => 'Generate all thumbs',
    'args' => [] + Janitor::ARGS, // page, file, user, site, data, model
    'command' => static function (CLI $cli): void {
        $time = time();

        $root = null;
        // just one page
        if (! empty($cli->arg('page')) && $page = page($cli->arg('page'))) {
            $root = $page->mediaRoot();
        }
        // full site
        if (! $root || $cli->arg('site')) {
            $root = kirby()->roots()->media().'/pages';
        }
        Dir::make($root); // make sure folder exists

        // find existing thumbs
        $finder = new Finder;
        $finder->files()
            ->in($root)
            ->name('/\.(?:avif|gif|jpeg|jpg|png|webp)$/');
        $cli->blue(iterator_count($finder).' existing thumbs');

        // job files
        $finder = new Finder;
        $finder->files()
            ->in($root)
            ->ignoreDotFiles(false)
            ->path('#(?:^|/)\.jobs/[^/]+\.json$#');
        $countJobs = iterator_count($finder);
        $cli->blue($countJobs.' thumb job files found');

        // generate
        $created = 0;
        $jobsFailed = [];
        $countJobs > 0 && $cli->out('Generating Thumbs...');

        $pagesRoot = kirby()->roots()->media().'/pages/';
        foreach ($finder as $file) {
            // <media>/pages/<page id>/<media hash>/.jobs/<thumbnail>.json
            $hashDirectory = dirname($file->getPath());
            $parentID = substr(dirname($hashDirectory), strlen($pagesRoot));
            $page = page($parentID);
            if (! $page) {
                $skip = '𐄂 '.$parentID.' => Page not found, removed job file';
                $jobsFailed[] = $skip;
                $cli->red($skip);
                unlink($file->getPath().'/'.$file->getFilename());

                continue;
            }

            $path = $file->getPath().'/'.$file->getFilename();
            $options = Data::read($path);
            $jobFilename = $file->getFilenameWithoutExtension();
            $filename = A::get($options, 'filename');

            $pageFile = $page->file($filename);
            if (! $pageFile) {
                $skip = '𐄂 '.$parentID.'/'.$filename.' => File not found, removed job file';
                $jobsFailed[] = $skip;
                $cli->red($skip);
                unlink($file->getPath().'/'.$file->getFilename());

                continue;
            }

            $hash = basename($hashDirectory);
            if (Media::link($page, $hash, $jobFilename) !== false) {
                $cli->out('✔ '.$file->getPath().'/'.$file->getFilename());
                $created++;
            }
        }

        $duration = time() - $time;

        $cli->blue($duration.' sec');
        (
            count($jobsFailed) > 0 ?
            $cli->red(count($jobsFailed).' jobs failed') :
            $cli->blue(count($jobsFailed).' jobs failed')
        );
        $cli->success($created.' thumbs created');

        janitor()->data($cli->arg('command'), [
            'status' => $created > 0 ? 200 : 204,
            'duration' => $duration,
            'jobs' => $countJobs,
            'created' => $created,
            'failed' => count($jobsFailed),
            'message' => tt(
                'janitor.thumbs.message',
                '{{ created }} thumbs created, {{ failed }} failed',
                ['created' => $created, 'failed' => count($jobsFailed)]
            ),
        ]);
    },
];
