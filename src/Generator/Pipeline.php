<?php
namespace StaticSitemaps\Generator;

use StaticSitemaps\Config\ConfigOptions;
use StaticSitemaps\Config\ConfigWriter;
use StaticSitemaps\Enricher\MediaEnricher;
use StaticSitemaps\Filter\ExclusionFilter;
use StaticSitemaps\Filter\PrivacyFilter;
use StaticSitemaps\Source\Manager as SourceManager;
use StaticSitemaps\Target\Location;
use StaticSitemaps\Target\ProxyBaseUriCheck;
use StaticSitemaps\Target\TargetProbe;
use StaticSitemaps\Writer\AtomicPublisher;
use StaticSitemaps\Writer\IndexWriter;
use StaticSitemaps\Writer\SitemapWriter;

/**
 * Composes the whole run:
 *
 *   SOURCES → FILTERS → ENRICHERS → WRITER → PUBLISH → REPORT
 *
 * Every stage is a generator, and that is a hard rule rather than an optimisation
 * (architecture §3). The largest realistic run is tens of thousands of entries, each
 * potentially carrying media; collecting them before writing would be a large array in
 * a process whose memory limit is a known-sensitive setting. Once any stage returns an
 * array the property is lost for the whole pipeline, and it is very hard to get back.
 *
 * Preflight is the other half of the design. Because the output is served statically,
 * with no application in the request path, there is no request-time error handling to
 * fall back on: a bad run produces a bad file that is then served and edge-cached. So
 * everything that can be checked is checked BEFORE the first byte is written, and a
 * failure refuses the run rather than publishing something wrong.
 */
class Pipeline
{
    /** @var ConfigOptions */
    private $options;

    /** @var SourceManager */
    private $sources;

    /** @var TargetProbe */
    private $probe;

    /** @var ProxyBaseUriCheck */
    private $proxyCheck;

    /** @var ConfigWriter */
    private $writer;

    /** @var PublicUrlContext */
    private $urlContext;

    /** @var string */
    private $omekaPath;

    public function __construct(
        ConfigOptions $options,
        ConfigWriter $writer,
        SourceManager $sources,
        TargetProbe $probe,
        ProxyBaseUriCheck $proxyCheck,
        PublicUrlContext $urlContext,
        string $omekaPath
    ) {
        $this->options = $options;
        $this->writer = $writer;
        $this->sources = $sources;
        $this->probe = $probe;
        $this->proxyCheck = $proxyCheck;
        $this->urlContext = $urlContext;
        $this->omekaPath = $omekaPath;
    }

    /**
     * @param callable|null $progress Receives a status string; used for job logging.
     */
    public function run(?callable $progress = null): RunReport
    {
        $report = new RunReport();
        $progress = $progress ?: function () {};

        try {
            $urls = $this->preflight($report);
            $location = new Location($this->options->target(), $this->omekaPath);
            $publisher = new AtomicPublisher($location);
            $staging = $publisher->beginStaging();

            try {
                $this->generate($urls, $location, $staging, $publisher, $report, $progress);
            } catch (\Throwable $e) {
                // The previous sitemap survives untouched — that is the whole point of
                // staging. Without this, a failure halfway through publishes a
                // truncated sitemap that the web server will happily serve.
                $publisher->abandonStaging();
                throw $e;
            }

            $report->succeed();
        } catch (\Throwable $e) {
            $report->fail($e);
        } finally {
            // Always restore: a Synchronous dispatch runs inside a web request, where
            // leaving the helpers pinned would alter every URL rendered afterwards.
            $this->urlContext->restore();
        }

        // Persisted HERE rather than by the caller. The next run's cleanup of a changed
        // target depends on this having been recorded, so it cannot be left to whichever
        // entry point happened to invoke us.
        $this->writer->saveRunReport($report->toArray());

        return $report;
    }

