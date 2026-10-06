<?php
namespace StaticSitemaps\Service\Generator;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use StaticSitemaps\Generator\PathAssembler;

class PathAssemblerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new PathAssembler($services->get('Router'));
    }
}
