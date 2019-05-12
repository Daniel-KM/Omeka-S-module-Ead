<?php
namespace BulkImportEad\Form;

use Zend\Form\Element;
use Zend\Form\Form;

class ImportForm extends Form
{
    public function init()
    {
        // The action attribute is set via the controller.

        $this->add([
            'name' => 'file',
            'type' => Element\File::class,
            'options' => [
                'label' => 'EAD xml file', // @translate
                'info'  => 'The EAD is a simple xml file.', //@translate
            ],
            'attributes' => [
                'id' => 'file',
                'required' => true,
            ],
        ]);

        $this->add([
            'name' => 'ead_max_level',
            'type' => Element\Number::class,
            'options' => [
                'label' => 'Maximum number of levels', // @translate
                'info'  => "To set a maximum number of levels avoids heavy processing.\n
The recommended is 15: 12 components, an archival description, a finding aid and one more.\n
If \"dsc\" are used as item, this can be 30.", // @translate
            ],
            'attributes' => [
                'id' => 'ead_max_level',
                'value' => '15',
                'step' => 1,
                'min' => 1,
                'max' => 99,
            ],
        ]);

        // TODO See Ead for Omeka for other options (specific or custom base ids).
        // ead_base_id, ead_base_ids, extra_parameters.
    }
}
