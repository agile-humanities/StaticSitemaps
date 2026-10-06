<?php
namespace StaticSitemaps;

use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use StaticSitemaps\Config\ConfigOptions;
use StaticSitemaps\Config\ConfigWriter;
use StaticSitemaps\Job\GenerateSitemaps;
use StaticSitemaps\Robots\RobotsAuditor;
use StaticSitemaps\Robots\RobotsFile;
use StaticSitemaps\Source\Manager as SourceManager;
use StaticSitemaps\Target\Location;
use StaticSitemaps\Target\ProxyBaseUriCheck;
use StaticSitemaps\Target\TargetProbe;

/**
 * Static Sitemaps.
 *
 * Writes sitemap XML to disk so that it is served by the web server with no PHP in the
 * request path, giving legitimate crawlers a cheap, complete map of the content — which
 * is what makes it safe to restrict discovery UIs at the network layer.
 *
 * See docs/goals.md for why, docs/architecture.md for how.
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * The global configuration form.
     *
     * Omeka renders whatever HTML we return inside its own <form> with a CSRF field,
     * and hands the raw POST back to handleConfigForm().
     *
     * Every diagnostic is refreshed on each load rather than cached from the last save,
     * because all of them describe the CURRENT environment: a directory's permissions,
     * whether another module's setting is pinned, what robots.txt says today. A stale
     * "all clear" would be worse than no check at all.
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $options = $services->get(ConfigOptions::class);

        $location = new Location($options->target(), OMEKA_PATH);
        $baseUrl = $options->baseUrl();

        $probe = $services->get(TargetProbe::class);
        $writable = $probe->checkWritable($location);

        $served = null;
        if ($writable['writable'] && $baseUrl !== '') {
            $served = $probe->checkServed($location, $baseUrl);
        }

        $indexUrl = $baseUrl !== ''
            ? rtrim($baseUrl, '/') . $location->indexPath(ConfigOptions::INDEX_FILE)
            : '';

        $robots = null;
        if ($indexUrl !== '') {
            $robots = $services->get(RobotsAuditor::class)
                ->audit($indexUrl, $this->samplePaths($services, $options));
        }

        return $renderer->partial('common/config/static-sitemaps', [
            'options' => $options,
            'matrix' => $options->matrix(),
            'location' => $location,
            'writable' => $writable,
            'served' => $served,
            'indexUrl' => $indexUrl,
            'sitemapDirective' => $indexUrl !== '' ? sprintf('Sitemap: %s', $indexUrl) : '',
            'robots' => $robots,
            'robotsFile' => $services->get(RobotsFile::class),
            'proxyCheck' => $services->get(ProxyBaseUriCheck::class)->check($options->includeMedia()),
            'lastRun' => $options->lastRun(),
        ]);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $post = $controller->params()->fromPost();
        $writer = $services->get(ConfigWriter::class);

        $errors = $writer->save($post);
        foreach ($errors as $error) {
            $controller->messenger()->addError($error);
        }
        if ($errors) {
            return false;
        }

        $options = $services->get(ConfigOptions::class);

        // Append the Sitemap: directive to robots.txt, if asked. Separate from saving
        // so it is an explicit act rather than a side effect of pressing Save.
        if (!empty($post['staticsitemaps_append_robots'])) {
            $location = new Location($options->target(), OMEKA_PATH);
            $indexUrl = rtrim($options->baseUrl(), '/') . $location->indexPath(ConfigOptions::INDEX_FILE);
            $result = $services->get(RobotsFile::class)->appendSitemap($indexUrl);
            $result['changed']
                ? $controller->messenger()->addSuccess($result['message'])
                : $controller->messenger()->addWarning($result['message']);
        }

        if (!empty($post['staticsitemaps_generate'])) {
            $this->dispatchGeneration($controller);
        }

        $controller->messenger()->addSuccess('Sitemap settings updated.'); // @translate

        return true;
    }

    private function dispatchGeneration(AbstractController $controller): void
    {
        $services = $this->getServiceLocator();
        $options = $services->get(ConfigOptions::class);

        // Fail fast, in front of the person who pressed the button. The job would
        // report the same thing, but on a page they would have to go and find.
        if ($options->baseUrl() === '') {
            $controller->messenger()->addError(
                'Set a base URL before generating: a background job has no request to detect one from.' // @translate
            );

            return;
        }

        $job = $services->get('Omeka\Job\Dispatcher')->dispatch(GenerateSitemaps::class);
        $controller->messenger()->addSuccess(sprintf(
            'Sitemap generation started (job %d).', // @translate
            $job->getId()
        ));
    }

    /**
     * One representative local path per enabled source, for the robots.txt audit.
     *
     * A sample is enough because the answer is a property of the RULES, not of any
     * individual URL — testing thousands would give the same answer far more slowly.
     */
    private function samplePaths($services, ConfigOptions $options): array
    {
        $sources = $services->get(SourceManager::class)->available();
        $samples = [];

        foreach ($options->publicSites() as $site) {
            foreach ($options->enabledSourcesFor($site->id()) as $sourceId) {
                if (isset($samples[$sourceId]) || !isset($sources[$sourceId])) {
                    continue;
                }
                foreach ($sources[$sourceId]->entriesFor($site) as $entry) {
                    $samples[$sourceId] = $entry->path();
                    break; // The generator stops here; nothing further is queried.
                }
            }
        }

        return $samples;
    }
}
