<?php
namespace StaticSitemaps\Generator;

/**
 * What a generation run did — the module's answer to Goal 5, "honest feedback".
 *
 * This is not logging. It is the record the configuration page displays, and it
 * deliberately reports what was LEFT OUT as prominently as what went in (R5.1):
 * silent omission is as unhelpful as silent inclusion, and an administrator who can
 * see "823 private items excluded" knows the number is intentional rather than
 * wondering where their content went.
 */
class RunReport
{
    /** @var array */
    private $data = [
        'startedAt' => null,
        'finishedAt' => null,
        'durationSeconds' => null,
        'ok' => false,
        'error' => null,
        'baseUrl' => null,
        'target' => null,
        'layout' => null,
        'indexDirectory' => null,
        'childDirectory' => null,
        'indexFile' => null,
        'indexUrl' => null,
        'totalUrls' => 0,
        'files' => [],
        'orphansRemoved' => [],
        'perSite' => [],
        'excludedPrivate' => 0,
        'excludedByRule' => [],
        'unusedRules' => [],
        'mediaImages' => 0,
        'mediaVideos' => 0,
        'warnings' => [],
    ];

    /** @var float */
    private $started;

    public function __construct()
    {
        $this->started = microtime(true);
        $this->data['startedAt'] = (new \DateTime())->format('c');
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function addSiteResult(string $siteSlug, string $sourceId, int $count): void
    {
        $this->data['perSite'][$siteSlug][$sourceId] = $count;
    }

    public function addWarning(string $warning): void
    {
        $this->data['warnings'][] = $warning;
    }

    public function fail(\Throwable $e): void
    {
        $this->data['ok'] = false;
        $this->data['error'] = $e->getMessage();
        $this->finish();
    }

    public function succeed(): void
    {
        $this->data['ok'] = true;
        $this->finish();
    }

    private function finish(): void
    {
        $this->data['finishedAt'] = (new \DateTime())->format('c');
        $this->data['durationSeconds'] = round(microtime(true) - $this->started, 2);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
