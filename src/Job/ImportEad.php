<?php
namespace BulkImportEad\Job;

// Include a file from Omeka Classic plugin "Archive Folder".
// TODO Remove or simplify these functions to manage paths.
require_once __DIR__ . '/ArchiveFolder/Tool/ManagePaths.php';

use ArrayObject;
use DOMDocument;
use DOMXPath;
use Omeka\Job\AbstractJob;
use ManagePaths;
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

    protected $mapItemTypeToClasses = [
        'Archival Finding Aid' => 'ead:ArchivalFindingAid',
        'Component' => 'ead:Component',
    ];

    protected $mapElementsToProperties= [
        'EAD Archive:Description of Subordinate Components' => 'ead:dsc',

        'EAD Archive:Descriptive Identification : Heading' => 'ead:unitDIdHead',
        'EAD Archive:Descriptive Identification : Note' => 'ead:unitDIdNote',
        'EAD Archive:Appraisal Information' => 'ead:unitAppraisal',
        'EAD Archive:Arrangement' => 'ead:unitArrangement',
        'EAD Archive:Biography or History' => 'ead:unitBiogHist',
        'EAD Archive:Index' => 'ead:unitIndex',
        'EAD Archive:Level' => 'ead:unitLevel',
        'EAD Archive:Note' => 'ead:unitNote',
        'EAD Archive:Other Descriptive Data' => 'ead:unitOdd',
        'EAD Archive:Processing Information' => 'ead:unitProcessInfo',
        'EAD Archive:Scope and Content' => 'ead:unitScopeContent',
        'EAD Archive:Heading' => 'ead:unitHead',
        'EAD Archive:Table Head' => 'ead:unitTHead',

        'Item Type Metadata:Edition Statement' => 'ead:headerEditionStmt',
        'Item Type Metadata:Publication Statement' => 'ead:headerPublicationStmt',
        'Item Type Metadata:Note statement' => 'ead:headerNoteStmt',
        'Item Type Metadata:Profile description : Creation' => 'ead:headerProfileDescCreation',
        'Item Type Metadata:Profile description : Descriptive Rules' => 'ead:headerProfileDescDescRules',
        'Item Type Metadata:Profile description : Language Usage' => 'ead:headerProfileDescLangUsage',
        'Item Type Metadata:Revision Description : Change' => 'ead:headerRevisionDescChange',
        'Item Type Metadata:Revision Description : List' => 'ead:headerRevisionDescList',

        'Item Type Metadata:Front matter : Title page' => 'ead:frontmatterTitlePage',
        'Item Type Metadata:Front matter : Title page : Block Quote' => 'ead:frontmatterTitlePageBlockQuote',
        'Item Type Metadata:Front matter : Title page : Chronology list' => 'ead:frontmatterTitlePageChronList',
        'Item Type Metadata:Front matter : Title page : List' => 'ead:frontmatterTitlePageList',
        'Item Type Metadata:Front matter : Title page : Note' => 'ead:frontmatterTitlePageNote',
        'Item Type Metadata:Front matter : Title page : Paragraph' => 'ead:frontmatterTitlePageP',
        'Item Type Metadata:Front matter : Title page : Table' => 'ead:frontmatterTitlePageTable',
        'Item Type Metadata:Front matter : Division' => 'ead:frontmatterDiv',
    ];

    /**
     * List of item types mapped with resource classes ids.
     *
     * @var array
     */
    protected $itemTypes = [];

    /**
     * @var string
     */
    protected $uri;

    /**
     * Local path to the uri.
     *
     * @var string
     */
    protected $metadataFilepath;

    /**
     * @var string
     */
    protected $tempPath;

    /**
     * Temporary final xml path.
     *
     * @var string
     */
    protected $xmlpath;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\ProcessXslt
     */
    protected $processXslt;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \ManagePaths
     */
    protected $managePaths;

    /**
     * @var ArrayObject
     */
    protected $resources;

    /**
     * @var \SimpleXMLElement
     */
    protected $xml;

    /**
     * @var int
     */
    protected $indexResource = 0;

    /**
     * @var int
     */
    protected $indexItem = 0;

    public function perform()
    {
        $this->xslMain = dirname(dirname(__DIR__)) . $this->xslMain;
        $this->xslSecondary = dirname(dirname(__DIR__)) . $this->xslSecondary;
        $this->xslParts = dirname(dirname(__DIR__)) . $this->xslParts;
        $this->xmlConfig = dirname(dirname(__DIR__)) . $this->xmlConfig;

        $this->logger()->log(Logger::NOTICE, 'Import started'); // @translate

        $file = $this->getArg('file');
        if (empty($file)) {
            $this->logger()->log(Logger::ERR, 'No file submitted.'); // @translate
            return;
        }

        $this->metadataFilepath = $file['tmp_name'];
        if (empty($this->metadataFilepath)) {
            $this->logger()->log(Logger::ERR, 'Unable to cache file.'); // @translate
            return;
        }

        if (!filesize($this->metadataFilepath)) {
            $this->logger()->log(Logger::ERR, 'File is empty.'); // @translate
            return;
        }

        // Use the base name as document uri.
        $isRemote = $this->getArg('isRemote');
        $this->uri = $isRemote ? $this->getArg('url') : $file['name'];

        $pluginManager = $this->getServiceLocator()->get('ControllerPluginManager');
        $this->processXslt = $pluginManager->get('processXslt');
        $this->bulk = $pluginManager->get('bulk');
        $this->managePaths = new ManagePaths($this->uri, $this->job->getArgs());
        $this->managePaths->setMetadataFilepath($this->metadataFilepath);

        $this->prepareItemTypes();

        $result = $this->prepareDocuments();
        if (!$result) {
            return;
        }

        $this->logger()->info(
            'Starting import of {number} converted resources.', // @translate
            ['number' => count($this->resources)]
        );

        $this->importDocuments();

        $this->logger()->log(Logger::INFO,
            'Starting creation of links between archival components.' // @translate
        );

        $this->linkDocuments();

        $this->logger()->info(
            'Import of {number} converted resources completed.', // @translate
            ['number' => count($this->resources)]
        );

        $this->logger()->log(Logger::NOTICE, 'Import completed'); // @translate
    }

    /**
     * Get the logger for the bulk process (the Omeka one, with reference id).
     *
     * @return \Zend\Log\Logger
     */
    protected function logger()
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
     * Prepare list of item types and resource class ids.
     */
    protected function prepareItemTypes()
    {
        $bulk = $this->bulk();
        foreach ($this->mapItemTypeToClasses as $itemType => $class) {
            $id = $bulk->getResourceClassId($class);
            if ($id) {
                $this->itemTypes[$id] = $itemType;
            }
        }
    }

    /**
     * Prepare the list of documents set inside the current metadata file.
     *
     * @return bool
     */
    protected function prepareDocuments()
    {
        $this->resources = new ArrayObject;

        // If the xml is too large, the php memory may be increased so it can be
        // processed directly via SimpleXml.
        $this->xml = simplexml_load_file($this->metadataFilepath, \SimpleXMLElement::class, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_PARSEHUGE);
        if ($this->xml === false) {
            $this->logger()->err('The file "{filepath}" is not xml.', ['filepath' => $this->metadataFilepath]); // @translate
            return false;
        }

        $this->xml->registerXPathNamespace(self::XML_PREFIX, self::XML_NAMESPACE);

        $extraParameters = $this->getArg('extra_parameters');

        // Set the default file for the configuration.
        $configuration = empty($extraParameters['configuration'])
            ? $this->xmlConfig
            : $extraParameters['configuration'];

        // TODO Use temp dir of the Omeka config.
        // Set the base id in the config file.
        $tempConfig = tempnam(sys_get_temp_dir(), 'ead2dcterms_');
        $result = copy($configuration, $tempConfig);
        if (empty($result)) {
            $this->logger()->err(
                'Error during copy of the configuration file from "{filepath}" into "{filepath2}".', // @translate
                ['filepath' => $configuration, 'filepath2' => $tempConfig]
            );
            return false;
        }

        $configuration = $tempConfig;

        // In fact, if it is the same than the "baseid", it's useless, but it's
        // simpler to set it always.
        $baseIdXml = $this->getBaseIdXml();
        $result = $this->updateConfig($configuration, $baseIdXml);
        if (empty($result)) {
            $this->logger()->err(
                'Error during update of the element "baseid" in the configuration file "{filepath}".', // @translate
                ['filepath' => $configuration]
            );
            return false;
        }

        $this->logger()->debug('File used for internal configuration: {filepath}.', ['filepath' => $configuration]); // @translate

        $extraParameters['configuration'] = $configuration;

        // Process the xml file via the stylesheet.
        $intermediatePath = $this->processXslt($this->metadataFilepath, $this->xslMain, '', $extraParameters);
        if (filesize($intermediatePath) == 0) {
            $this->logger()->err(
                'An empty file was the result of the first conversion. Check your input file and your params.' // @translate
            );
            return false;
        }

        $this->logger()->debug('Intermediate converted file: {filepath}.', ['filepath' => $intermediatePath]); // @translate

        // Process the intermediate xml file via the secondary stylesheet.
        $xmlpath = $this->processXslt($intermediatePath, $this->xslSecondary);
        if (filesize($xmlpath) == 0) {
            $this->logger()->err(
                'An empty file was the result of the second conversion. Check your input file and your params.' // @translate
            );
            return false;
        }

        $this->logger()->debug('Final converted file: {filepath}.', ['filepath' => $xmlpath]); // @translate

        // Now, the xml is a standard document, so process it via standard way.
        // Standard way means apply another xslt on the metadata filepath.
        // $this->resources = $this->mappingDocument->listDocuments($xmlpath);
        $this->xmlpath = $xmlpath;
        $this->prepareNormalizedDocuments();
        $this->setXmlFormat();
        $this->validateDocuments();
        // The deletion of duplicate metadatat is done manually via module Next.
        // $this->removeDuplicateMetadata();

        if (empty($this->resources)) {
            $this->logger()->log(Logger::WARN,
                'No resources were created after conversion of the input file into Omeka items.' // @translate
            );
            return false;
        }

        // Reset each intermediate xml metadata by the original one.
        $this->setXmlMetadata();

        return true;
    }

    /**
     * Import standard resources;
     */
    protected function importDocuments()
    {
        $api = $this->getServiceLocator()->get('ControllerPluginManager')->get('api');

        foreach ($this->resources as &$resource) {
            ++$this->indexResource;
            ++$this->indexItem;

            $data = $resource['metadata'];
            if (!empty($resource['extra']['itemType'])
                && isset($this->mapItemTypeToClasses[$resource['extra']['itemType']])
            ) {
                $data['o:resource_class'] = [
                    'o:id' => $this->bulk()
                        ->getResourceClassId($this->mapItemTypeToClasses[$resource['extra']['itemType']]),
                ];
            }
            $data['o:resource_template'] = ['o:id' => null];
            $data['o:thumbnail'] = ['o:id' => null];
            $data['o:is_public'] = true;

            $filesData = [];
            if (!empty($data['files'])) {
                foreach ($data['files'] as $file) {
                    ++$this->indexResource;
                    $fileData = $file['metadata'];
                    $fileData['o:resource_template'] = ['o:id' => null];
                    $fileData['o:resource_class'] = ['o:id' => null];
                    $fileData['o:thumbnail'] = ['o:id' => null];
                    $fileData['o:is_public'] = true;
                    $filesData[] = $fileData;
                }
            }

            $item = $api->create('items', $data, $filesData)->getContent();

            if ($item) {
                $resource['process']['@id'] = $item->id();
                $this->logger()->info(
                    'Index #{index}: Item #{item_id} created.', // @translate
                    ['index' => $this->indexResource, 'item_id' => $item->id()]
                );
            } else {
                $this->logger()->warn(
                    'Index #{index}: Unable to create an item.', // @translate
                    ['index' => $this->indexResource]
                );
            }
        }
    }

    /**
     * Create linked resources for dcterms:isPartOf and dcterms:hasPart.
     */
    protected function linkDocuments()
    {
        $api = $this->getServiceLocator()->get('ControllerPluginManager')->get('api');

        // First, create a list of all identifiers.
        $identifiers = [];
        $index = 0;
        foreach ($this->resources as &$resource) {
            ++$index;
            if (empty($resource['process']['@id'])) {
                continue;
            }
            $data = $resource['metadata'];
            if (empty($data['dcterms:identifier'])) {
                $this->logger()->warn(
                    'Index #{index}: item #{item_id} has no identifier.', // @translate
                    ['index' => $index, 'item_id' => $resource['process']['@id']]
                );
                continue;
            }

            foreach ($data['dcterms:identifier'] as $value) {
                if (!strlen($value['@value'])) {
                    continue;
                }
                if (isset($identifiers[$value['@value']])
                    && $identifiers[$value['@value']] !== $resource['process']['@id']
                ) {
                    $this->logger()->warn(
                        'Index #{index}: duplicate identifier "{identifier}" for item #{item_id} and item #{itemId}.', // @translate
                        ['index' => $index, 'item_id' => $identifiers[$value['@value']], 'itemId' => $resource['process']['@id']]
                    );
                    continue;
                }
                $identifiers[$value['@value']] = $resource['process']['@id'];
            }
        }
        unset($resource);

        if (!count($identifiers)) {
            $this->logger()->warn(
                'This import has no identifier, so no link can be created between component.' // @translate
            );
            return;
        }

        // Second, update items with dcterms:isPartOf and dcterms:hasPart.
        $index = 0;
        foreach ($this->resources as &$resource) {
            ++$index;
            if (empty($resource['process']['@id'])) {
                continue;
            }

            $toUpdate = false;
            $data = $resource['metadata'];
            foreach (['dcterms:hasPart', 'dcterms:isPartOf'] as $term) {
                if (isset($data[$term])) {
                    foreach ($data[$term] as $key => $value) {
                        if (!strlen($value['@value'])) {
                            continue;
                        }
                        if (isset($identifiers[$value['@value']])) {
                            $toUpdate = true;
                            $data[$term][$key] = [
                                'property_id' => $value['property_id'],
                                'type' => 'resource',
                                '@language' => '',
                                '@value' => '',
                                '@id' => '',
                                'value_resource_id' => $identifiers[$value['@value']],
                                'is_public' => $value['is_public'],
                            ];
                        }
                    }
                }
            }
            if ($toUpdate) {
                $api->update('items', $resource['process']['@id'], $data, [], ['isPartial' => true]);
            }
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

        $file = $this->getArg('file');
        $isUploaded = !$this->bulk()->isUrl($file['name']);
        $defaultBaseId = $isUploaded
            ? $file['name']
            : $this->uri;

        $baseId = $this->getArg('ead_base_id');
        switch ($baseId) {
            case 'documentUri':
            default:
                $baseIdXml['from'] = '';
                $baseIdXml['default'] = $defaultBaseId;
                break;
            case 'basename':
                $baseIdXml['from'] = '';
                $baseIdXml['default'] = pathinfo($defaultBaseId, PATHINFO_BASENAME);
                break;
            case 'filename':
                $baseIdXml['from'] = '';
                $baseIdXml['default'] = pathinfo($defaultBaseId, PATHINFO_FILENAME);
                break;
            case 'eadid':
                $baseIdXml['from'] = '/ead/eadheader/eadid';
                $baseIdXml['default'] = $defaultBaseId;
                break;
            case 'publicid':
                $baseIdXml['from'] = '/ead/eadheader/eadid/@publicid';
                $baseIdXml['default'] = $defaultBaseId;
                break;
            case 'identifier':
                $baseIdXml['from'] = '/ead/eadheader/eadid/@identifier';
                $baseIdXml['default'] = $defaultBaseId;
                break;
            case 'url':
                $baseIdXml['from'] = '/ead/eadheader/eadid/@url';
                $baseIdXml['default'] = $defaultBaseId;
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
                    $baseIdXml['default'] = $defaultBaseId;
                }
                break;
        }

        $baseIdXml = array_map([$this->bulk, 'xml_escape'], $baseIdXml);
        return $baseIdXml;
    }

    protected function updateConfig($configuration, $baseIdXml)
    {
        $dom = new DOMDocument('1.1', 'UTF-8');
        $result = $dom->load($configuration);
        if (empty($result)) {
            return false;
        }

        // $root = $dom->documentElement;
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
            $message = sprintf('The EAD file "%s" cannot be processed [last step].', $this->metadataFilepath); // @translate
            throw new \Exception($message);
        }

        // Prepare the list of parts via the stylesheet.

        // Metadata are different when files are separated.
        $recordsForFiles = (bool) $this->getArg('records_for_files');

        $partsPath = $this->processXslt(
            $this->metadataFilepath,
            $this->xslParts,
            '',
            ['set_digital_objects' => $recordsForFiles ? 'separated' : 'integrated']
        );
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

    /**
     * Get the bulk plugin.
     *
     * @return \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected function bulk()
    {
        return $this->bulk;
    }

    /***
     *  Adapted from ArchiveFolder Mapping Document and Abstract.
     *
     * TODO Remove all these methods and use BulkImport process.
     **/

    /**
     * From Archive Folder
     * @todo To be removed.
     *
     * List of normalized special fields (attributes or extra data).
     * They are unique values, except tags.
     *
     * @var array
     */
    protected $_specialData = [
        // For any record (allow to manage process).
        'record type' => false,
        'action' => false,
        'name' => false,
        'identifier field' => false,
        'internal id' => false,
        // For files ("file" will be normalized as speciic "path").
        'item' => false,
        'file' => false,
        'path' => false,
        'original filename' => false,
        'filename' => false,
        'md5' => false,
        'authentication' => false,
        // For items ("tag" will be normalized as specific "tags").
        'collection' => false,
        'item type' => false,
        'tag' => true,
        'tags' => true,
        // For items and collections.
        'featured' => false,
        'public' => false,
    ];

    /**
     * @var \ManagePaths
     */
    protected $managePathsSub;

    /**
     * Prepare the list of documents set inside the current metadata file.
     */
    protected function prepareNormalizedDocuments()
    {
        $this->managePathsSub = new ManagePaths($this->xmlpath, $this->job->getArgs());
        $this->managePathsSub->setMetadataFilepath($this->xmlpath);

        // If the xml is too large, the php memory may be increased so it can be
        // processed directly via SimpleXml.
        $this->xml = simplexml_load_file($this->xmlpath);
        if ($this->xml === false) {
            return;
        }

        $xmlPrefix = 'doc';
        $xmlNamespace = 'http://localhost/documents/';

        $this->xml->registerXPathNamespace($xmlPrefix, $xmlNamespace);

        $nameBase = $this->managePathsSub->getRelativePathToFolder($this->xmlpath);
        foreach ($this->xml->record as $key => $record) {
            $doc = $this->getDocument($record, true);

            // Add a name.
            $doc['process']['name'] = isset($doc['process']['name'])
                ? $doc['process']['name']
                : $nameBase . '-' . ($key + 1);

            // All records are imported: no check if empty.
            $recordDom = dom_import_simplexml($record);
            $recordDom->setAttribute('xmlns', $xmlNamespace);
            $doc['process']['xml'] = $record->asXml();
            $this->resources[] = $doc;
        }
    }

    /**
     * Convert one record (e.g. one row of a spreadsheet) into a document.
     *
     * @param \SimpleXMLElement $record The record to process.
     * @param bool $withSubRecords Add sub records if any (files...).
     * @return array The document.
     */
    protected function getDocument($record, $withSubRecords = false)
    {
        // Process common metadata and create a new record for them.
        $doc = $this->getDataForRecord($record);

        if ($withSubRecords) {
            // Process files.
            $files = $record->record;
            foreach ($files as $fileXml) {
                $file = $this->getDataForRecord($fileXml);
                // A filepath is needed here.
                if (!isset($file['specific']['path']) || strlen($file['specific']['path']) == 0) {
                    continue;
                }

                $path = $file['specific']['path'];
                $doc['files'][$path] = $file;

                // The update of the xml with the good url is done now, but the
                // path in the document array is done later.
                // No check is done, because another one will be done later on
                // the document.
                $file = dom_import_simplexml($fileXml);
                if ($file) {
                    $fileurl = $this->managePathsSub->getRepositoryUrlForFile($path);
                    $file->setAttribute('file', $fileurl);
                }
            }
        }

        return $doc;
    }

    /**
     * Get all data for a record (item or file).
     *
     * @param SimpleXMLElement $record
     * @return array The document array.
     */
    protected function getDataForRecord($record)
    {
        $xmlPrefix = 'doc';
        $xmlNamespace = 'http://localhost/documents/';

        $current = [];

        // Set default values to avoid notices.
        $current['process'] = [];
        $current['specific'] = [];
        $current['metadata'] = [];
        $current['extra'] = [];

        // Process flat Dublin Core.
        $record->registerXPathNamespace('', $xmlNamespace);
        $record->registerXPathNamespace($xmlPrefix, $xmlNamespace);

        $record->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $record->registerXPathNamespace('dcterms', 'http://purl.org/dc/terms/');
        $xpath = 'dc:*|dcterms:*';
        $dcs = $record->xpath($xpath);
        foreach ($dcs as $dc) {
            $text = $this->innerXML($dc);
            $term = 'dcterms:' . $dc->getName();
            $current['metadata'][$term][] = [
                'property_id' => $this->bulk()->getPropertyId($term),
                'type' => 'literal',
                '@language' => '',
                '@value' => $text,
                'is_public' => true,
            ];
        }

        // The xml needs the Dublin Core namespaces in some cases.
        if (!empty($dcs)) {
            $recordDom = dom_import_simplexml($record);
            if ($recordDom) {
                $recordDom->setAttribute('xmlns:dcterms', 'http://purl.org/dc/terms/');
            }
        }

        // Process hierarchical elements.
        $elementSets = $record->elementSet;
        foreach ($elementSets as $elementSet) {
            $elementSetName = trim($this->getXmlAttribute($elementSet, 'name'));
            // Unmanageable.
            if (strlen($elementSetName) == 0) {
                continue;
            }

            $elements = $elementSet->element;
            foreach ($elements as $element) {
                $elementName = trim($this->getXmlAttribute($element, 'name'));
                // Unmanageable.
                if (strlen($elementName) == 0) {
                    continue;
                }

                $elementTerm = $elementSetName . ':' . $elementName;
                if (!isset($this->mapElementsToProperties[$elementTerm])) {
                    $this->logger()->warn(sprintf('Element "%s" doesn’t exist.', $elementTerm));
                    continue;
                }

                $term = $this->mapElementsToProperties[$elementTerm];
                $termId = $this->bulk()->getPropertyId($term);
                if (empty($termId)) {
                    $this->logger()->warn(sprintf('Element "%s" has no equivalent term.', $elementTerm));
                    continue;
                }
                $data = $element->data;
                foreach ($data as $value) {
                    $text = $this->innerXML($value);
                    $current['metadata'][$term][] = [
                        'property_id' => $termId,
                        'type' => 'literal',
                        '@language' => '',
                        '@value' => $text,
                        'is_public' => true,
                    ];
                }
            }
        }

        // Save all attributes as extra data.
        foreach ($record->attributes() as $name => $data) {
            $current['extra'][$name][] = (string) $data;
        }

        // Process extra data.
        $extra = $record->extra;
        if (!empty($extra)) {
            $extraData = $extra->data;
            foreach ($extraData as $data) {
                $name = trim($this->getXmlAttribute($data, 'name'));
                if (strlen($name) > 0) {
                    $text = $this->innerXML($data);
                    $current['extra'][$name][] = $text;
                }
            }
        }

        // Normalize special data, keeping original order.
        // Filling data during loop is unpredictable.
        $extraLower = [];
        foreach ($current['extra'] as $field => $data) {
            $lowerField = $this->spaceFromUppercase($field);
            if (isset($this->specialData[$lowerField])) {
                // Multiple values are allowed (for example tags). Keep order.
                if ($this->_specialData[$lowerField]) {
                    // Manage the tags exception (may be "tags" or "tag").
                    if ($lowerField == 'tag') {
                        $lowerField = 'tags';
                    }

                    $extraLower[$lowerField] = empty($extraLower[$lowerField])
                        ? $data
                        : array_merge($extraLower[$lowerField], $data);
                }
                // Only one value is allowed: keep last value.
                else {
                    $extraLower[$lowerField] = is_array($data) ? array_pop($data) : $data;
                }
                unset($current['extra'][$field]);
            }
        }
        if ($extraLower) {
            $current['extra'] = array_merge($current['extra'], $extraLower);
        }

        // Exceptions.

        // Normalize "path" (exception: can be "file" or "path").
        if (isset($current['extra']['file'])) {
            $current['specific']['path'] = $current['extra']['file'];
            unset($current['extra']['file']);
        }

        // Normalize true extra data.
        if (!empty($current['extra'])) {
            $extraData = array_diff_key($current['extra'], $this->_specialData);
            if ($extraData) {
                // Step 1: set single value as string, else let it as array.
                $value = null;
                foreach ($extraData as $name => &$value) {
                    if (is_array($value)) {
                        // Normalize empty value.
                        if (count($value) == 0) {
                            $value = '';
                        }
                        // Make unique value a single string.
                        elseif (count($value) == 1) {
                            $value = reset($value);
                        }
                    }
                }
                // Required, because $value is a generic reference used just before.
                unset($value);

                // Step 2: Normalize extra data names like geolocation[latitude]
                // (array notation). They will be imported via a pseudo post.
                $extra = [];
                foreach ($extraData as $key => $value) {
                    $array = $this->convertArrayNotation($key);
                    $array = $this->nestArray($array, $value);
                    $value = reset($array);
                    $name = key($array);
                    $extra[] = [$name => $value];
                }
                $finalExtraData = [];
                foreach ($extra as $data) {
                    $finalExtraData = array_merge_recursive($finalExtraData, $data);
                }

                $specialData = array_intersect_key($current['extra'], $this->_specialData);
                $current['extra'] = array_merge($finalExtraData, $specialData);
            }
        }

        // Avoid useless metadata.
        unset($current['extra']['xmlns:dc']);
        unset($current['extra']['xmlns:dcterms']);

        return $current;
    }

    /**
     * Get the attribute of a xml element.
     *
     * @param SimpleXMLElement $xml
     * @param string $attribute
     * @return string|null
     */
    protected function getXmlAttribute($xml, $attribute)
    {
        if (isset($xml[$attribute])) {
            return (string) $xml[$attribute];
        }
    }

    /**
     * Return the full inner content of an xml element.
     *
     * @todo Fully manage cdata
     *
     * @param SimpleXMLElement $xml
     * @return string
     */
    protected function innerXML($xml)
    {
        $output = $xml->asXml();
        $pos = strpos($output, '>') + 1;
        $len = strrpos($output, '<') - $pos;
        $output = trim(substr($output, $pos, $len));

        // Only main CDATA is managed, not inside content: if this is an xml or
        // html, it will be managed automatically by the display; if this is a
        // text, the cdata is a text too.
        $simpleXml = simplexml_load_string($output, \SimpleXMLElement::class, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING);
        // Non XML data.
        if (empty($simpleXml)) {
            // Check if this is a CDATA.
            if ($this->isCdata($output)) {
                $output = substr($output, 9, strlen($output) - 12);
            }
            // Check if this is a json data.
            elseif (json_decode($output) !== null) {
                $output = html_entity_decode($output, ENT_NOQUOTES);
            }
            // Else this is a normal data.
            else {
                $output = html_entity_decode($output);
            }
        }
        // Else this is an xml value, so no change because it's xml escaped.

        return trim($output);
    }

    /**
     * Check if a string is an xml cdata one.
     *
     * @param string $string
     * @return boolean
     */
    protected function isCdata($string)
    {
        $string = trim($string);
        return !empty($string)
            && strpos($string, '<![CDATA[') === 0
            && strpos($string, ']]>') === strlen($string) - 3;
    }

    /**
     * Return an array of names from a string in array notation.
     */
    protected function convertArrayNotation($string)
    {
        // Bail early if no array notation detected.
        if (!strstr($string, '[')) {
            $array = array($string);
        }
        // Convert array notation.
        else {
            if ('[]' == substr($string, -2)) {
                $string = substr($string, 0, strlen($string) - 2);
            }
            $string = str_replace(']', '', $string);
            $array = explode('[', $string);
        }
        return $array;
    }

    /**
     * Convert a flat array into a nested array via recursion.
     *
     * @param array $keys Flat array.
     * @param mixed $value The last value
     * @return array The nested array.
     */
    protected function nestArray($keys, $value)
    {
        $nextKey = array_pop($keys);
        if (count($keys)) {
            $temp = array($nextKey => $value);
            return $this->nestArray($keys, $temp);
        }
        return array($nextKey => $value);
    }

    /**
     * Converts a word as "spacedVersion" into "spaced version".
     *
     * See \Inflector::underscore()
     * @param string $string
     * @return string $string
     */
    private static function spaceFromUppercase($string)
    {
        return  strtolower(
            preg_replace(
                '/[^A-Z^a-z^0-9]+/',
                ' ',
                preg_replace(
                    '/([a-z\d])([A-Z])/',
                    '\1 \2',
                    preg_replace(
                        '/([A-Z]+)([A-Z][a-z])/',
                        '\1 \2',
                        $string
                    )
                )
            )
        );
    }

    /**
     * Validate documents, secure paths of files and make them absolute.
     *
     * @internal Only local filepaths are checked.
     */
    protected function validateDocuments()
    {
        $documents = &$this->resources;

        // Check file paths and names (if one is absent, the other is used).
        $nameBase = $this->managePathsSub->getRelativePathToFolder($this->xmlpath);
        foreach ($documents as $key => &$document) {
            $document = $this->normalizeDocument($document, 'Item');
            // Check if the document is empty.
            if (empty($document['specific'])
                && empty($document['metadata'])
                && empty($document['extra'])
                && empty($document['files'])
            ) {
                // Special check for process: remove xml, automatically added.
                $check = array_diff_key($document['process'], array('xml' => true, 'format_xml' => true));
                if (empty($check)) {
                    unset($documents[$key]);
                    continue;
                }
            }

            // Add an internal name if needed.
            // Warning: this should not be the same than the one defined inside
            // a metadata file, even if the issue is very rare. Nevertheless, it
            // should be enough stable to be updatable in main normal cases.
            if (empty($document['process']['name'])) {
                $document['process']['name'] = $nameBase . ':0' . ($key + 1);
            }

            if (empty($document['files'])) {
                $document['files'] = [];
                continue;
            }
            foreach ($document['files'] as /* $order => */ &$file) {
                // The path and the fullpath are set during normalization, but
                // not checked for security. They are the same.
                $file = $this->normalizeDocument($file, 'File');

                // The path is not required if the file can be identified with
                // another metadata, for example for update or deletion.
                if (!strlen($file['process']['fullpath']) && !strlen($file['specific']['path'])) {
                    // TODO Check other metadata (name...).
                    continue;
                }

                // Secure the absolute filepath.
                $absoluteFilepath = $this->managePathsSub->getAbsolutePath($file['process']['fullpath']);
                if (empty($absoluteFilepath)) {
                    throw new \Exception(sprintf(
                        'The file "%s" inside document "%s" is incorrect.', // @translate
                        $file['process']['fullpath'],
                        $document['process']['name']
                    ));
                }

                // No relative path if the file is external to the folder.
                $relativeFilepath = $this->managePathsSub->isInsideFolder($absoluteFilepath)
                    ? $this->managePathsSub->getRelativePathToFolder($absoluteFilepath)
                    : $absoluteFilepath;
                if (empty($relativeFilepath)) {
                    throw new \Exception(sprintf(
                        'The file path "%s" is incorrect.', // @translate
                        $file['process']['fullpath']
                    ));
                }

                if (empty($file['process']['name'])) {
                    $file['process']['name'] = $relativeFilepath;
                }
            }
        }

        return $documents;
    }

    /**
     * Check and normalize a document (move extra data in process and specific).
     *
     * No default is added here, except the record type.
     *
     * @param array $document The document to normalize.
     * @param array $recordType Optional The record type if not set
     * @return array The normalized document.
     */
    protected function normalizeDocument($document, $recordType = null)
    {
        // Set default values to avoid notices.
        if (!isset($document['process'])) {
            $document['process'] = [];
        }
        if (!isset($document['specific'])) {
            $document['specific'] = [];
        }
        if (!isset($document['metadata'])) {
            $document['metadata'] = [];
        }
        if (!isset($document['extra'])) {
            $document['extra'] = [];
        }

        // Normalization for any record.
        $process = array(
            'record type' => null,
            'action' => null,
            'name' => null,
            'identifier field' => null,
            'internal id' => null,
            'format_xml' => null,
            'xml' => null,
        );
        $document['process'] = array_intersect_key(
            array_merge($document['extra'], $document['process']),
            $process
        );
        $document['extra'] = array_diff_key($document['extra'], $process);

        // For compatibility, the name can be set at root of the document.
        if (isset($document['name'])) {
            $document['process']['name'] = $document['name'];
            unset($document['name']);
        }

        // Set the record type, one of the most important value.
        if (empty($document['process']['record type'])) {
            // When the record type is set directly, it is used.
            if ($recordType) {
                $document['process']['record type'] = $recordType;
            }

            // Force the record type to item if not a file.
            else {
                $document['process']['record type'] = !isset($document['specific']['files'])
                    && (
                        isset($document['specific']['path'])
                        || isset($document['extra']['path'])
                        || isset($document['path'])
                    )
                    ? 'File'
                    : 'Item';
            }
        }

        // Normalize and check the record type.
        $recordType = ucfirst(strtolower($document['process']['record type']));
        if (!in_array($recordType, array('File', 'Item', 'Collection'))) {
            throw new \Exception(sprintf(
                'The record type "%s" is not managed.', // @translate
                $document['process']['record type']
                ));
        }
        $document['process']['record type'] = $recordType;

        // Check the action.
        if (!empty($document['process']['action'])) {
            $action = strtolower($document['process']['action']);
            if (!in_array(
                $action,
                [
                    'update else create',
                    'create',
                    'update',
                    'add',
                    'replace',
                    'delete',
                    'skip',
                ]
            )) {
                $message = sprintf('The action "%s" does not exist.', $document['process']['action']); // @translate
                throw new \Exception($message);
            }
            $document['process']['action'] = $action;
        }

        // Specific normalization according to the record type: separate Omeka
        // metadata and element texts, that are standard metadata.
        switch ($document['process']['record type']) {
            case 'File':
                $specific = [
                    'path' => null,
                    // "fullpath" is automatically checked and defined below.
                    'original filename' => null,
                    'filename' => null,
                    'md5' => null,
                    'authentication' => null,
                ];
                $document['specific'] = array_intersect_key(
                    array_merge($document['extra'], $document['specific']),
                    $specific
                );
                $document['extra'] = array_diff_key($document['extra'], $specific);

                if (empty($document['specific']['path'])) {
                    $document['specific']['path'] = empty($document['path']) ? '' : $document['path'];
                }

                // The full path is checked and simplifies management of files.
                if (isset($document['specific']['path']) && strlen($document['specific']['path'])) {
                    $absoluteFilePath = $this->managePathsSub->getAbsoluteUri($document['specific']['path']);
                    // An empty result means an incorrect path.
                    // Access rights for local files are checked by the builder.
                    if (empty($absoluteFilePath)) {
                        $message = sprintf('The path "%s" is forbidden or incorrect.', $document['specific']['path']); // @translate
                        throw new \Exception($message);
                    }
                    $document['specific']['path'] = $absoluteFilePath;
                    $document['process']['fullpath'] = $absoluteFilePath;
                }
                // No path is allowed for update if there is another identifier.
                else {
                    $document['specific']['path'] = '';
                    $document['process']['fullpath'] = '';
                }

                // The authentication is kept if md5 is set too.
                if (!isset($document['specific']['authentication']) && !empty($document['specific']['md5'])) {
                    $document['specific']['authentication'] = $document['specific']['md5'];
                }
                unset($document['specific']['md5']);
                break;

            case 'Item':
                $specific = [
                    'public' => null,
                    'featured' => null,
                    'collection_id' => null,
                    'item_type_id' => null,
                    'item_type_name' => null,
                    'tags' => null,
                    'collection' => null,
                    'item type' => null,
                ];
                $document['specific'] = array_intersect_key(
                    array_merge($document['extra'], $document['specific']),
                    $specific
                );
                $document['extra'] = array_diff_key($document['extra'], $specific);

                // Check the collection.
                if (isset($document['specific']['collection_id'])
                        && isset($document['specific']['collection'])
                ) {
                    unset($document['specific']['collection_id']);
                }

                // No collection name, so check the collection id.
                if (isset($document['specific']['collection_id'])) {
                    // If empty, collection id should be null, not "" or "0".
                    if (empty($document['specific']['collection_id'])) {
                        $document['specific']['collection_id'] = null;
                    }
                    // Check the collection id.
                    else {
                        $collection = get_db()->getTable('Collection')->find($document['specific']['collection_id']);
                        if (empty($collection)) {
                            $message = sprintf('The collection "%s" does not exist.', $document['specific']['collection_id']); // @translate
                            throw new \Exception($message);
                        }
                    }
                }

                // Check the item type, that can be set as "item_type_id",
                // "item_type_name" and "item type". The "item type" is kept
                // with the key "item_type_name".
                if (isset($document['specific']['item type'])) {
                    unset($document['specific']['item_type_id']);
                    $document['specific']['item_type_name'] = $document['specific']['item type'];
                    unset($document['specific']['item type']);
                }
                // Item type name is used if no item type.
                elseif (isset($document['specific']['item_type_name'])) {
                    unset($document['specific']['item_type_id']);
                }

                // TODO Convert item type to resource class.
                // $itemTypes = get_db()->getTable('ItemType')->findPairsForSelectForm();
                // Check the item type name.
                if (!empty($document['specific']['item_type_name'])) {
                    $itemTypeId = array_search(strtolower($document['specific']['item_type_name']), array_map('strtolower', $this->itemTypes));
                    if ($itemTypeId) {
                        throw new \Exception(sprintf(
                            'The item type "%s" does not exist.', // @translate
                            $document['specific']['item_type_name']
                        ));
                    }
                    $document['specific']['item_type_name'] = $this->mapItemTypeToClasses[$this->itemTypes[$itemTypeId]];
                }

                // Check the item type id.
                elseif (!empty($document['specific']['item_type_id'])) {
                    if (!isset($this->itemTypes[$document['specific']['item_type_id']])) {
                        throw new \Exception(sprintf(
                            'The item type id "%d" does not exist.', // @translate
                            $document['specific']['item_type_id']
                        ));
                    }
                    unset($document['specific']['item_type_id']);
                    $document['specific']['item_type_name'] = $this->mapItemTypeToClasses[$this->itemTypes[$document['specific']['item_type_id']]];
                }
                break;

            case 'Collection':
                $specific = [
                    'public' => null,
                    'featured' => null,
                ];
                $document['specific'] = array_intersect_key(
                    array_merge($document['extra'], $document['specific']),
                    $specific
                );
                $document['extra'] = array_diff_key($document['extra'], $specific);
                break;
        }

        // Normalize the identifier field if it is a special one.
        if (!empty($document['process']['identifier field'])) {
            $lowerIdentifierField = str_replace('_', ' ', strtolower($document['process']['identifier field']));
            if (in_array(
                $lowerIdentifierField,
                [
                    // For any record.
                    'none',
                    'internal id',
                    // For file only.
                    'original filename',
                    'filename',
                    'authentication',
                    // Old releases.
                    'original_filename',
                    'md5',
                ])
            ) {
                if ($document['process']['record type'] == 'File') {
                    // Quick checks for old releases.
                    if ($lowerIdentifierField == 'original_filename') {
                        $lowerIdentifierField = 'original filename';
                    } elseif ($lowerIdentifierField == 'md5') {
                        $lowerIdentifierField = 'authentication';
                    }
                } elseif (in_array(
                    $lowerIdentifierField,
                    [
                        'original filename',
                        'filename',
                        'authentication',
                        'original_filename',
                        'md5',
                    ])
                ) {
                    $message = sprintf(
                        'The identifier field "%s" is not allowed for the record type "%s".',
                        $document['process']['identifier field'],
                        $document['process']['record type']
                    );
                    throw new \Exception($message);
                }

                $document['process']['identifier field'] = $lowerIdentifierField;
            }
        }

        // The identifier itself is checked only during import.

        // Clean value for any record (done above).
        // Clean specific value of fIle.
        unset($document['path']);
        unset($document['extra']['path']);
        unset($document['extra']['original filename']);
        unset($document['extra']['filename']);
        unset($document['extra']['md5']);
        unset($document['extra']['authentication']);
        // Clean specific value of item.
        unset($document['extra']['collection']);
        unset($document['extra']['collection_id']);
        unset($document['extra']['item_type_id']);
        unset($document['extra']['item_type_name']);
        unset($document['extra']['item type']);
        unset($document['extra']['tags']);
        // Clean specific value of item and collection.
        unset($document['extra']['public']);
        unset($document['extra']['featured']);

        // Normalize the element texts.
        // Remove the Omeka 'html', that slows down process and that can be
        // determined automatically when it is really needed.
        foreach ($document['metadata'] as /* $term => */ $values) {
            foreach ($values as &$value) {
                if (is_array($value['@value'])) {
                    $value['@value'] = trim(isset($value['@value']['text']) ? $value['@value']['text'] : reset($value['@value']));
                }
                // Trim the metadata too to avoid useless spaces.
                else {
                    $value['@value'] = trim($value['@value']);
                }
            }
        }

        return $document;
    }

    /**
     * Set the xml format of all documents, specially if a sub class is used.
     */
    protected function setXmlFormat()
    {
        $documents = &$this->resources;

        $_formatXml = 'doc';

        foreach ($documents as &$document) {
            if (isset($document['process']['xml'])) {
                $document['process']['format_xml'] = $_formatXml;
            } else {
                unset($document['process']['format_xml']);
            }
            if (isset($document['files'])) {
                foreach ($document['files'] as &$file) {
                    if (isset($file['process']['xml'])) {
                        $file['process']['format_xml'] = $_formatXml;
                    } else {
                        unset($file['process']['format_xml']);
                    }
                }
            }
        }
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
}
