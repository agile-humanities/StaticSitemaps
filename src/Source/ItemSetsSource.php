<?php
namespace StaticSitemaps\Source;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use StaticSitemaps\Generator\PathAssembler;
use StaticSitemaps\Generator\UrlEntry;

/**
 * Item set pages.
 *
 * ⚠️ Item sets are scoped to a site through a DIFFERENT table from items:
 * ItemSetAdapter joins site_item_set (siteItemSets), while ItemAdapter joins
 * item_site. Passing site_id to the API inherits whichever is correct for each,
 * which is precisely why both sources query through the API rather than reaching
 * for a join of their own.
 *
 * Ships OFF by default (D6): item sets are the preferred path to items in some
 * installations and an unwanted duplicate layer in others, so the capability is
 * present and the default is conservative.
 */
class ItemSetsSource extends AbstractSource
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
        return 'item-sets';
    }

    public function getLabel(): string
    {
        return 'Item sets'; // @translate
    }

    public function countFor(SiteRepresentation $site): int
    {
        return $this->countResources('item_sets', $this->baseQuery($site));
    }

    public function entriesFor(SiteRepresentation $site): iterable
    {
        foreach ($this->pageThrough('item_sets', $this->baseQuery($site)) as $itemSet) {
            yield new UrlEntry(
                $this->paths->assemble('site/item-set', [
                    'site-slug' => $site->slug(),
                    'item-set-id' => $itemSet->id(),
                ]),
                $this->modifiedOf($itemSet),
                $itemSet->id()
            );
        }
    }
}
