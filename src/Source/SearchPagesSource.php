<?php
namespace StaticSitemaps\Source;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Module\Manager as ModuleManager;
use Omeka\Settings\SiteSettings;
use StaticSitemaps\Generator\PathAssembler;
use StaticSitemaps\Generator\UrlEntry;

/**
 * Search landing pages provided by the AdvancedSearch module.
 *
 * These pages are the POINT of the exercise. The strategy this module serves is to
 * give crawlers an explicit map so that the expensive faceted discovery UIs can be
 * restricted at the network layer — and the landing page is the front door that has
 * to stay open for that trade to work.
 *
 * A SOFT integration (architecture §4): isAvailable() gates on the module being
 * active, and the configuration matrix hides the column entirely when it is not. No
 * part of the core sitemap depends on this class loading.
 *
 * We list every config targeted at the site (D10). AdvancedSearch already scopes its
 * configs per site through the advancedsearch_configs site setting, so that assignment
 * is the administrator's existing statement of intent; per-page opt-out is served by
 * the exclusion list rather than by a second, competing control here.
 *
 * These entries carry no <lastmod>. A search page has no meaningful modification time,
 * and inventing one from the run timestamp would be a lie told to every crawler on
 * every run (D13).
 */
class SearchPagesSource extends AbstractSource
{
    const MODULE = 'AdvancedSearch';

    /** @var ModuleManager */
    private $modules;

    /** @var SiteSettings */
    private $siteSettings;

    /** @var PathAssembler */
    private $paths;

    public function __construct(
        ApiManager $api,
        EntityManager $entityManager,
        ModuleManager $modules,
        SiteSettings $siteSettings,
        PathAssembler $paths
    ) {
        parent::__construct($api, $entityManager);
        $this->modules = $modules;
        $this->siteSettings = $siteSettings;
        $this->paths = $paths;
    }

    public function getId(): string
    {
        return 'search-pages';
    }

    public function getLabel(): string
    {
        return 'Advanced Search pages'; // @translate
    }

    public function isAvailable(): bool
    {
        $module = $this->modules->getModule(self::MODULE);

        return $module && $module->getState() === ModuleManager::STATE_ACTIVE;
    }

    public function countFor(SiteRepresentation $site): int
    {
        return count($this->slugsFor($site));
    }

    public function entriesFor(SiteRepresentation $site): iterable
    {
        foreach ($this->slugsFor($site) as $slug) {
            // AdvancedSearch registers these routes at runtime as
            // '/s/:site-slug/{slug}' (Module::addRoutes). They may not be registered
            // in every context this runs in, so the literal form is used rather than
            // route assembly — the pattern is fixed and carries no parameters.
            yield new UrlEntry(
                $this->paths->literal(sprintf('/s/%s/%s', $site->slug(), $slug))
            );
        }
    }

    /**
     * The search-config slugs assigned to this site, in the module's own order.
     *
     * @return string[]
     */
    private function slugsFor(SiteRepresentation $site): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        // Explicit target, no setTargetId() — see the note in ConfigOptions:
        // leaving the shared SiteSettings pointing at our site would leak into
        // whatever reads site settings next, including other modules.
        $assigned = $this->siteSettings->get('advancedsearch_configs', [], $site->id());
        if (!is_array($assigned) || !$assigned) {
            return [];
        }

        $slugs = [];
        foreach ($assigned as $configId) {
            try {
                $config = $this->api->read('search_configs', ['id' => $configId])->getContent();
            } catch (\Exception $e) {
                // A stale assignment pointing at a deleted config. Skip it rather than
                // failing the whole run for one dangling id.
                continue;
            }
            $slug = method_exists($config, 'slug') ? $config->slug() : null;
            if (is_string($slug) && $slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }
}
