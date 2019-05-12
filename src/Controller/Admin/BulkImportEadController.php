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
        $view = new ViewModel;

        $form = $this->getForm(ImportForm::class);
        $form->setAttribute('action', $this->url()->fromRoute('admin/bulk-import-ead', ['action' => 'upload']));
        $form->init();

        $view->form = $form;
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

        $files = $request->getFiles()->toArray();
        if (empty($files)) {
            $this->messenger()->addError(
                sprintf('Missing file.') // @translate
            );
            return $this->redirect()->toRoute('admin/bulk-import-ead', ['action' => 'import']);
        }

        $post = $this->params()->fromPost();
        $form = $this->getForm(ImportForm::class);
        $form->setData($post);
        // TODO Important: Check csrf, even other checks are enough.
        if (!$form->isValid()) {
            $this->messenger()->addError(
                'Wrong request for file.' // @translate
            );
            return $this->redirect()->toRoute('admin/bulk-import-ead');
        }

        // TODO Check the file during validation inside the form.

        $file = $files['file'];
        $fileCheck = $this->checkFile($file);
        if (!empty($file['error'])) {
            $this->messenger()->addError(
                sprintf('An error occurred when uploading the file.') // @translate
            );
        } elseif ($fileCheck === false) {
            $this->messenger()->addError(
                sprintf('Wrong media type ("%s") for file.', // @translate
                    $file['type'])
            );
        } elseif (empty($file['size'])) {
            $this->messenger()->addError(
                sprintf('The file is empty.') // @translate
            );
        } elseif (!$this->validateXml($file['tmp_name'], [
            'xmlRoot' => \BulkImportEad\Job\ImportEad::XML_ROOT,
            'xmlNamespace' => \BulkImportEad\Job\ImportEad::XML_NAMESPACE,
            'xmlPrefix' => \BulkImportEad\Job\ImportEad::XML_PREFIX,
        ])) {
            $this->messenger()->addError(
                sprintf('The xml doesn’t have the required namespace.') // @translate
            );
        } else {
            $args = $form->getData();
            unset($args['csrf']);
            $args['files'] = $files;
            $args['files']['file']['tmp_name'] = $this->moveToTemp($files['file']['tmp_name']);

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
            } catch (\Exception $e) {
                $this->messenger()->addError('Import start failed'); // @translate
            }
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
}
