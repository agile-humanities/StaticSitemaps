<?php
namespace StaticSitemaps\Config;

use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;
use Omeka\Settings\SiteSettings;
use StaticSitemaps\Source\Manager as SourceManager;

/**
 * Typed reads of the module's configuration, and the view model for the config page.
 *
 * Two responsibilities in one class, following the pattern AdvancedMedia established:
 * the generation pipeline needs typed getters, and the configuration page needs a
 * fully-built model including live counts. Both must read the settings identically,
 * so they read them here.
 */
class ConfigOptions
{
    const BASE_URL = 'staticsitemaps_base_url';
    const TARGET = 'staticsitemaps_target';
    const INCLUDE_MEDIA = 'staticsitemaps_include_media';
    const MEDIA_PRIMARY_ONLY = 'staticsitemaps_media_primary_only';
    const MEDIA_ORIGINALS_ONLY = 'staticsitemaps_media_originals_only';
    const ALL_ITEMS = 'staticsitemaps_all_items';
    const ALL_PAGES = 'staticsitemaps_all_pages';
    const EXCLUSIONS = 'staticsitemaps_exclusions';
    const MAX_ENTRIES = 'staticsitemaps_max_entries';
    const SINGLE_FILE = 'staticsitemaps_single_file';
    const LAST_RUN = 'staticsitemaps_last_run';

    /**
     * What is CURRENTLY ON DISK, as distinct from what the last run did.
     *
     * Kept separate from LAST_RUN on purpose. A failed run must still record that it
     * failed, but it must NOT claim to have published anything — otherwise the record of
     * where the working sitemap actually lives is destroyed by the failure, and the next
     * successful run cannot clean up the old location when the target changes.
     */
    const PUBLISHED = 'staticsitemaps_published';

    /** Per-SITE key holding the enabled source ids for that site. */
    const SITE_SOURCES = 'staticsitemaps_sources';

    const INDEX_FILE = 'sitemap.xml';

    const DEFAULT_MAX_ENTRIES = 50000;

    /** @var Settings */
    private $settings;

    /** @var SiteSettings */
    private $siteSettings;

    /** @var SourceManager */
    private $sources;

    /** @var ApiManager */
    private $api;

    public function __construct(
        Settings $settings,
        SiteSettings $siteSettings,
        SourceManager $sources,
        ApiManager $api
    ) {
        $this->settings = $settings;
        $this->siteSettings = $siteSettings;
        $this->sources = $sources;
        $this->api = $api;
    }

    public function baseUrl(): string
    {
        return trim((string) $this->settings->get(self::BASE_URL, ''));
    }

    public function target(): string
    {
        return (string) $this->settings->get(self::TARGET, 'docroot');
    }

    public function includeMedia(): bool
    {
        return (bool) $this->settings->get(self::INCLUDE_MEDIA, false);
    }

    public function mediaPrimaryOnly(): bool
    {
        return (bool) $this->settings->get(self::MEDIA_PRIMARY_ONLY, true);
    }

    public function mediaOriginalsOnly(): bool
    {
        return (bool) $this->settings->get(self::MEDIA_ORIGINALS_ONLY, true);
    }

    public function allItems(): bool
    {
        return (bool) $this->settings->get(self::ALL_ITEMS, false);
    }

    public function allPages(): bool
    {
        return (bool) $this->settings->get(self::ALL_PAGES, false);
    }

    public function exclusions(): string
    {
        return (string) $this->settings->get(self::EXCLUSIONS, '');
    }

    /**
     * Prefer one sitemap file over an index plus children.
     *
     * Off by default. It is a layout PREFERENCE, not a guarantee: the per-file limit
     * still governs, so a collection that outgrows one file is split into an index
     * regardless — with a warning in the run report, since the layout the administrator
     * asked for is not the one they ended up with.
     *
     * The per-file limit deliberately stays in force here rather than being overridden.
     * It is the safety valve for file size, not merely a splitting preference, and an
     * administrator who capped it at 10,000 for their CDN did not stop meaning it
     * because they also asked for one file where possible.
     */
    public function singleFile(): bool
    {
        return (bool) $this->settings->get(self::SINGLE_FILE, false);
    }

