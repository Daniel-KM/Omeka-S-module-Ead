<?php
namespace BulkImportEad\Service\ViewHelper;

use BulkImportEad\View\Helper\Ead;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

/**
 * Service factory for the Ead view helper.
 */
class EadFactory implements FactoryInterface
{
    /**
     * Create and return the Ead view helper.
     *
     * @return Ead
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Ead(
            $services->get('ControllerPluginManager')->get('ead')
        );
    }
}
