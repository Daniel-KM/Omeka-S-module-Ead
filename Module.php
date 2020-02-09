<?php
namespace Ead;

/*
 * Copyright Daniel Berthereau, 2015-2019
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Doctrine\ORM\QueryBuilder;
use Generic\AbstractModule;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Api\Adapter\ItemAdapter;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * EAD
 *
 * Allows to import and to display EAD from an xml file.
 *
 * @copyright Daniel Berthereau, 2015-2019
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected $dependency = 'BulkImport';

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $lib = __DIR__ . '/data/xsl/Ead2DCterms/ead2dcterms.xsl';
        if (!file_exists($lib)) {
            throw new \Omeka\Module\Exception\ModuleCannotInstallException(
                sprintf(
                    'EAD2DCTerms library should be installed. See %sReadme%s.',
                    '<a href="https://github.com/Daniel-KM/Omeka-S-module-Ead#install">',
                    '</a>'
                )
            );
        }

        parent::install($serviceLocator);

        $settings = $serviceLocator->get('Omeka\Settings');

        $whitelist = $settings->get('media_type_whitelist', []);
        if (!in_array('text/xml', $whitelist)) {
            $whitelist[] = 'text/xml';
            $settings->set('media_type_whitelist', $whitelist);
        }

        $whitelist = $settings->get('extension_whitelist', []);
        if (!in_array('xml', $whitelist)) {
            $whitelist[] = 'xml';
            $settings->set('extension_whitelist', $whitelist);
        }

        $this->setServiceLocator($serviceLocator);
        $this->installResources();
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.search.query',
            [$this, 'searchQuery']
        );

        // Add the search field to the advanced search pages.
        // TODO add filter for public site too.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.advanced_search',
            [$this, 'displayAdvancedSearch']
        );
        // Filter the search filters for the advanced search pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.search.filters',
            [$this, 'filterSearchFilters']
        );
    }

    /**
     * Helper to filter search queries.
     *
     * @param Event $event
     */
    public function searchQuery(Event $event)
    {
        /** @var \Doctrine\ORM\QueryBuilder $qb */
        $qb = $event->getParam('queryBuilder');
        $adapter = $event->getTarget();
        $query = $event->getParam('request')->getContent();
        if ($adapter instanceof ItemAdapter) {
            $this->searchIsArchive($qb, $adapter, $query);
        }
    }

    /**
     * Display the advanced search form via partial.
     *
     * @param Event $event
     */
    public function displayAdvancedSearch(Event $event)
    {
        $query = $event->getParam('query', []);
        $partials = $event->getParam('partials', []);

        $resourceType = $event->getParam('resourceType');
        if ($resourceType === 'item') {
            $query['is_archive'] = isset($query['is_archive']) ? $query['is_archive'] : '';
            $partials[] = 'common/advanced-search/ead';
        }

        $event->setParam('query', $query);
        $event->setParam('partials', $partials);
    }

    /**
     * Filter search filters.
     *
     * @param Event $event
     */
    public function filterSearchFilters(Event $event)
    {
        $translate = $event->getTarget()->plugin('translate');
        $query = $event->getParam('query', []);
        $filters = $event->getParam('filters');

        if (isset($query['is_archive'])) {
            $value = $query['is_archive'];
            if ($value) {
                $filterLabel = $translate('Is archive'); // @translate
                $filters[$filterLabel][] = $translate('yes'); // @translate
            } elseif ($value !== '') {
                $filterLabel = $translate('Is archive'); // @translate
                $filters[$filterLabel][] = $translate('no'); // @translate
            }
        }

        $event->setParam('filters', $filters);
    }

    /**
     * Build query to check if an item is an archive or not.
     *
     * The argument uses "is_archive", with value "1" or "0".
     *  It is an archive if it has "ead:ead" set and not empty.
     *
     * @param QueryBuilder $qb
     * @param AbstractResourceEntityAdapter $adapter
     * @param array $query
     */
    protected function searchIsArchive(
        QueryBuilder $qb,
        AbstractResourceEntityAdapter $adapter,
        array $query
    ) {
        if (!isset($query['is_archive'])) {
            return;
        }

        $value = (string) $query['is_archive'];
        if ($value === '') {
            return;
        }

        $expr = $qb->expr();

        $valuesJoin = $adapter->getEntityClass() . '.values';

        $property = $adapter->getPropertyByTerm('ead:ead');
        $propertyId = $property ? $property->getId() : 0;

        $joinConditions = [];
        $valuesAlias = $adapter->createAlias();
        $predicateExpr = $expr->isNotNull("$valuesAlias.id");
        $joinConditions[] = $expr->eq("$valuesAlias.property", (int) $propertyId);
        $qb->leftJoin($valuesJoin, $valuesAlias, 'WITH', $expr->andX(...$joinConditions));
        $predicateExpr2 = $expr->notIn("$valuesAlias.value", ['0', 'false']);
        $where = '(' . $predicateExpr . ') AND (' . $predicateExpr2 . ')';

        $qb->andWhere($where);
    }

    protected function installResources()
    {
        if (!class_exists(\Generic\InstallResources::class)) {
            require_once file_exists(dirname(__DIR__) . '/Generic/InstallResources.php')
                ? dirname(__DIR__) . '/Generic/InstallResources.php'
                : __DIR__ . '/src/Generic/InstallResources.php';
        }

        $services = $this->getServiceLocator();
        $installResources = new \Generic\InstallResources($services);
        $installResources = $installResources();

        $vocabulary = [
            'vocabulary' => [
                // 'o:namespace_uri' => 'https://loc.gov/ead#',
                'o:namespace_uri' => 'http://www.loc.gov/ead',
                'o:prefix' => 'ead',
                'o:label' => 'EAD for Omeka', // @translate
                'o:comment' => 'An adaptation of the Encoded Archival Description (EAD) as a vocabulary. Only elements that can’t be easily mapped into Dublin Core Terms, mainly textual content, are added. Textual content will be imported as xhtml in a future version.', // @translate
            ],
            'strategy' => 'file',
            'file' => __DIR__ . '/data/vocabularies/ead-for-omeka-s.ttl',
            'format' => 'turtle',
        ];
        $installResources->createVocabulary($vocabulary);
    }
}