    public function maxEntries(): int
    {
        $value = (int) $this->settings->get(self::MAX_ENTRIES, self::DEFAULT_MAX_ENTRIES);

        return $value > 0 ? $value : self::DEFAULT_MAX_ENTRIES;
    }

    public function lastRun(): ?array
    {
        $report = $this->settings->get(self::LAST_RUN);

        return is_array($report) ? $report : null;
    }

    /** Where the currently-published sitemap lives, or null if nothing is published. */
    public function published(): ?array
    {
        $state = $this->settings->get(self::PUBLISHED);

        return is_array($state) ? $state : null;
    }

    /**
     * Source ids enabled for a site, honouring the global overrides.
     *
     * The global "all items"/"all site pages" switches SUPERSEDE the per-site matrix
     * (R3.5). They are resolved here, in one place, so the pipeline and the UI cannot
     * disagree about what is actually in effect — the UI shows the superseded controls
     * as disabled rather than quietly ignoring them.
     *
     * @return string[]
     */
    public function enabledSourcesFor(int $siteId): array
    {
        // The three-argument form, never setTargetId(): SiteSettings is a SHARED,
        // STATEFUL service, and setTargetId() leaves it pointing at whatever site we
        // looked at last. Anything that reads site settings after us — another module,
        // a view helper, our own later code — would silently get that site instead of
        // its own. Omeka's get($id, $default, $targetId) sets the target, reads, and
        // restores it, which is the only form that composes safely.
        $stored = $this->siteSettings->get(self::SITE_SOURCES, [], $siteId);
        $enabled = [];

        foreach (array_keys($this->sources->available()) as $sourceId) {
            $on = is_array($stored) && !empty($stored[$sourceId]) && (string) $stored[$sourceId] === '1';

            if ($sourceId === 'items' && $this->allItems()) {
                $on = true;
            }
            if ($sourceId === 'site-pages' && $this->allPages()) {
                $on = true;
            }
            if ($on) {
                $enabled[] = $sourceId;
            }
        }

        return $enabled;
    }

    /** Is a source forced on by a global override, rather than chosen per site? */
    public function isForced(string $sourceId): bool
    {
        return ($sourceId === 'items' && $this->allItems())
            || ($sourceId === 'site-pages' && $this->allPages());
    }

    /**
     * Public sites, in id order.
     *
     * Only public sites are ever enumerated: a private site has no public URL space,
     * so nothing in it can belong in a sitemap (R5).
     *
     * @return \Omeka\Api\Representation\SiteRepresentation[]
     */
    public function publicSites(): array
    {
        return $this->api->search('sites', [
            'is_public' => true,
            'sort_by' => 'id',
            'sort_order' => 'asc',
        ])->getContent();
    }

    /**
     * The configuration matrix: every public site crossed with every available source,
     * carrying the count each checkbox would contribute (R6).
     *
     * The counts are what make verbatim listing safe (D7): we never overrule an
     * administrator about their own site's contents, but we never let them enable
     * something blind either. A site carrying thousands of items announces itself here.
     */
    public function matrix(): array
    {
        $sources = $this->sources->available();
        $rows = [];

        foreach ($this->publicSites() as $site) {
            $enabled = $this->enabledSourcesFor($site->id());
            $cells = [];

            foreach ($sources as $sourceId => $source) {
                $cells[$sourceId] = [
                    'label' => $source->getLabel(),
                    'enabled' => in_array($sourceId, $enabled, true),
                    'forced' => $this->isForced($sourceId),
                    'count' => $source->countFor($site),
                ];
            }

            $rows[] = [
                'site' => $site,
                'cells' => $cells,
                'total' => array_sum(array_map(function ($cell) {
                    return $cell['enabled'] ? $cell['count'] : 0;
                }, $cells)),
            ];
        }

        return ['sources' => $sources, 'rows' => $rows];
    }

    public function settings(): Settings
    {
        return $this->settings;
    }
}
