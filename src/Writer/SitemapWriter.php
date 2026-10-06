<?php
namespace StaticSitemaps\Writer;

use StaticSitemaps\Generator\UrlBuilder;
use StaticSitemaps\Generator\UrlEntry;

/**
 * Streams UrlEntry objects into one or more <urlset> files, rolling over at the
 * protocol limits.
 *
 * XMLWriter in streaming mode against a file handle — deliberately not DOMDocument
 * (which builds the whole tree in memory, defeating the pipeline's flat-memory
 * design) and deliberately not string concatenation (which invites escaping bugs in
 * exactly the values most likely to contain apostrophes and ampersands: titles).
 *
 * Rollover happens on EITHER protocol limit:
 *   - 50,000 URLs
 *   - 50 MB uncompressed
 * The byte limit is tracked from actual bytes written, not estimated from entry
 * counts, because entries carrying many media extensions are far larger than bare
 * ones and an estimate would be wrong precisely when it matters.
 */
class SitemapWriter
{
    const PROTOCOL_MAX_URLS = 50000;
    const PROTOCOL_MAX_BYTES = 50000000;

    /** Roll over a little early so a final large entry cannot cross the limit. */
    const BYTE_HEADROOM = 500000;

    const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    const NS_IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';
    const NS_VIDEO = 'http://www.google.com/schemas/sitemap-video/1.1';

    /** @var UrlBuilder */
    private $urls;

    /** @var string Directory files are written into (the staging dir). */
    private $dir;

    /** @var int */
    private $maxUrls;

    /** @var \XMLWriter|null */
    private $writer;

    /** @var resource|null */
    private $handle;

    /** @var string|null */
    private $currentFile;

    /** @var int */
    private $urlsInFile = 0;

    /** @var int */
    private $bytesInFile = 0;

    /** @var int */
    private $fileOrdinal = 0;

    /** @var string */
    private $basename;

    /** @var string[] Filenames written, in order. */
    private $written = [];

    /** @var int */
    private $totalUrls = 0;

    public function __construct(UrlBuilder $urls, string $dir, int $maxUrls = self::PROTOCOL_MAX_URLS)
    {
        $this->urls = $urls;
        $this->dir = rtrim($dir, '/');
        $this->maxUrls = max(1, min($maxUrls, self::PROTOCOL_MAX_URLS));
    }

    /**
     * Consume a stream of entries into files named "{basename}-{n}.xml".
     *
     * @param iterable<UrlEntry> $entries
     * @return int URLs written
     */
    public function write(string $basename, iterable $entries): int
    {
        $this->basename = $basename;
        $this->fileOrdinal = 0;
        $written = 0;

        foreach ($entries as $entry) {
            if ($this->writer === null || $this->shouldRoll()) {
                $this->close();
                $this->open();
            }
            $this->writeEntry($entry);
            $written++;
        }

        $this->close();

        return $written;
    }

    private function shouldRoll(): bool
    {
        return $this->urlsInFile >= $this->maxUrls
            || $this->bytesInFile >= (self::PROTOCOL_MAX_BYTES - self::BYTE_HEADROOM);
    }

    private function open(): void
    {
        $this->fileOrdinal++;
        $this->currentFile = sprintf('%s-%d.xml', $this->basename, $this->fileOrdinal);
        $path = $this->dir . '/' . $this->currentFile;

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not open "%s" for writing.', $path));
        }
        $this->handle = $handle;

        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', self::NS_SITEMAP);
        // The media namespaces are declared unconditionally on files that may carry
        // them. Declaring an unused namespace is harmless; omitting a used one is not,
        // and we cannot know what a streamed entry will hold before we reach it.
        $writer->writeAttribute('xmlns:image', self::NS_IMAGE);
        $writer->writeAttribute('xmlns:video', self::NS_VIDEO);

        $this->writer = $writer;
        $this->urlsInFile = 0;
        $this->bytesInFile = 0;
        $this->flush();
    }

    private function writeEntry(UrlEntry $entry): void
    {
        $w = $this->writer;
        $w->startElement('url');
        $w->writeElement('loc', $this->urls->absolutise($entry->path()));

        $lastmod = $entry->lastmod();
        if ($lastmod instanceof \DateTimeInterface) {
            // W3C datetime. Never synthesised from the run time (D13) — an entry with
            // no real timestamp simply omits the element.
            $w->writeElement('lastmod', $lastmod->format('c'));
        }

        foreach ($entry->images() as $image) {
            // <image:loc> is the only image field search engines still read.
            $w->startElement('image:image');
            $w->writeElement('image:loc', $image['loc']);
            $w->endElement();
        }

        foreach ($entry->videos() as $video) {
            // thumbnail_loc, title and description are all REQUIRED by the video
            // sitemap spec. The enricher skips any video it cannot fill them for, so
            // anything reaching here is complete.
            $w->startElement('video:video');
            $w->writeElement('video:thumbnail_loc', $video['thumbnail']);
            $w->writeElement('video:title', $video['title']);
            $w->writeElement('video:description', $video['title']);
            $w->writeElement('video:content_loc', $video['loc']);
            $w->endElement();
        }

        $w->endElement();

        $this->urlsInFile++;
        $this->totalUrls++;
        $this->flush();
    }

    /**
     * Move XMLWriter's memory buffer to disk and account for the bytes.
     *
     * Flushing per entry is what keeps memory flat: without it XMLWriter accumulates
     * the entire document, and the streaming sources upstream would be pointless.
     */
    private function flush(): void
    {
        $chunk = $this->writer->flush(true);
        if ($chunk === '' || $chunk === false) {
            return;
        }
        $bytes = fwrite($this->handle, $chunk);
        if ($bytes === false) {
            throw new \RuntimeException(sprintf('Write failed for "%s".', $this->currentFile));
        }
        $this->bytesInFile += $bytes;
    }

    private function close(): void
    {
        if ($this->writer === null) {
            return;
        }
        $this->writer->endElement();   // urlset
        $this->writer->endDocument();
        $this->flush();

        fflush($this->handle);
        fclose($this->handle);

        $this->written[] = $this->currentFile;
        $this->writer = null;
        $this->handle = null;
        $this->currentFile = null;
    }

    /** @return string[] Filenames written across all write() calls, in order. */
    public function writtenFiles(): array
    {
        return $this->written;
    }

    public function totalUrls(): int
    {
        return $this->totalUrls;
    }
}
