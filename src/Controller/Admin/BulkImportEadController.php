<?php
namespace BulkImportEad\Controller\Admin;

use BulkImportEad\Form\ImportForm;
use BulkImportEad\Job\ImportEad;
use finfo;
use Log\Stdlib\PsrMessage;
use XMLReader;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class BulkImportEadController extends AbstractActionController
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function indexAction()
    {
        return $this->redirect()->toRoute('admin/bulk-import-ead', ['action' => 'import']);
    }

    public function importAction()
    {
        /** @var \BulkImportEad\Form\ImportForm $form */
        $form = $this->getForm(ImportForm::class);
        $form->setAttribute('action', $this->url()->fromRoute('admin/bulk-import-ead', ['action' => 'upload']));
        $form->init();

        $view = new ViewModel;
        $view->setVariable('form', $form);
        return $view;
    }

    public function uploadAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            $this->messenger()->addError(
                sprintf('Unallowed request.') // @translate
            );
            return $this->redirect()->toRoute('admin/bulk-import-ead', ['action' => 'import']);
        }

        /** @var \BulkImportEad\Form\ImportForm $form */
        $form = $this->getForm(ImportForm::class);

        $view = new ViewModel;
        $view->setVariable('form', $form);
        $view->setTemplate('bulk-import-ead/admin/bulk-import-ead/import');

        $post = $this->params()->fromPost();
        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addFormErrors($form);
            return $view;
        }

        $args = $form->getData();
        $files = $request->getFiles()->toArray();
        if (empty($files) && empty($args['url'])) {
            $this->messenger()->addError(
                'You should select either a file to upload, either a url to fetch.' // @translate
            );
            return $view;
        }

        if ($args['ead_base_id'] === 'custom' && empty($args['ead_base_ids'])) {
            $this->messenger()->addError(
                'With custom base id, you should fill the params.' // @translate
            );
            return $view;
        }

        // TODO Check the file during validation inside the form.
        $isRemote = !empty($args['url'])
            && (empty($files)
                || (isset($files['file']['error']) && $files['file']['error'] == UPLOAD_ERR_NO_FILE
            ));
        if ($isRemote) {
            $url = $args['url'];
            $filepath = $this->downloadToTemp($url);
            if (empty($filepath)) {
                $this->messenger()->addError(
                    'Unable to fetch data from the url.' // @translate
                );
                return $view;
            }
            $filesize = filesize($filepath);
            if (!$filesize) {
                $this->messenger()->addError(
                    'The url returns empty data.' // @translate
                );
                return $view;
            }
            $file = [
                'name' => $url,
                'type' => null,
                'tmp_name' => $filepath,
                'error' => 0,
                'size' => $filesize,
            ];
        } else {
            $file = $files['file'];
        }

        $fileCheck = $this->checkFile($file);
        if (empty($file['tmp_name'])) {
            $this->messenger()->addError(
                'No file provided.' // @translate
            );
            return $view;
        }elseif (!empty($file['error'])) {
            $this->messenger()->addError(
                'An error occurred when uploading the file.' // @translate
            );
            return $view;
        } elseif ($fileCheck === false) {
            $this->messenger()->addError(
                sprintf('Wrong media type ("%s") for file.', // @translate
                    $file['type'])
            );
            return $view;
        } elseif (empty($file['size'])) {
            $this->messenger()->addError(
                'The file is empty.' // @translate
            );
            return $view;
        } elseif (!$this->validateXml($file['tmp_name'], [
            'xmlRoot' => \BulkImportEad\Job\ImportEad::XML_ROOT,
            'xmlNamespace' => \BulkImportEad\Job\ImportEad::XML_NAMESPACE,
            'xmlPrefix' => \BulkImportEad\Job\ImportEad::XML_PREFIX,
        ])) {
            $this->messenger()->addError(
                sprintf('The xml doesn’t have the required namespace.') // @translate
            );
            return $view;
        }

        unset($args['csrf']);
        $args['file'] = $file;
        $args['isRemote'] = $isRemote;
        if ($isRemote) {
            $args['file']['type'] = 'text/xml';
        } else {
            $args['file']['tmp_name'] = $this->moveToTemp($files['file']['tmp_name']);
            unset($args['url']);
        }

        $dispatcher = $this->jobDispatcher();
        try {
            // Synchronous dispatcher for testing purpose.
            // $job = $dispatcher->dispatch(ImportEad::class, $args, $this->getServiceLocator()->get('Omeka\Job\DispatchStrategy\Synchronous'));
            $job = $dispatcher->dispatch(ImportEad::class, $args);

            $message = new PsrMessage(
                'Import started in background (<a href="{job_url}">job #{job_id}</a>). This may take a while.', // @translate
                [
                    'job_url' => htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()])),
                    'job_id' => $job->getId(),
                ]
            );
            $message->setEscapeHtml(false);
            $this->messenger()->addSuccess($message);
            $this->messenger()->addNotice('The process is done in two steps: import of all pieces as items, then creation of the tree structure to link them.'); // @translate
        } catch (\Exception $e) {
            $this->messenger()->addError('Import start failed'); // @translate
        }

        return $this->redirect()->toRoute('admin/bulk-import-ead', ['action' => 'import']);
    }

    /**
     * Check the file, according to its media type.
     *
     * @todo Use the class TempFile before.
     *
     * @param array $fileData File data from a post ($_FILES).
     * @return array|bool
     */
    protected function checkFile(array $fileData)
    {
        if (empty($fileData) || empty($fileData['tmp_name'])) {
            return false;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($fileData['tmp_name']);

        $extension = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
        $fileData['extension'] = $extension;

        // Manage an exception for a very common format, undetected by fileinfo.
        if ($mediaType === 'text/plain') {
            $extensions = [
                'xml' => 'text/xml',
            ];
            if (isset($extensions[$extension])) {
                $mediaType = $extensions[$extension];
                $fileData['type'] = $mediaType;
            }
        }

        $supporteds = [
            'text/xml' => true,
            'application/xml' => true,
        ];
        if (!isset($supporteds[$mediaType])) {
            return false;
        }

        $fileData['type'] = $mediaType;
        return $fileData;
    }

    /**
     * Check if the current file is a xml metadata one.
     *
     * @param string $filepath
     * @param array $args Specific values needed: xmlRoot, namespace.
     * @return boolean
     */
    protected function validateXml($filepath, $args)
    {
        $xmlRoot = $args['xmlRoot'];
        $xmlNamespace = $args['xmlNamespace'];
        if (empty($xmlRoot) || empty($xmlNamespace)) {
            return false;
        }

        // XmlReader is the quickest and the simplest for such a check, localy
        // or remotely.
        $reader = new XMLReader;
        $result = $reader->open($filepath, null, LIBXML_NSCLEAN);
        if ($result) {
            // The xml prefix may or may not be used.
            // TODO Use the prefix used in the xml file for the specified namespace.
            $xmlPrefix = $args['xmlPrefix'];
            if (empty($xmlPrefix)) {
                $xmlPrefixNs = '';
                $xmlPrefixRoot = '';
            }
            // Check the existing prefix.
            else {
                $xmlPrefixNs = 'xmlns:' . $xmlPrefix;
                $xmlPrefixRoot = $xmlPrefix . ':' . $xmlRoot;
            }

            $result = false;
            while ($reader->read()) {
                if ($reader->name !== '#comment') {
                    $result = ($reader->name === $xmlRoot
                            && $reader->getAttribute('xmlns') === $xmlNamespace)
                        || ($xmlPrefixNs
                            && $reader->name === $xmlPrefixRoot
                            && $reader->getAttribute($xmlPrefixNs) === $xmlNamespace);
                    break;
                }
            }
        }
        $reader->close();
        return $result;
    }

    /**
     * Move a file to a temp path.
     *
     * @param string $systemTempPath
     * @param string $tempDir
     * @return string|false
     */
    protected function moveToTemp($systemTempPath, $tempDir = null)
    {
        if (!isset($tempDir)) {
            if (!isset($this->config['temp_dir'])) {
                throw new \Omeka\Service\Exception\ConfigException('Missing temporary directory configuration'); // @translate
            }
            $tempDir = $this->config['temp_dir'];
        }
        $tempPath = tempnam($tempDir, 'omk');
        return move_uploaded_file($systemTempPath, $tempPath)
            ? $tempPath
            : false;
    }

    /**
     * Download a url into a temp path.
     *
     * @param string $systemTempPath
     * @param string $tempDir
     * @return string|false
     */
    protected function downloadToTemp($url, $tempDir = null)
    {
        if (!isset($tempDir)) {
            if (!isset($this->config['temp_dir'])) {
                throw new \Omeka\Service\Exception\ConfigException('Missing temporary directory configuration'); // @translate
            }
            $tempDir = $this->config['temp_dir'];
        }
        $tempPath = tempnam($tempDir, 'omk');

        $file = file_get_contents($url);
        if (empty($file)) {
            return false;
        }

        return file_put_contents($tempPath, $file)
            ? $tempPath
            : false;
    }
}
