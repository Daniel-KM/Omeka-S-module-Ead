<?php
namespace BulkImportEad\Job;

use ArrayObject;
use DOMDocument;
use DOMXPath;
use Omeka\Job\AbstractJob;
use SimpleXMLElement;
use XMLReader;
use Zend\Log\Logger;

class ImportEad extends AbstractJob
{
    const XML_ROOT = 'ead';
    const XML_PREFIX = 'ead';
    const XML_NAMESPACE = 'http://www.loc.gov/ead';

    protected $xslMain = '/data/xsl/Ead2DCterms/ead2dcterms-omeka.xsl';
    protected $xslSecondary = '/data/xsl/dcterms-omeka2documents.xsl';
    protected $xslParts = '/data/xsl/ead_parts.xsl';
    protected $xmlConfig = '/data/xsl/Ead2DCterms/ead2dcterms-omeka_config.xml';

    /**
     * @var string
     */
    protected $uri;

    /**
     * @var string
     */
    protected $tempPath;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\ProcessXslt
     */
    protected $processXslt;

    /**
     * @var ArrayObject
     */
    protected $resources;

    /**
     * @var \SimpleXMLElement
     */
    protected $xml;

    public function perform()
    {
        $this->xslMain = dirname(dirname(__DIR__)) . $this->xslMain;
        $this->xslSecondary = dirname(dirname(__DIR__)) . $this->xslSecondary;
        $this->xslParts = dirname(dirname(__DIR__)) . $this->xslParts;
        $this->xmlConfig = dirname(dirname(__DIR__)) . $this->xmlConfig;

        $this->processXslt = $this->getServiceLocator()->get('ControllerPluginManager')->get('processXslt');

        $logger = $this->getLogger();
        $logger->log(Logger::NOTICE, 'Import started'); // @translate

        $this->uri = $this->getArg('filepath');
        if (empty($this->uri)) {
            $logger->log(Logger::ERR, 'Unable to cache file.'); // @translate
            return;
        }

        if (!filesize($this->uri)) {
            $logger->log(Logger::ERR, 'File is empty.'); // @translate
            return;
        }

        $this->prepareDocuments();

        $this->importDocuments();

        $logger->log(Logger::NOTICE, 'Import completed'); // @translate
    }

    /**
     * Get the logger for the bulk process (the Omeka one, with reference id).
     *
     * @return \Zend\Log\Logger
     */
    protected function getLogger()
    {
        if ($this->logger) {
            return $this->logger;
        }
        $this->logger = $this->getServiceLocator()->get('Omeka\Logger');
        $referenceIdProcessor = new \Zend\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('bulk/import/ead/' . $this->job->getId());
        $this->logger->addProcessor($referenceIdProcessor);
        return $this->logger;
    }

