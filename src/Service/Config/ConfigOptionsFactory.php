<?php
namespace StaticSitemaps\Service\Config;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use StaticSitemaps\Config\ConfigOptions;
use StaticSitemaps\Source\Manager as SourceManager;

class ConfigOptionsFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ConfigOptions(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\Settings\Site'),
            $services->get(SourceManager::class),
            $services->get('Omeka\ApiManager')
        );
    }
}
