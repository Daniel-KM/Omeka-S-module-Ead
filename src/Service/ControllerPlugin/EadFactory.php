<?php
namespace BulkImportEad\Service\ControllerPlugin;

use BulkImportEad\Mvc\Controller\Plugin\Ead;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

/**
 * Service factory for the Ead controller plugin.
 */
class EadFactory implements FactoryInterface
{
    /**
     * Create and return the Ead controller plugin.
     *
     * @return Ead
     */
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        return new Ead(
            $services->get('Omeka\EntityManager'),
            $services->get('ControllerPluginManager')->get('api')
        );
    }
}