    /**
     * Prepare the list of documents set inside the current metadata file.
     */
    protected function prepareDocuments()
    {
        $this->resources = new ArrayObject;
        $documents = $this->resources;

        // If the xml is too large, the php memory may be increased so it can be
        // processed directly via SimpleXml.
        $this->xml = simplexml_load_file($this->uri, \SimpleXMLElement::class, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
        if ($this->xml === false) {
            $message = sprintf('The file "%s" is not xml.', $this->uri); // @translate
            throw new \Exception($message);
        }

        $this->xml->registerXPathNamespace(self::XML_PREFIX, self::XML_NAMESPACE);

        $extraParameters = $this->getArg('extra_parameters');

        // Set the default file for the configuration.
        $configuration = empty($extraParameters['configuration'])
            ? $this->xmlConfig
            : $extraParameters['configuration'];

        // Set the base id in the config file.
        $tempConfig = tempnam(sys_get_temp_dir(), 'ead2dcterms_');
        $result = copy($configuration, $tempConfig);
        if (empty($result)) {
            $message = sprintf(
                'Error during copy of the configuration file from "%s" into "%s".', // @translate
                $configuration,
                $tempConfig
            );
            throw new \Exception($message);
        }

        $configuration = $tempConfig;

        // In fact, if it is the same than the "baseid", it's useless, but it's
        // simpler to set it always.
        $baseIdXml = $this->getBaseIdXml();
        $result = $this->updateConfig($configuration, $baseIdXml);
        if (empty($result)) {
            $message = sprintf('Error during update of the element "baseid" in the configuration file "%s".', $configuration); // @translate
            throw new \Exception($message);
        }

        $extraParameters['configuration'] = $configuration;

        // Process the xml file via the stylesheet.
        $intermediatePath = $this->processXslt($this->uri, $this->xslMain, '', $extraParameters);
        if (filesize($intermediatePath) == 0) {
            return;
        }

        // Process the intermediate xml file via the secondary stylesheet.
        $xmlpath = $this->processXslt($intermediatePath, $this->xslSecondary);
        if (filesize($xmlpath) == 0) {
            return;
        }

pmf($documents);

        // Now, the xml is a standard document, so process it with the class.
//        $documents = $this->mappingDocument->listDocuments($xmlpath);

        // Reset each intermediate xml metadata by the original one.
        $this->setXmlMetadata();
    }

    /**
     * Import standard resources;
     */
    protected function importDocuments()
    {
        foreach ($this->resources as $resource) {




        }
    }

    /**
     * Get the base id from the parameters.
     *
     * @return array Attributes of the "base_id" element.
     */
    protected function getBaseIdXml()
    {
        $baseIdXml = [];

        $baseId = $this->getArg('ead_base_id');
        switch ($baseId) {
            case 'documentUri':
            default:
                $baseIdXml['from'] = '';
                $baseIdXml['default'] = $this->uri;
                break;
            case 'basename':
                $baseIdXml['from'] = '';
                $baseIdXml['default'] = pathinfo($this->uri, PATHINFO_BASENAME);
                break;
            case 'filename':
                $baseIdXml['from'] = '';
                $baseIdXml['default'] = pathinfo($this->uri, PATHINFO_FILENAME);
                break;
            case 'eadid':
                $baseIdXml['from'] = '/ead/eadheader/eadid';
                $baseIdXml['default'] = $this->uri;
                break;
            case 'publicid':
                $baseIdXml['from'] = '/ead/eadheader/eadid/@publicid';
                $baseIdXml['default'] = $this->uri;
                break;
            case 'identifier':
                $baseIdXml['from'] = '/ead/eadheader/eadid/@identifier';
                $baseIdXml['default'] = $this->uri;
                break;
            case 'url':
                $baseIdXml['from'] = '/ead/eadheader/eadid/@url';
                $baseIdXml['default'] = $this->uri;
                break;
            case 'custom':
                $baseIdXml['from'] = '';
                $baseIds = $this->getArg('ead_base_ids');
                $baseIds = $this->stringParametersToArray($baseIds);
                $xpath = '/ead:ead/ead:eadheader/ead:eadid';
                $result = $this->xml->xpath($xpath);
                $result = json_decode(json_encode($result), true);
                $result = $result[0]['@attributes'];
                $result = array_intersect(array_keys($baseIds), $result);
                if ($result) {
                    $result = array_shift($result);
                    $baseIdXml['default'] = $baseIds[$result];
                }
                // Unknown identifier.
                else {
                    $baseIdXml['default'] = $this->uri;
                }
                break;
        }
        $baseIdXml = array_map('xml_escape', $baseIdXml);
        return $baseIdXml;
    }

    protected function updateConfig($configuration, $baseIdXml)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $result = $dom->load($configuration);
        if (empty($result)) {
            return false;
        }

        $root = $dom->documentElement;
        $xpath = new DOMXPath($dom);

        $element = $xpath->query('/config/baseid')->item(0);
        $element->setAttribute('from', $baseIdXml['from']);
        $element->setAttribute('default', $baseIdXml['default']);

        // Because this is a temp file, the full path should be set when needed.
        $element = $xpath->query('/config/option[@name = "mappings"]')->item(0);
        $path = $element->getAttribute('value');
        if (realpath($path) != $path) {
            $path = dirname($this->xmlConfig) . DIRECTORY_SEPARATOR . $path;
        }
        $element->setAttribute('value', $path);

        // Because this is a temp file, the full path should be set when needed.
        $element = $xpath->query('/config/option[@name = "rules"]')->item(0);
        $path = $element->getAttribute('value');
        if (realpath($path) != $path) {
            $path = dirname($this->xmlConfig) . DIRECTORY_SEPARATOR . $path;
        }
        $element->setAttribute('value', $path);

        return $dom->save($configuration);
    }

