<?php
namespace StaticSitemaps\Filter;

use StaticSitemaps\Generator\UrlEntry;

/**
 * Administrator-supplied path exclusions (R3.6, D11).
 *
 * Semantics, fixed deliberately so they are predictable rather than clever:
 *
 *   - PREFIX match. "/s/monsters" excludes everything beneath it.
 *   - Case-insensitive.
 *   - Trailing slashes normalised on both sides.
 *   - Matched against the LOCAL PATH, not the absolute URL — an administrator types
 *     what they see in the address bar, and rules stay valid if the base URL changes.
 *   - Blank lines and lines starting with '#' are ignored, so a rule can be commented
 *     out without being deleted.
 *
 * ⚠️ AN EXCLUSION GUIDES CRAWLERS; IT DOES NOT CONTROL THEM.
 * Omitting a URL from a sitemap does not block anything. A crawler that finds the URL
 * another way will still fetch it, and a hostile one was never reading the sitemap in
 * the first place. Blocking belongs in robots.txt (for the compliant) and at the CDN
 * or WAF (for everyone else). This warning is repeated in the UI next to the field,
 * because the conflation is the single most likely way for an administrator to believe
 * they have protected something they have not.
 *
 * Hits are counted PER RULE so the UI can report "this rule removed 0 URLs" — which is
 * how a typo announces itself instead of silently doing nothing forever.
 */
class ExclusionFilter
{
    /** @var string[] Normalised rule prefixes, keyed by the rule as typed. */
    private $rules = [];

    /** @var array<string, int> Hit count per rule as typed. */
    private $hits = [];

    public function __construct(string $raw)
    {
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $this->rules[$line] = $this->normalise($line);
            $this->hits[$line] = 0;
        }
    }

    /**
     * @param iterable<UrlEntry> $entries
     * @return iterable<UrlEntry>
     */
    public function __invoke(iterable $entries): iterable
    {
        if (!$this->rules) {
            yield from $entries;
            return;
        }

        foreach ($entries as $entry) {
            $path = $this->normalise($entry->path());
            $matched = null;

            foreach ($this->rules as $asTyped => $prefix) {
                if ($prefix === '' ) {
                    continue;
                }
                if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                    $matched = $asTyped;
                    break;
                }
            }

            if ($matched !== null) {
                $this->hits[$matched]++;
                continue;
            }

            yield $entry;
        }
    }

    private function normalise(string $path): string
    {
        $path = strtolower(trim($path));
        if ($path !== '' && $path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = rtrim($path, '/');

        return $path;
    }

    /** @return array<string, int> Hit count keyed by the rule exactly as typed. */
    public function hits(): array
    {
        return $this->hits;
    }

    public function totalRejected(): int
    {
        return array_sum($this->hits);
    }

    /** Rules that matched nothing — surfaced in the UI as probable typos. */
    public function unusedRules(): array
    {
        return array_keys(array_filter($this->hits, function ($n) {
            return $n === 0;
        }));
    }

    public function hasRules(): bool
    {
        return (bool) $this->rules;
    }
}
