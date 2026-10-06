<?php
namespace StaticSitemaps\Source;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use StaticSitemaps\Generator\PathAssembler;
use StaticSitemaps\Generator\UrlEntry;

/**
 * Item pages, at the site's own URL.
 *
 * Site membership is read VERBATIM (D7). We pass site_id to the API and emit what
 * comes back; we do not consult the site's item_pool, do not special-case an empty
 * pool, and do not deduplicate across sites (D8).
 *
 * That is a deliberate position, not an omission:
 *
 *   - Omeka resolves site_id by joining item_site, a materialised table. The pool is
 *     only the rule that populated it, so item_site IS what the site serves. Second-
 *     guessing it would make the sitemap disagree with the site.
 *   - An empty pool legitimately means "all items" (core's UpdateSiteItems runs the
 *     pool as an API query, and an empty query matches everything). On a single-site
 *     installation — the common case for this module's audience — that is normal.
 *   - Cross-site duplication is opted into per site (R3.3). A subsite that shares
 *     items with a parent is frequently meant to stand alone, so redundancy is a
 *     feature the administrator chooses, not a defect for us to correct.
 *
 * What keeps this safe is R6: the configuration page shows the count each checkbox
 * will contribute BEFORE it is saved, so a site carrying thousands of items announces
 * itself rather than surprising anyone.
 */
class ItemsSource extends AbstractSource
{
    /** @var PathAssembler */
    private $paths;

    public function __construct(ApiManager $api, EntityManager $entityManager, PathAssembler $paths)
    {
        parent::__construct($api, $entityManager);
        $this->paths = $paths;
    }

    public function getId(): string
    {
        return 'items';
    }

    public function getLabel(): string
    {
        return 'Items'; // @translate
    }

    public function countFor(SiteRepresentation $site): int
    {
        return $this->countResources('items', $this->baseQuery($site));
    }

    public function entriesFor(SiteRepresentation $site): iterable
    {
        foreach ($this->pageThrough('items', $this->baseQuery($site)) as $item) {
            $entry = new UrlEntry(
                // Not $item->siteUrl(): that routes through the Url view helper, which
                // prepends the router's base path and would double it against the
                // configured base URL. PathAssembler strips it (see that class).
                $this->paths->assemble('site/resource-id', [
                    'site-slug' => $site->slug(),
                    'controller' => 'item',
                    'id' => $item->id(),
                ]),
                $this->modifiedOf($item),
                $item->id()
            );
            $entry->setRepresentation($item);

            yield $entry;
        }
    }
}
