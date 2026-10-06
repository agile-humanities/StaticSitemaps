<?php
namespace StaticSitemaps\Source;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\SiteRepresentation;

/**
 * Shared plumbing for the resource-backed sources.
 *
 * The two things worth having in one place are the paging loop and the public-only
 * query, because getting either wrong is expensive: an unpaged loop defeats the
 * streaming design, and a missing is_public publishes private content.
 */
abstract class AbstractSource implements UrlSourceInterface
{
    /** Rows fetched per API call. Large enough to be efficient, small enough to stay flat. */
    const PAGE_SIZE = 200;

    /** @var ApiManager */
    protected $api;

    /** @var EntityManager */
    protected $entityManager;

    public function __construct(ApiManager $api, EntityManager $entityManager)
    {
        $this->api = $api;
        $this->entityManager = $entityManager;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * The base query for a site. Public-only is applied HERE and re-checked later by
     * PrivacyFilter — see architecture §6.1 for why that redundancy is deliberate.
     */
    protected function baseQuery(SiteRepresentation $site): array
    {
        return [
            'site_id' => $site->id(),
            'is_public' => true,
        ];
    }

    protected function countResources(string $resourceName, array $query): int
    {
        $query['limit'] = 0;
        $response = $this->api->search($resourceName, $query, ['returnScalar' => 'id']);

        return (int) $response->getTotalResults();
    }

    /**
     * Page through a resource type, yielding representations.
     *
     * Sorted by id so paging is stable: an unsorted query can repeat or skip rows
     * between pages when the underlying data changes mid-run.
     *
     * @return iterable<\Omeka\Api\Representation\AbstractResourceEntityRepresentation>
     */
    protected function pageThrough(string $resourceName, array $query): iterable
    {
        $page = 1;
        do {
            $query['page'] = $page;
            $query['per_page'] = self::PAGE_SIZE;
            $query['sort_by'] = 'id';
            $query['sort_order'] = 'asc';

            $response = $this->api->search($resourceName, $query);
            $representations = $response->getContent();
            $count = count($representations);

            foreach ($representations as $representation) {
                yield $representation;
            }

            // ── Why this clear() is not optional ─────────────────────────────
            // Doctrine's identity map retains every entity the API hydrates, so
            // without it memory grows LINEARLY with the collection — measured at
            // ~4 MB per 1,000 items here, which is ~50 MB for this installation
            // and unbounded for a larger one. Streaming generators everywhere
            // else in the pipeline would be pointless while the ORM quietly held
            // on to every row behind them.
            //
            // Safe at this point because control only returns here once the
            // consumer has finished with the last entry of the page: filters,
            // enricher and writer have all run and the XML is already on disk.
            //
            // A full clear() is core's own pattern (Omeka\Job\IndexFulltextSearch
            // does the same, with the same reason), and Omeka's job dispatcher
            // expects it — it re-merges the job entity afterwards precisely
            // because jobs are known to clear.
            unset($representations, $response);
            $this->entityManager->clear();

            $page++;
        } while ($count === self::PAGE_SIZE);
    }

    /**
     * Omeka gives us a DateTime for modified; normalise the "never modified" case.
     */
    protected function modifiedOf($representation): ?\DateTimeInterface
    {
        $modified = $representation->modified();
        if ($modified instanceof \DateTimeInterface) {
            return $modified;
        }
        $created = $representation->created();

        return $created instanceof \DateTimeInterface ? $created : null;
    }
}
