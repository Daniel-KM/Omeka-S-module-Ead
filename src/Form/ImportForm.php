<?php
namespace Ead\Form;

use Zend\Filter;
use Zend\Form\Element;
use Zend\Form\Form;
use Zend\Validator;

class ImportForm extends Form
{
    public function init()
    {
        // The action attribute is set via the controller.

        $this->add([
            'name' => 'action',
            'type' => Element\Radio::class,
            'options' => [
                'label' => 'Action', // @translate
                'info' => 'The update and deletion of resources uses their original identifiers, so they should not be changed.', // @translate
                'value_options' => [
                    \BulkImport\Processor\AbstractProcessor::ACTION_CREATE => 'Create new resources', // @translate
                    \BulkImport\Processor\AbstractProcessor::ACTION_REPLACE => 'Replace all data of resources', // @translate
                    \BulkImport\Processor\AbstractProcessor::ACTION_DELETE => 'Delete resources', // @translate
                    \BulkImport\Processor\AbstractProcessor::ACTION_SKIP => 'Skip entries (dry run)', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'action',
                'multiple' => false,
                'required' => true,
                'class' => 'chosen-select',
            ],
        ]);

        $this->add([
            'name' => 'action_unidentified',
            'type' => Element\Radio::class,
            'options' => [
                'label' => 'Action on unidentified resources', // @translate
                'info' => 'What to do when a resource to update does not exist.', // @translate
                'value_options' => [
                    \BulkImport\Processor\AbstractProcessor::ACTION_SKIP => 'Skip entry', // @translate
                    \BulkImport\Processor\AbstractProcessor::ACTION_CREATE => 'Create a new resource', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'action_unidentified',
                'value' => \BulkImport\Processor\AbstractProcessor::ACTION_SKIP,
            ],
        ]);

        $this->add([
            'name' => 'file',
            'type' => Element\File::class,
            'options' => [
                'label' => 'EAD xml file', // @translate
                'info' => 'The EAD is a simple xml file.', //@translate
            ],
            'attributes' => [
                'id' => 'file',
                'required' => false,
            ],
        ]);

        $this->add([
            'name' => 'url',
            'type' => Element\Url::class,
            'options' => [
                'label' => 'EAD xml url', // @translate
                'info' => 'The EAD may be available via a end point.', //@translate
            ],
            'attributes' => [
                'id' => 'url',
                'required' => false,
            ],
        ]);

        $this->add([
            'name' => 'ead_base_id',
            'type' => Element\Select::class,
            'options' => [
                'label' => 'Base ids', // @translate
                'info' => "Each item inside an EAD xml file is represented by a unique id, that is used to make relations between all items.\n
The base id is the first part of this id.\n
Default is the full document uri for remote source and filename for uploaded file.", // @translate
                'value_options' => [
                    'documentUri' => 'Document uri', // @translate
                    'basename' => 'Filename', // @translate
                    'filename' => 'Filename without extension', // @translate
                    'eadid' => 'Value of element "eadid"', // @translate
                    'publicid' => 'Attribute "publicid" of "eadid"', // @translate
                    'identifier' => 'Attribute "identifier" of "eadid"', // @translate
                    'url' => 'Attribute "url" of "eadid"', // @translate
                    'custom' => 'Custom, in the field below', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'ead_base_id',
                'value' => 'documentUri',
                'required' => false,
                'class' => 'chosen-select',
            ],
        ]);

        $this->add([
            'name' => 'ead_base_ids',
            'type' => Element\Textarea::class,
            'options' => [
                'label' => 'Custom base ids', // @translate
                'info' => "If \"custom\" is selected, specify the base ids to use, one by line, for each EAD xml file.\n
The base id should be linked to one of the attributes of the \"eadid\" element: \"publicid\", \"identifier\" or \"url\".", // @translate
            ],
            'attributes' => [
                'id' => 'ead_base_ids',
                'rows' => 5,
                'placeholder' => 'attribute value = base id of the file', // @translate
            ],
        ]);

        $this->add([
            'name' => 'ead_bypass_check',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Fix namespace/doctype', // @translate
                'info' => 'Some parsers don’t support the use of a doctype or require a namespace. Some servers don’t allow to fetch an external dtd, for example if the server is secure (https), but not the dtd (http only). This fix tries to bypass this issue.', //@translate
            ],
            'attributes' => [
                'id' => 'file',
                'required' => false,
            ],
        ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'url',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'ead_base_id',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'ead_base_ids',
            'required' => false,
            'filters' => [
                ['name' => Filter\StringTrim::class],
            ],
            'validators' => [
                [
                    'name' => Validator\Callback::class,
                    'options' => [
                        'callback' => [$this, 'validateExtraParameters'],
                        'callbackOptions' => [
                        ],
                        'messages' => [
                            Validator\Callback::INVALID_VALUE => 'Each base id, one by line, should have a name separated from the value with a "=".', // @translate
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Callback to check extra-parameters.
     *
     * @param string $value The value to check.
     * @param array $values
     * @param string $message
     * @return bool
     */
    public function validateExtraParameters($value, array $values, $option = null)
    {
        if ($values['ead_base_id'] !== 'custom') {
            return true;
        }

        $value = trim($value);
        if (empty($value)) {
            return true;
        }

        $parameters = $this->stringToList($value);
        foreach ($parameters as $parameter) {
            if (strpos($parameter, '=') === false) {
                return false;
            }

            list($paramName) = explode('=', $parameter);
            $paramName = trim($paramName);
            if ($paramName == '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Get each line of a string separately.
     *
     * @param string $string
     * @return array
     */
    protected function stringToList($string)
    {
        return array_filter(array_map('trim', explode("\n", $this->fixEndOfLine($string))));
    }

    /**
     * Clean the text area from end of lines.
     *
     * This method fixes Windows and Apple copy/paste from a textarea input.
     *
     * @param string $string
     * @return string
     */
    protected function fixEndOfLine($string)
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], $string);
    }
}
