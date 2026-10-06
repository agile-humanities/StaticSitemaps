<?php
namespace StaticSitemaps\Service\Robots;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use StaticSitemaps\Robots\RobotsFile;

class RobotsFileFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new RobotsFile(OMEKA_PATH);
    }
}
