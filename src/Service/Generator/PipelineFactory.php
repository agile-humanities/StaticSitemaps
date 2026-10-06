<?php
namespace StaticSitemaps\Service\Generator;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use StaticSitemaps\Config\ConfigOptions;
use StaticSitemaps\Config\ConfigWriter;
use StaticSitemaps\Generator\Pipeline;
use StaticSitemaps\Generator\PublicUrlContext;
use StaticSitemaps\Source\Manager as SourceManager;
use StaticSitemaps\Target\ProxyBaseUriCheck;
use StaticSitemaps\Target\TargetProbe;

class PipelineFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new Pipeline(
            $services->get(ConfigOptions::class),
            $services->get(ConfigWriter::class),
            $services->get(SourceManager::class),
            $services->get(TargetProbe::class),
            $services->get(ProxyBaseUriCheck::class),
            new PublicUrlContext($services->get('ViewHelperManager')),
            OMEKA_PATH
        );
    }
}
