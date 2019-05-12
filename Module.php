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

require_once dirname(__DIR__) . '/Generic/AbstractModule.php';

use Generic\AbstractModule;
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

    protected function installResources()
    {
        require_once dirname(__DIR__) . '/Generic/InstallResources.php';

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