    /**
     * Set the xml metadata of all documents, specially if a sub class is used.
     */
    protected function setXmlMetadata()
    {
        $documents = $this->resources;

        if (empty($documents)) {
            $message = sprintf('The EAD file "%s" cannot be processed [last step].', $this->uri); // @translate
            throw new \Exception($message);
        }

        // Prepare the list of parts via the stylesheet.

        // Metadata are different when files are separated.
        $recordsForFiles = (bool) $this->getArg('records_for_files');

        $partsPath = $this->processXslt($this->uri, $this->xslParts, '',
            ['set_digital_objects' => $recordsForFiles ? 'separated' : 'integrated']);
        if (filesize($partsPath) == 0) {
            return;
        }

        // By construction (see xsl), the root is the string before "/ead/eadheader".
        // TODO Warning: the root path may contain '/ead/eadheader' before,
        // even if this is very rare.
        $pathPos = strpos($documents[0]['process']['name'], '/ead/eadheader');

        foreach ($documents as &$document) {
            $partPath = isset($document['extra']['XPath'][0])
                ? $document['extra']['XPath'][0]
                : substr($document['process']['name'], $pathPos);
            $document['process']['xml'] = $this->getXmlPart($partsPath, $partPath);
            if (empty($document['files'])) {
                continue;
            }
            foreach ($document['files'] as &$file) {
                $partPath = isset($file['extra']['XPath'][0])
                    ? $file['extra']['XPath'][0]
                    : '';
                $file['process']['xml'] = $this->getXmlPart($partsPath, $partPath);
            }
        }
    }

    /**
     * Get an xml metadata part from the attribute "xpath" of a part.
     *
     * @param string $partsPath Path to the xml file.
     * @param string $partPath Attribute "xpath" of the part to get.
     * @return String value of the xml part, if any.
     *
     * @todo Avoid to reload the reader for each part.
     */
    protected function getXmlPart($partsPath, $partPath)
    {
        if ($partsPath == '' || $partPath == '') {
            return '';
        }

        // Read the xml from the beginning.
        $reader = new XMLReader;
        $result = $reader->open($partsPath, null, LIBXML_NSCLEAN);
        if (!$result) {
            return;
        }

        $result = '';
        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT
                && $reader->name == 'part'
                && $reader->getAttribute('xpath') === $partPath
            ) {
                $result = $reader->readInnerXml();
            }
        }
        return trim($result);
    }

    /**
     * Convert a string into a list of key / values.
     *
     * @internal The input is already checked via Zend form validator.
     *
     * @param array|string $input
     * @return array
     */
    protected function stringParametersToArray($input)
    {
        if (is_array($input)) {
            return $input;
        }

        $parameters = [];

        $parametersAdded = array_values(array_filter(array_map('trim', explode("\n", $input))));
        foreach ($parametersAdded as $parameterAdded) {
            list($paramName, $paramValue) = explode('=', $parameterAdded);
            $parameters[trim($paramName)] = trim($paramValue);
        }

        return $parameters;
    }

    /**
     * Apply a process (xslt stylesheet) on an file (xml file) and save result.
     *
     * @param string $uri Uri of input file.
     * @param string $stylesheet Path of the stylesheet.
     * @param string $output Path of the output file. If none, a temp file will
     * be used.
     * @param array $parameters Parameters array.
     * @return string|null Path to the output file if ok, null else.
     * @throws \Exception
     */
    protected function processXslt($uri, $stylesheet, $output = '', array $parameters = [])
    {
        $processXslt = $this->processXslt;
        return $processXslt($uri, $stylesheet, $output, $parameters);
    }
}
