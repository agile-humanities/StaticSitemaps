<?php
namespace StaticSitemaps\Robots;

/**
 * Answers two questions about robots.txt, and reports honestly (R3.9, Goal 5).
 *
 *   1. IS THE SITEMAP DISCOVERABLE?  Is there a Sitemap: directive, and does it point
 *      at the index we actually write? A sitemap nobody is told about is close to
 *      useless — submitting it to one search console reaches one crawler.
 *
 *   2. IS ANYTHING WE PUBLISH BLOCKED?  This is the consistency check, and it is the
 *      more valuable of the two. Advertising a URL that robots.txt then tells the
 *      crawler not to fetch is worse than not listing it at all: it produces coverage
 *      errors in search consoles and wastes the crawl budget this whole project exists
 *      to reclaim.
 *
 * The second check is exactly what would catch a future tightening of the existing
 * rule. On the installation this was designed against, robots.txt carries
 * "Disallow: /s/*\/discover?*" — which blocks only the QUERY-STRING forms, leaving the
 * bare search landing pages crawlable, so listing them is consistent. Drop the '?'
 * from that pattern one day and every search-page entry silently becomes a
 * contradiction. This audit is what says so.
 *
 * Samples paths rather than testing every URL: the answer is a property of the rules,
 * not of the individual URL, so a representative sample from each source is enough to
 * find a contradiction while keeping the config page fast.
 */
class RobotsAuditor
{
    /** @var RobotsFile */
    private $robots;

    public function __construct(RobotsFile $robots)
    {
        $this->robots = $robots;
    }

    /**
     * @param string   $indexUrl     Absolute URL of the sitemap index we write.
     * @param string[] $samplePaths  Representative local paths from the enabled sources.
     * @return array
     */
    public function audit(string $indexUrl, array $samplePaths = []): array
    {
        $directives = $this->robots->sitemapDirectives();
        $declared = false;
        foreach ($directives as $directive) {
            if (rtrim($directive, '/') === rtrim($indexUrl, '/')) {
                $declared = true;
                break;
            }
        }

        $blocked = [];
        foreach ($samplePaths as $label => $path) {
            if ($this->robots->blocks($path)) {
                $blocked[$label] = $path;
            }
        }

        $indexPath = (string) parse_url($indexUrl, PHP_URL_PATH);
        $indexBlocked = $indexPath !== '' && $this->robots->blocks($indexPath);

        return [
            'exists' => $this->robots->exists(),
            'path' => $this->robots->path(),
            'writable' => $this->robots->isWritable(),
            'directives' => $directives,
            'declared' => $declared,
            'indexBlocked' => $indexBlocked,
            'blocked' => $blocked,
            'rules' => $this->robots->wildcardRules(),
            'status' => $this->status($declared, $indexBlocked, $blocked),
            'summary' => $this->summary($declared, $indexBlocked, $blocked, $directives),
        ];
    }

    private function status(bool $declared, bool $indexBlocked, array $blocked): string
    {
        if ($indexBlocked || $blocked) {
            return 'conflict';
        }

        return $declared ? 'ok' : 'undeclared';
    }

    private function summary(bool $declared, bool $indexBlocked, array $blocked, array $directives): string
    {
        if ($indexBlocked) {
            return 'robots.txt blocks the sitemap index itself. Crawlers that obey it will never read the sitemap.'; // @translate
        }
        if ($blocked) {
            return sprintf(
                'robots.txt blocks %d of the content types this sitemap publishes. Crawlers will be '
                . 'told not to fetch URLs you are advertising, which shows up as coverage errors.',
                count($blocked)
            );
        }
        if (!$declared) {
            return $directives
                ? 'robots.txt names a sitemap, but not this one. Crawlers will not discover it automatically.' // @translate
                : 'robots.txt does not name a sitemap. Crawlers will not discover it automatically.'; // @translate
        }

        return 'The sitemap is declared in robots.txt and nothing it publishes is blocked.'; // @translate
    }
}
