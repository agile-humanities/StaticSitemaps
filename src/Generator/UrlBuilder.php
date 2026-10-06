<?php
namespace StaticSitemaps\Generator;

/**
 * Turns a local path into an absolute URL, and is the ONLY thing permitted to do so.
 *
 * Background jobs have no HTTP request, so Laminas' ServerUrl helper has nothing to
 * detect from. On this project that has already produced wrong stored URLs twice —
 * the IIIF manifest cache and the Solr index both baked in S3/localhost hosts when
 * run without a request. The fix is not to document the hazard but to remove it:
 *
 *   ServerUrl is not used anywhere in the generation path. Enforce this in review.
 *
 * The base URL is explicit configuration (R4). If it is missing we refuse rather
 * than guess — a sitemap full of http://localhost/ URLs is worse than no sitemap,
 * because it is published, cached, and read by crawlers before anyone notices.
 */
class UrlBuilder
{
    /** @var string Scheme + host + optional base path, no trailing slash. */
    private $base;

    public function __construct(string $baseUrl)
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            throw new \RuntimeException(
                'No base URL is configured. Sitemap generation cannot build absolute URLs '
                . 'without one, and will not guess.'
            );
        }

        $parts = parse_url($baseUrl);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException(sprintf(
                'The configured base URL "%s" is not an absolute URL with a scheme and host.',
                $baseUrl
            ));
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \RuntimeException(sprintf(
                'The configured base URL "%s" must use http or https.',
                $baseUrl
            ));
        }

        $this->base = rtrim($baseUrl, '/');
    }

    /**
     * Validate a candidate base URL without constructing a builder.
     *
     * @return string|null An error message, or null when the value is usable.
     */
    public static function validate(string $baseUrl): ?string
    {
        try {
            new self($baseUrl);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return null;
    }

    /**
     * @param string $localPath A path with a leading slash. Query strings are kept.
     */
    public function absolutise(string $localPath): string
    {
        if ($localPath === '') {
            return $this->base . '/';
        }
        if ($localPath[0] !== '/') {
            $localPath = '/' . $localPath;
        }

        return $this->base . $localPath;
    }

    /** The configured base, without a trailing slash. */
    public function base(): string
    {
        return $this->base;
    }
}
