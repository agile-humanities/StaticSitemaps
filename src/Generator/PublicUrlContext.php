<?php
namespace StaticSitemaps\Generator;

use Laminas\View\HelperPluginManager;

/**
 * Pins Laminas' ServerUrl and BasePath helpers to the configured public base URL for
 * the duration of a run, then restores them.
 *
 * ── Why this is necessary even though nothing in our pipeline uses ServerUrl ─────
 * The FILE STORE uses it, and media URLs come from the store.
 *
 * Omeka's stock Local store derives its base URI as ServerUrl(BasePath('files')) when
 * file_store.local.base_uri is not configured — which is the default. In a background
 * job there is no request, and Laminas' ServerUrl helper then returns the literal
 * string "http://". Measured on this installation:
 *
 *     ServerUrl()                  === 'http://'
 *     ServerUrl(BasePath('files')) === 'http:///files'
 *
 * So a stock install with media entries switched on would publish
 * "http:///files/original/x.jpg" to every crawler that reads the sitemap. Not a
 * plausible failure — the observed one.
 *
 * This is the same class of bug as the AdvancedMedia base-URI hazard, and the reason
 * it needs its own fix is that it affects the GENERIC installation this module is
 * written for, where no proxying module is involved at all. ProxyBaseUriCheck guards
 * the S3-proxy case because that store refuses rather than emitting nonsense; the
 * Local store has no such guard and will happily emit it.
 *
 * Pinning rather than patching the store keeps this module out of another module's
 * business: we set the context correctly and let every store — stock, proxying, or
 * one we have never heard of — derive whatever it derives from a base URL that is now
 * true.
 *
 * ⚠️ ORDER MATTERS. Laminas memoises services, so the store must not have been built
 * before this runs. The pipeline pins during preflight, well before anything touches
 * media. In a web request the helpers are already correct, so pinning is a no-op in
 * practice and the restore puts back exactly what was there.
 */
class PublicUrlContext
{
    /** @var HelperPluginManager */
    private $helpers;

    /** @var array|null Saved state, for restore(). */
    private $saved;

    public function __construct(HelperPluginManager $helpers)
    {
        $this->helpers = $helpers;
    }

    public function pin(UrlBuilder $urls): void
    {
        $parts = parse_url($urls->base());
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return;
        }

        $serverUrl = $this->helpers->get('ServerUrl');
        $basePath = $this->helpers->get('BasePath');

        $this->saved = [
            'scheme' => $serverUrl->getScheme(),
            'port' => $serverUrl->getPort(),
            'host' => $serverUrl->getHost(),
            // BasePath exposes no getter, and invoking it throws when unset (which is
            // exactly the CLI case), so read it defensively.
            'basePath' => $this->currentBasePath($basePath),
        ];

        $this->apply($serverUrl, $parts['scheme'], $parts['port'] ?? null, $parts['host']);

        // A base URL may carry a subdirectory (Omeka installed under /omeka-s); that
        // subdirectory IS the base path as far as every URL helper is concerned.
        $basePath->setBasePath(isset($parts['path']) ? rtrim($parts['path'], '/') : '');
    }

    /**
     * ⚠️ ORDER IS LOAD-BEARING, and the API is sharp in two places.
     *
     *   - setPort() casts to int, so setPort(null) stores 0 — not "no port".
     *   - setHost() reads the CURRENT scheme and port, and appends ":{port}" unless
     *     the port is null or the scheme's default.
     *
     * Set them in the wrong order, or pass a null port, and you get "https://host:0".
     * So a URL with no explicit port is pinned using that scheme's default port, which
     * is precisely the value setHost() knows to omit.
     */
    private function apply($serverUrl, string $scheme, $port, string $host): void
    {
        $serverUrl->setScheme($scheme);
        $serverUrl->setPort($port !== null ? (int) $port : $this->defaultPort($scheme));
        $serverUrl->setHost($host);
    }

    private function defaultPort(string $scheme): int
    {
        return strtolower($scheme) === 'https' ? 443 : 80;
    }

    public function restore(): void
    {
        if ($this->saved === null) {
            return;
        }

        $serverUrl = $this->helpers->get('ServerUrl');
        $scheme = (string) $this->saved['scheme'];
        // getHost() returns the composed host, which may already carry ":port";
        // setHost() would append it a second time, so strip it before restoring.
        $host = (string) $this->saved['host'];
        if (preg_match('/^(?P<host>.*?):(?P<port>\\d+)$/', $host, $m)) {
            $host = $m['host'];
        }
        $this->apply($serverUrl, $scheme, $this->saved['port'], $host);

        $this->helpers->get('BasePath')->setBasePath((string) $this->saved['basePath']);

        $this->saved = null;
    }

    private function currentBasePath($basePath): string
    {
        try {
            return (string) $basePath();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
