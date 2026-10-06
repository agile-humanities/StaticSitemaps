<?php
namespace StaticSitemaps\Source;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;
use StaticSitemaps\Generator\PathAssembler;
use StaticSitemaps\Generator\UrlEntry;

/**
 * The site's own pages.
 *
 * Note the lastmod caveat (goals §9): site_page.modified tracks the page RECORD, not
 * the resources displayed on it, so a listing page whose contents changed shows a
 * stale timestamp. We emit the real value anyway — a synthesised one would be worse.
 */
class SitePagesSource extends AbstractSource
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
        return 'site-pages';
    }

    public function getLabel(): string
    {
        return 'Pages'; // @translate
    }

    public function countFor(SiteRepresentation $site): int
    {
        return $this->countResources('site_pages', [
            'site_id' => $site->id(),
            'is_public' => true,
        ]);
    }

    public function entriesFor(SiteRepresentation $site): iterable
    {
        $query = ['site_id' => $site->id(), 'is_public' => true];

        foreach ($this->pageThrough('site_pages', $query) as $page) {
            yield new UrlEntry(
                $this->paths->assemble('site/page', [
                    'site-slug' => $site->slug(),
                    'page-slug' => $page->slug(),
                ]),
                $this->modifiedOf($page)
            );
        }
    }
}