    /**
     * Everything that must be true before a single byte is written.
     */
    private function preflight(RunReport $report): UrlBuilder
    {
        // 1. Base URL. Refuses rather than guessing — a sitemap of localhost URLs is
        //    published, cached and read by crawlers before anyone notices (§10).
        $urls = new UrlBuilder($this->options->baseUrl());
        $report->set('baseUrl', $urls->base());

        // 1b. Pin ServerUrl/BasePath before anything can build the file store. Media
        //     URLs come from the store, and a stock Local store derives its base URI
        //     from ServerUrl — which in a job returns "http://". See PublicUrlContext.
        $this->urlContext->pin($urls);

        // 2. Writability. A hard gate: the failure must surface here, not inside a job.
        $location = new Location($this->options->target(), $this->omekaPath);
        $writable = $this->probe->checkWritable($location);
        if (!$writable['writable']) {
            throw new \RuntimeException($writable['message'] . ' ' . $writable['detail']);
        }
        $report->set('target', $location->target());
        $report->set('indexDirectory', $location->indexDirectory());
        $report->set('childDirectory', $location->childDirectory());
        $report->set('indexFile', ConfigOptions::INDEX_FILE);
        $report->set('indexUrl', $urls->absolutise($location->indexPath(ConfigOptions::INDEX_FILE)));

        // 3. The AdvancedMedia base-URI hazard. Also a hard gate, because the
        //    condition is precisely detectable and the consequence certain: publishing
        //    bucket URLs that bypass the media chokepoint entirely.
        $proxy = $this->proxyCheck->check($this->options->includeMedia());
        if (!$proxy['ok']) {
            throw new \RuntimeException($proxy['message'] . ' ' . $proxy['detail']);
        }

        return $urls;
    }

    private function generate(
        UrlBuilder $urls,
        Location $location,
        string $staging,
        AtomicPublisher $publisher,
        RunReport $report,
        callable $progress
    ): void {
        $privacy = new PrivacyFilter();
        $exclusions = new ExclusionFilter($this->options->exclusions());
        $enricher = $this->options->includeMedia()
            ? new MediaEnricher($this->options->mediaPrimaryOnly(), $this->options->mediaOriginalsOnly())
            : null;

        $writer = new SitemapWriter($urls, $staging, $this->options->maxEntries());
        $pairs = $this->enabledPairs();

        if (!$pairs) {
            $this->publishNothing($publisher, $report);

            return;
        }

        $singleFile = $this->options->singleFile();

        if ($singleFile) {
            // One continuous stream across every site and source, so the writer only
            // rolls over if the protocol limits force it to.
            $writer->write('sitemap-all', $this->streamAll($pairs, $privacy, $exclusions, $enricher, $report, $progress));
        } else {
            foreach ($pairs as [$site, $source]) {
                $progress(sprintf('Generating %s for site "%s"', $source->getLabel(), $site->slug()));
                $stream = $this->pipeFor($site, $source, $privacy, $exclusions, $enricher);
                $written = $writer->write(
                    sprintf('sitemap-%s-%s', $this->slugify($site->slug()), $source->getId()),
                    $stream
                );
                $report->addSiteResult($site->slug(), $source->getId(), $written);
            }
        }

        $children = $writer->writtenFiles();

        if (!$children) {
            $this->publishNothing($publisher, $report);

            return;
        }

        $previous = $this->options->published() ?: [];

        if ($singleFile && count($children) === 1) {
            // The whole sitemap fitted in one file: publish it AS sitemap.xml, a plain
            // <urlset> with no index. The PUBLISHED URL IS UNCHANGED either way — only
            // the document type at that URL differs — so nothing downstream breaks when
            // an installation later outgrows this and gets an index instead.
            rename($staging . '/' . $children[0], $staging . '/' . ConfigOptions::INDEX_FILE);
            $removed = $publisher->publish([], ConfigOptions::INDEX_FILE, $previous);
            $report->set('layout', 'single');
            $report->set('files', [ConfigOptions::INDEX_FILE]);
            $this->recordPublished($location, [ConfigOptions::INDEX_FILE]);
        } else {
            if ($singleFile) {
                // Asked for one file, could not have one. Say so plainly: the layout is
                // not what was configured, and an administrator who is not told will
                // reasonably assume the setting did not work.
                $report->addWarning(sprintf(
                    'A single sitemap was requested, but %s URLs exceed what one file may hold, '
                    . 'so an index with %d files was written instead.', // @translate
                    number_format($writer->totalUrls()),
                    count($children)
                ));
            }
            $indexWriter = new IndexWriter($urls, $staging, $location->childPublicPrefix());
            $indexWriter->write(ConfigOptions::INDEX_FILE, $children, new \DateTime());
            $removed = $publisher->publish($children, ConfigOptions::INDEX_FILE, $previous);
            $report->set('layout', 'index');
            $report->set('files', array_merge([ConfigOptions::INDEX_FILE], $children));
            $this->recordPublished($location, array_merge([ConfigOptions::INDEX_FILE], $children));
        }

        $report->set('totalUrls', $writer->totalUrls());
        $report->set('orphansRemoved', $removed);
        $report->set('excludedPrivate', $privacy->rejectedCount());
        $report->set('excludedByRule', $exclusions->hits());
        $report->set('unusedRules', $exclusions->unusedRules());
        if ($enricher) {
            $report->set('mediaImages', $enricher->imageCount());
            $report->set('mediaVideos', $enricher->videoCount());
        }
    }

