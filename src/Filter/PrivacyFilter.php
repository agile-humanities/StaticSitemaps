<?php
namespace StaticSitemaps\Filter;

use StaticSitemaps\Generator\UrlEntry;

/**
 * The second of two privacy checks. Both are deliberate (architecture §6.1).
 *
 * Sources already pass is_public => true. This re-checks each entry before it can be
 * written. The redundancy is not defensive habit — it is the specific lesson from how
 * Omeka stores site membership:
 *
 *   Core's Omeka\Job\UpdateSiteItems populates the item_site table with view-all
 *   privileges EXPLICITLY GRANTED — its own comment reads "Grant 'view-all'
 *   privileges to include private items". So item_site contains private items. On the
 *   installation this was designed against, 823 of the 12,820 rows on public sites
 *   reference a private item.
 *
 * And a generation job runs under a real user's identity, so the API would happily
 * hand those back if a query were ever built without is_public. The rule this class
 * enforces is therefore:
 *
 *   PRIVACY MUST NEVER BE AN EMERGENT PROPERTY OF THE CALLER'S PRIVILEGES.
 *
 * The cost is one boolean per entry. The failure it prevents is publishing the
 * existence of deliberately private material to every crawler on the internet, in a
 * file that is then edge-cached — which is not a failure anyone gets to take back.
 */
class PrivacyFilter
{
    /** @var int */
    private $rejected = 0;

    /**
     * @param iterable<UrlEntry> $entries
     * @return iterable<UrlEntry>
     */
    public function __invoke(iterable $entries): iterable
    {
        foreach ($entries as $entry) {
            if (!$this->isPublishable($entry)) {
                $this->rejected++;
                continue;
            }

            yield $entry;
        }
    }

    private function isPublishable(UrlEntry $entry): bool
    {
        $representation = $entry->representation();
        if ($representation === null) {
            // Entries with no backing resource (search landing pages) have nothing to
            // be private ABOUT — their visibility is the site's, and only public sites
            // are ever enumerated.
            return true;
        }

        if (method_exists($representation, 'isPublic') && !$representation->isPublic()) {
            return false;
        }

        return true;
    }

    public function rejectedCount(): int
    {
        return $this->rejected;
    }

    public function reset(): void
    {
        $this->rejected = 0;
    }
}
