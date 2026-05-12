<?php

declare(strict_types=1);

if (! class_exists('Bnomei\Janitor')) {
    require_once __DIR__.'/../classes/Janitor.php';
}

use Bnomei\Janitor;
use Bnomei\Lapse;
use Kirby\CLI\CLI;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Http\Remote;
use Kirby\Query\Query;
use Symfony\Component\Finder\Finder;

class JanitorRenderCommand
{
    private int $countPages;

    private int $visitedPages;

    private int $countLanguages;

    private array $renderFailed;

    private array $foundThumbs;

    private string $renderSiteUrl;

    public function run(CLI $cli): void
    {
        $time = time();
        $kirby = $cli->kirby();

        // make sure the thumbs are triggered
        $kirby->cache('pages')->flush();
        if (class_exists('\Bnomei\Lapse')) {
            Lapse::singleton()->flush();
        }

        // visit all pages to generate media/*.job files
        $allPages = $this->getPageIDs($cli);
        $this->countPages = count($allPages);
        $this->countLanguages = $kirby->multilang() === true ? $kirby->languages()->count() : 1;
        $this->renderFailed = [];
        $this->foundThumbs = [];
        $this->visitedPages = 0;

        $this->renderSiteUrl = ! empty($cli->arg('remote')) ?
            rtrim($cli->arg('remote'), '/') : '';
        // rtrim((php_sapi_name() === 'cli' ? site()->url() : ''), '/')

        $cli->blue($this->countLanguages.' languages');
        $cli->blue($this->countPages.' pages');
        $this->countPages > 0 && $cli->out('Rendering Pages...');

        foreach ($allPages as $pageId) {
            try {
                if (strlen($this->renderSiteUrl) > 0) {
                    $content = $this->remotePageContent($pageId);
                } else {
                    $content = $this->renderPageContent($pageId);
                }
                if (strlen($content) > 0) {
                    $thumbs = $this->findUrlOfThumbsInContent($content);
                    $thumbs = array_unique($thumbs);
                    $this->foundThumbs = array_merge(
                        $this->foundThumbs,
                        $thumbs
                    );
                }
                $cli->out('['.count($thumbs).'] '.$pageId);
            } catch (Exception $ex) {
                $this->renderFailed[] = $pageId.' => '.$ex->getMessage();
            }

            $this->visitedPages++;
        }

        $this->foundThumbs = array_unique($this->foundThumbs);
        $duration = time() - $time;

        $data = [
            'status' => $this->visitedPages === 0 || count($this->renderFailed) ? 204 : 200,
            'duration' => $duration,
            'count' => $this->visitedPages,
            'foundThumbs' => count($this->foundThumbs),
            'renderFailed' => count($this->renderFailed),
        ];
        $data['message'] = t(
            'janitor.render.message',
            str_replace(
                ['{{ count }}', '{{ failed }}', '{{ thumbs }}'],
                [$data['count'], $data['renderFailed'], $data['foundThumbs']],
                '{{ count }} pages rendered, {{ failed }} failed, {{ thumbs }} thumbs found'
            )
        );

        $cli->blue($data['duration'].' sec');
        $cli->blue($data['foundThumbs'].' images found in rendered content');
        (
            $data['renderFailed'] > 0 ?
                $cli->error($data['renderFailed'].' pages failed rendering') :
                $cli->blue($data['renderFailed'].' pages failed rendering')
        );
        foreach ($this->renderFailed as $fail) {
            $cli->red($fail);
        }
        $cli->success(
            $data['count'].' pages rendered'
        );

        janitor()->data($cli->arg('command'), $data);
    }

    private function getPageIDs(CLI $cli): array
    {
        $query = $cli->arg('query');
        $page = ! empty($cli->arg('page')) ? page($cli->arg('page')) : null;
        $ids = [];
        if (! empty($query) && $query !== 'site.index') {
            $allPages = (new Query($query))->resolve([
                'kirby' => $cli->kirby(),
                'site' => $cli->kirby()->site(),
                'page' => $page,
            ]);
            // got single page from query instead of collection then create collection
            if ($allPages instanceof Page) {
                $allPages = new Pages([$allPages]);
            }
            foreach ($allPages as $page) {
                $ids[] = $page->id(); // this should not fully load the page yet
            }
        } else { // performance optimized way to get ids for `site.index`
            $finder = new Finder;
            $finder->directories()
                ->in($cli->kirby()->roots()->content())
                ->exclude(['_drafts', '_changes']);
            foreach ($finder as $folder) {
                $id = $folder->getRelativePathname();
                if ($this->isRenderableContentPath($id)) {
                    $ids[] = ltrim(preg_replace('/\/*\d+_/', '/', $id), '/');
                }
            }
        }

        return $ids;
    }

    private function isRenderableContentPath(string $path): bool
    {
        $ignored = ['_drafts', '_changes'];
        $segments = explode('/', str_replace('\\', '/', $path));

        foreach ($segments as $segment) {
            if (in_array($segment, $ignored, true)) {
                return false;
            }
        }

        return true;
    }

    private function findUrlOfThumbsInContent(string $content): array
    {
        preg_match_all('~/media/pages/([a-zA-Z0-9-_./]+.(?:avif|gif|jpeg|jpg|png|webp))~', $content, $matches);
        if ($matches && count($matches) > 1) {
            return $matches[1];
        }

        return [];
    }

    private function renderPageContent(string $pageId): string
    {
        $page = page($pageId);
        if (! $page instanceof Page) {
            throw new RuntimeException('Page not found');
        }

        if ($this->countLanguages > 1) {
            $content = $page->render();
            foreach (kirby()->languages() as $lang) {
                site()->visit($page, $lang->code());
                $content .= $page->render();
            }
        } else {
            site()->visit($page);
            $content = $page->render();
        }

        return $content;
    }

    private function remotePageContent(string $pageId): ?string
    {
        $content = Remote::get($this->renderSiteUrl.'/'.$pageId)->content();
        foreach (kirby()->languages() as $lang) {
            $content .= Remote::get($this->renderSiteUrl.'/'.$lang->code().'/'.$pageId)->content();
        }

        return $content;
    }
}

return [
    'description' => 'Render all pages',
    'args' => [
        'query' => [
            'prefix' => 'q',
            'longPrefix' => 'query',
            'description' => 'Query what pages to render. like `site.index()` (default), `site.index(true)` or `page.children()`',
            'defaultValue' => 'site.index', // `site.index`, `site.index(true)` for with drafts
            'castTo' => 'string',
        ],
        'remote' => [
            'prefix' => 'r',
            'longPrefix' => 'remote',
            'description' => 'provide URL to render using Remote::get instead of `$page->render()`',
            'castTo' => 'string',
        ],
    ] + Janitor::ARGS, // page, file, user, site, data, model
    'command' => static function (CLI $cli): void {
        (new JanitorRenderCommand)->run($cli);
    },
];
