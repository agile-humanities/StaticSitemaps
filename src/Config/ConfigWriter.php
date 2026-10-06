<?php
namespace StaticSitemaps\Config;

use Omeka\Settings\Settings;
use Omeka\Settings\SiteSettings;
use StaticSitemaps\Generator\UrlBuilder;
use StaticSitemaps\Source\Manager as SourceManager;
use StaticSitemaps\Target\Location;

/**
 * Writes the module's configuration.
 *
 * Two conventions carried over from AdvancedMedia, both learned the hard way:
 *
 *  1. **A checkbox cannot express three states.** An unchecked checkbox submits
 *     nothing, so an absent POST key is indistinguishable from "not shown". We only
 *     ever write per-site source flags for sites the form actually rendered, and we
 *     write BOTH '1' and '0' explicitly rather than relying on presence.
 *
 *  2. **Validate before storing, not at use.** A malformed base URL stored now becomes
 *     a failed job later, far from the person who typed it. It is rejected here, while
 *     they are still looking at the field.
 */
class ConfigWriter
{
    /** @var Settings */
    private $settings;

    /** @var SiteSettings */
    private $siteSettings;

    /** @var SourceManager */
    private $sources;

    public function __construct(Settings $settings, SiteSettings $siteSettings, SourceManager $sources)
    {
        $this->settings = $settings;
        $this->siteSettings = $siteSettings;
        $this->sources = $sources;
    }

    /**
     * @return string[] Validation errors; empty means everything was saved.
     */
    public function save(array $post): array
    {
        $errors = [];

        $baseUrl = trim((string) ($post[ConfigOptions::BASE_URL] ?? ''));
        if ($baseUrl !== '') {
            $error = UrlBuilder::validate($baseUrl);
            if ($error !== null) {
                $errors[] = $error;
            } else {
                $this->settings->set(ConfigOptions::BASE_URL, rtrim($baseUrl, '/'));
            }
        } else {
            $this->settings->set(ConfigOptions::BASE_URL, '');
        }

        $target = (string) ($post[ConfigOptions::TARGET] ?? Location::TARGET_DOCROOT);
        if (!array_key_exists($target, Location::labels())) {
            $target = Location::TARGET_DOCROOT;
        }
        $this->settings->set(ConfigOptions::TARGET, $target);

        foreach ([
            ConfigOptions::SINGLE_FILE,
            ConfigOptions::INCLUDE_MEDIA,
            ConfigOptions::MEDIA_PRIMARY_ONLY,
            ConfigOptions::MEDIA_ORIGINALS_ONLY,
            ConfigOptions::ALL_ITEMS,
            ConfigOptions::ALL_PAGES,
        ] as $key) {
            // This form always renders these, so absence is a real "unchecked".
            $this->settings->set($key, !empty($post[$key]));
        }

        $this->settings->set(ConfigOptions::EXCLUSIONS, (string) ($post[ConfigOptions::EXCLUSIONS] ?? ''));

        // Stored even while single-file mode supersedes it, so switching the mode off
        // restores the administrator's chosen value rather than silently resetting it.
        $maxEntries = (int) ($post[ConfigOptions::MAX_ENTRIES] ?? ConfigOptions::DEFAULT_MAX_ENTRIES);
        if ($maxEntries < 1 || $maxEntries > 50000) {
            $errors[] = 'URLs per file must be between 1 and 50,000 (the sitemap protocol limit).'; // @translate
        } else {
            $this->settings->set(ConfigOptions::MAX_ENTRIES, $maxEntries);
        }

        $this->saveMatrix($post);

        return $errors;
    }

    /**
     * Per-site source flags.
     *
     * Only sites present in the POST are touched. The form posts a hidden list of the
     * site ids it rendered, so a site that was not on screen — because it was created
     * after the page loaded, or is private — keeps whatever it had rather than being
     * silently cleared by its absence.
     */
    private function saveMatrix(array $post): void
    {
        $renderedSites = $post['staticsitemaps_rendered_sites'] ?? '';
        $siteIds = array_filter(array_map('intval', explode(',', (string) $renderedSites)));
        if (!$siteIds) {
            return;
        }

        $available = array_keys($this->sources->available());
        $posted = $post['staticsitemaps_site'] ?? [];

        foreach ($siteIds as $siteId) {
            $flags = [];
            foreach ($available as $sourceId) {
                $flags[$sourceId] = !empty($posted[$siteId][$sourceId]) ? '1' : '0';
            }
            // Explicit target rather than setTargetId(), so the shared SiteSettings
            // service is left exactly as we found it.
            $this->siteSettings->set(ConfigOptions::SITE_SOURCES, $flags, $siteId);
        }
    }

    public function saveRunReport(array $report): void
    {
        $this->settings->set(ConfigOptions::LAST_RUN, $report);
    }

    /** Records what is now on disk. Written only after a successful publish. */
    public function savePublished(array $state): void
    {
        $this->settings->set(ConfigOptions::PUBLISHED, $state);
    }
}
