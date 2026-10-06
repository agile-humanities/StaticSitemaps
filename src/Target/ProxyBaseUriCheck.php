<?php
namespace StaticSitemaps\Target;

use Interop\Container\ContainerInterface;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Settings\Settings;

/**
 * Guards one specific cross-module hazard that would silently publish the wrong URLs.
 *
 * ── The failure being prevented ──────────────────────────────────────────────────
 * AdvancedMedia rewrites media URLs to same-origin /files/... paths, giving the
 * network layer one governable chokepoint for media traffic. Its proxying store
 * derives that base URI from the incoming HTTP request, and contains a guard: if the
 * base URI is not absolute (no scheme and host) it refuses to emit a broken URL and
 * SELF-DISABLES for that process, falling back to the raw store URL — an S3 bucket URL.
 *
 * Background jobs have no HTTP request, so that guard fires in exactly our situation
 * unless the base URI has been PINNED in settings.
 *
 * This is not hypothetical. It is precisely how S3 URLs were baked into this
 * installation's IIIF manifest cache and its Solr index; both were found only later,
 * by inspecting stored output. A sitemap makes the same failure worse, because the
 * wrong URLs are published to crawlers, indexed, and edge-cached — the media
 * chokepoint bypassed for exactly the traffic it exists to govern.
 *
 * ── Why this refuses rather than warns ───────────────────────────────────────────
 * Unlike reachability (TargetProbe), there is no ambiguity to interpret: the condition
 * is precisely detectable, the consequence is certain, and the fix is one settings
 * field. Warning and proceeding would publish known-wrong URLs.
 *
 * The check costs nothing where AdvancedMedia is absent — which is why the enricher
 * itself stays completely decoupled from that module (architecture §4) and the
 * coupling lives here, in one guard, instead.
 */
class ProxyBaseUriCheck
{
    const MODULE = 'AdvancedMedia';

    /** The store's own option names, mirrored so the check tests the real condition. */
    const PROXY_FILES_KEY = 'advancedmedia_proxy_files';
    const BASE_URI_KEY = 'advancedmedia_files_base_uri';

    const PROXY_STORE_CLASS = 'AdvancedMedia\File\Store\ProxyingAwsS3';

    /** @var ModuleManager */
    private $modules;

    /** @var Settings */
    private $settings;

    /** @var ContainerInterface */
    private $services;

    public function __construct(ModuleManager $modules, Settings $settings, ContainerInterface $services)
    {
        $this->modules = $modules;
        $this->settings = $settings;
        $this->services = $services;
    }

    /**
     * @param bool $mediaEnabled Whether this module will emit media URLs at all.
     * @return array{ok: bool, applicable: bool, message: string, detail: string}
     */
    public function check(bool $mediaEnabled): array
    {
        if (!$mediaEnabled) {
            return $this->notApplicable('Media entries are switched off.');
        }

        $module = $this->modules->getModule(self::MODULE);
        if (!$module || $module->getState() !== ModuleManager::STATE_ACTIVE) {
            return $this->notApplicable('AdvancedMedia is not active; media URLs come from the file store.');
        }

        if (!$this->settings->get(self::PROXY_FILES_KEY, false)) {
            return $this->notApplicable('AdvancedMedia is active but media URL proxying is off.');
        }

        // Proxying only applies to the S3-backed store. An installation with a Local
        // store already emits same-origin paths, so the flag is inert there and this
        // check must not refuse a perfectly sound configuration.
        if (!$this->proxyStoreIsInPlay()) {
            return $this->notApplicable(
                'Media URL proxying is enabled but the active file store is not the proxying S3 store.'
            );
        }

        $baseUri = trim((string) $this->settings->get(self::BASE_URI_KEY, ''));
        if (!$this->isAbsoluteUri($baseUri)) {
            return [
                'ok' => false,
                'applicable' => true,
                'message' => 'AdvancedMedia proxying is on, but its base URI is not pinned.', // @translate
                'detail' => sprintf(
                    'Sitemap generation runs as a background job, which has no HTTP request to '
                    . 'detect a base URI from. AdvancedMedia\'s proxying store self-disables in that '
                    . 'situation and falls back to raw storage URLs, so this run would publish bucket '
                    . 'URLs to crawlers instead of your proxied /files/ paths — bypassing the '
                    . 'chokepoint the proxy exists to provide. Set "%s" on the Advanced Media '
                    . 'configuration page to your public files URL (including the /files path), then '
                    . 'generate again. Current value: %s',
                    self::BASE_URI_KEY,
                    $baseUri === '' ? '(empty)' : $baseUri
                ),
            ];
        }

        return [
            'ok' => true,
            'applicable' => true,
            'message' => 'AdvancedMedia proxying is on and its base URI is pinned.', // @translate
            'detail' => $baseUri,
        ];
    }

    /**
     * Resolved lazily and defensively: building the S3 store throws when AWS
     * credentials are absent, which is a normal state on an install that has the
     * module but does not use it. That must not become an error here.
     */
    private function proxyStoreIsInPlay(): bool
    {
        if (!class_exists(self::PROXY_STORE_CLASS)) {
            return false;
        }
        try {
            $store = $this->services->get('Omeka\File\Store');
        } catch (\Exception $e) {
            return false;
        }

        return is_a($store, self::PROXY_STORE_CLASS);
    }

    private function isAbsoluteUri(string $uri): bool
    {
        if ($uri === '') {
            return false;
        }
        $parts = parse_url($uri);

        return $parts && !empty($parts['scheme']) && !empty($parts['host']);
    }

    private function notApplicable(string $detail): array
    {
        return ['ok' => true, 'applicable' => false, 'message' => '', 'detail' => $detail];
    }
}
