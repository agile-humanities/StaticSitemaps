<?php
namespace StaticSitemaps\Writer;

use StaticSitemaps\Generator\UrlBuilder;

/**
 * Writes the sitemap index.
 *
 * ALWAYS emitted, even when there is a single child file (D12). The index URL is what
 * robots.txt advertises and what gets submitted to a search console, so it must never
 * change shape as a collection grows: an installation that starts with 40 URLs and
 * later crosses 50,000 would otherwise have its published URL silently change meaning.
 * One small extra file and one extra fetch buys that permanence.
 */
class IndexWriter
{
    const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** @var UrlBuilder */
    private $urls;

    /** @var string */
    private $dir;

    /** @var string Local path CHILDREN are served at, e.g. "" or "/sitemaps". */
    private $publicPrefix;

    public function __construct(UrlBuilder $urls, string $dir, string $publicPrefix)
    {
        $this->urls = $urls;
        $this->dir = rtrim($dir, '/');
        $this->publicPrefix = rtrim($publicPrefix, '/');
    }

    /**
     * @param string[] $childFiles Filenames, relative to the output directory.
     */
    public function write(string $filename, array $childFiles, \DateTimeInterface $lastmod): void
    {
        $path = $this->dir . '/' . $filename;
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not open "%s" for writing.', $path));
        }

        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('sitemapindex');
        $w->writeAttribute('xmlns', self::NS_SITEMAP);

        foreach ($childFiles as $child) {
            $w->startElement('sitemap');
            $w->writeElement('loc', $this->urls->absolutise($this->publicPrefix . '/' . $child));
            $w->writeElement('lastmod', $lastmod->format('c'));
            $w->endElement();
        }

        $w->endElement();
        $w->endDocument();
        fwrite($handle, $w->flush(true));
        fflush($handle);
        fclose($handle);
    }
}
