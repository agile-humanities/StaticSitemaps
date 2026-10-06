<?php
namespace StaticSitemaps\Robots;

/**
 * Reads, parses and (optionally) writes robots.txt.
 *
 * The parser implements the parts of the convention that actually affect us:
 * user-agent grouping, Allow taking precedence over Disallow at equal specificity,
 * and the '*' and '$' wildcards. It is not a general-purpose robots implementation
 * and does not try to be — it exists to answer two questions for the audit
 * (RobotsAuditor), not to police crawlers.
 */
class RobotsFile
{
    /** @var string Absolute path, whether or not it exists. */
    private $path;

    /** @var string|null */
    private $contents;

    public function __construct(string $omekaPath)
    {
        $this->path = rtrim($omekaPath, '/') . '/robots.txt';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function isWritable(): bool
    {
        return $this->exists()
            ? is_writable($this->path)
            : is_writable(dirname($this->path));
    }

    public function contents(): string
    {
        if ($this->contents === null) {
            $this->contents = $this->exists() ? (string) @file_get_contents($this->path) : '';
        }

        return $this->contents;
    }

    /** Every Sitemap: directive in the file, in order. */
    public function sitemapDirectives(): array
    {
        $found = [];
        foreach ($this->lines() as $line) {
            if (preg_match('/^\s*sitemap\s*:\s*(\S+)\s*$/i', $line, $m)) {
                $found[] = $m[1];
            }
        }

        return $found;
    }

    /**
     * Disallow/Allow rules that apply to the wildcard user-agent group.
     *
     * We check the '*' group specifically because that is what governs the general
     * crawler population a sitemap is addressed to. A rule aimed at one named bot is
     * a deliberate, targeted decision and not a contradiction worth flagging.
     *
     * @return array<int, array{type: string, pattern: string}>
     */
    public function wildcardRules(): array
    {
        $rules = [];
        $inWildcardGroup = false;
        $groupHasAgents = false;

        foreach ($this->lines() as $line) {
            if (preg_match('/^\s*user-agent\s*:\s*(\S+)/i', $line, $m)) {
                // Consecutive User-agent lines share one group of rules.
                if (!$groupHasAgents) {
                    $inWildcardGroup = false;
                }
                $groupHasAgents = true;
                if (trim($m[1]) === '*') {
                    $inWildcardGroup = true;
                }
                continue;
            }

            if (preg_match('/^\s*(dis)?allow\s*:\s*(\S*)\s*$/i', $line, $m)) {
                $groupHasAgents = false;
                if ($inWildcardGroup && $m[2] !== '') {
                    $rules[] = [
                        'type' => strtolower($m[1] ?? '') === 'dis' ? 'disallow' : 'allow',
                        'pattern' => $m[2],
                    ];
                }
            }
        }

        return $rules;
    }

    /**
     * Would a crawler in the '*' group be told not to fetch this path?
     *
     * Longest matching pattern wins; Allow wins ties, per the usual convention.
     */
    public function blocks(string $path): bool
    {
        $best = null;
        $bestLength = -1;

        foreach ($this->wildcardRules() as $rule) {
            if (!$this->patternMatches($rule['pattern'], $path)) {
                continue;
            }
            $length = strlen($rule['pattern']);
            if ($length > $bestLength || ($length === $bestLength && $rule['type'] === 'allow')) {
                $best = $rule['type'];
                $bestLength = $length;
            }
        }

        return $best === 'disallow';
    }

    private function patternMatches(string $pattern, string $path): bool
    {
        $anchored = substr($pattern, -1) === '$';
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        $regex = '';
        foreach (str_split($pattern) as $char) {
            $regex .= $char === '*' ? '.*' : preg_quote($char, '#');
        }
        $regex = '#^' . $regex . ($anchored ? '$' : '') . '#';

        return (bool) preg_match($regex, $path);
    }

    /**
     * Append a Sitemap: directive, idempotently.
     *
     * Never duplicates an existing directive for the same URL, and never rewrites
     * anything already in the file — robots.txt is frequently hand-maintained and
     * often version-controlled, so this touches as little as possible.
     *
     * @return array{changed: bool, message: string}
     */
    public function appendSitemap(string $sitemapUrl): array
    {
        foreach ($this->sitemapDirectives() as $existing) {
            if (rtrim($existing, '/') === rtrim($sitemapUrl, '/')) {
                return ['changed' => false, 'message' => 'That Sitemap directive is already present.']; // @translate
            }
        }

        if (!$this->isWritable()) {
            return ['changed' => false, 'message' => 'robots.txt is not writable.']; // @translate
        }

        $current = $this->contents();
        $prefix = ($current === '' || substr($current, -1) === "\n") ? '' : "\n";
        $line = $prefix . sprintf("Sitemap: %s\n", $sitemapUrl);

        if (@file_put_contents($this->path, $line, FILE_APPEND) === false) {
            return ['changed' => false, 'message' => 'Could not write to robots.txt.']; // @translate
        }
        $this->contents = null;

        return ['changed' => true, 'message' => 'Sitemap directive added to robots.txt.']; // @translate
    }

    private function lines(): array
    {
        $contents = $this->contents();
        // Strip comments before parsing; a '#' can legitimately appear mid-line.
        $lines = preg_split('/\R/', $contents) ?: [];

        return array_map(function ($line) {
            $hash = strpos($line, '#');

            return $hash === false ? $line : substr($line, 0, $hash);
        }, $lines);
    }
}