    /**
     * Every enabled (site, source) pair, in site then source order.
     *
     * @return array<int, array{0: \Omeka\Api\Representation\SiteRepresentation, 1: object}>
     */
    private function enabledPairs(): array
    {
        $sources = $this->sources->available();
        $pairs = [];

        foreach ($this->options->publicSites() as $site) {
            foreach ($this->options->enabledSourcesFor($site->id()) as $sourceId) {
                if (isset($sources[$sourceId])) {
                    $pairs[] = [$site, $sources[$sourceId]];
                }
            }
        }

        return $pairs;
    }

    /** One site+source stream, filtered and enriched. */
    private function pipeFor($site, $source, PrivacyFilter $privacy, ExclusionFilter $exclusions, ?MediaEnricher $enricher): iterable
    {
        $stream = $source->entriesFor($site);
        $stream = $privacy($stream);
        $stream = $exclusions($stream);
        // Media decorate ITEM entries only — a page or a search landing page has no
        // media of its own to advertise.
        if ($enricher && $source->getId() === 'items') {
            $stream = $enricher($stream);
        }

        return $stream;
    }

    /**
     * All pairs as ONE lazy stream, for single-file mode.
     *
     * Still a generator: single-file mode changes the number of output files, never the
     * memory profile. Building a combined array here would undo the whole pipeline.
     */
    private function streamAll(array $pairs, PrivacyFilter $privacy, ExclusionFilter $exclusions, ?MediaEnricher $enricher, RunReport $report, callable $progress): iterable
    {
        foreach ($pairs as [$site, $source]) {
            $progress(sprintf('Generating %s for site "%s"', $source->getLabel(), $site->slug()));
            $count = 0;
            foreach ($this->pipeFor($site, $source, $privacy, $exclusions, $enricher) as $entry) {
                $count++;
                yield $entry;
            }
            $report->addSiteResult($site->slug(), $source->getId(), $count);
        }
    }

    /**
     * Nothing to publish.
     *
     * Replacing a good sitemap with a valid EMPTY one tells crawlers "there is no
     * content here" — considerably worse than staying silent. So we abandon staging and
     * leave whatever is there in place.
     */
    private function publishNothing(AtomicPublisher $publisher, RunReport $report): void
    {
        $publisher->abandonStaging();
        $report->addWarning(
            'Nothing was enabled, so no sitemap was written and any existing one was left in place.' // @translate
        );
        $report->set('totalUrls', 0);
    }

    /**
     * Record what is now on disk, so a later run can clean it up if the target changes.
     */
    private function recordPublished(Location $location, array $files): void
    {
        $this->writer->savePublished([
            'target' => $location->target(),
            'indexDirectory' => $location->indexDirectory(),
            'childDirectory' => $location->childDirectory(),
            'indexFile' => ConfigOptions::INDEX_FILE,
            'files' => $files,
        ]);
    }

    /** Site slugs are already URL-safe; this guards the filename pattern regardless. */
    private function slugify(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', $value) ?: 'site';
    }
}
