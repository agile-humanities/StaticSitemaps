<?php
namespace StaticSitemaps\Service\Config;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use StaticSitemaps\Config\ConfigWriter;
use StaticSitemaps\Source\Manager as SourceManager;

class ConfigWriterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ConfigWriter(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Settings\Site'),
            $services->get(SourceManager::class)
        );
    }
}
