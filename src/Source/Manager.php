<?php
namespace StaticSitemaps\Source;

use Omeka\ServiceManager\AbstractPluginManager;

/**
 * The source registry.
 *
 * A standard Omeka plugin manager, registered through module config under
 * 'staticsitemaps_url_sources' — the same pattern core uses for
 * resource_page_block_layouts and AdvancedMedia uses for players.
 *
 * Adding a content type is one config entry. The configuration matrix is generated
 * from this registry, so a new source needs no UI work, and another module can
 * contribute one without touching this one.
 */
class Manager extends AbstractPluginManager
{
    protected $instanceOf = UrlSourceInterface::class;

    /**
     * Every registered source, keyed by id, in registration order.
     *
     * @return UrlSourceInterface[]
     */
    public function all(): array
    {
        $sources = [];
        foreach ($this->getRegisteredNames() as $name) {
            $sources[$name] = $this->get($name);
        }

        return $sources;
    }

    /**
     * Only the sources usable in this installation.
     *
     * This is what the configuration matrix iterates. A source whose optional module
     * is absent must not appear as a control the administrator can switch on — that
     * is the visible half of the soft-integration rule (architecture §4).
     *
     * @return UrlSourceInterface[]
     */
    public function available(): array
    {
        return array_filter($this->all(), function (UrlSourceInterface $source) {
            return $source->isAvailable();
        });
    }
}
