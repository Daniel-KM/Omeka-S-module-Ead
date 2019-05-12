<?php
namespace BulkImportEad\View\Helper;

use Omeka\Api\Representation\ItemRepresentation;
use BulkImportEad\Mvc\Controller\Plugin\Ead as EadPlugin;
use Zend\View\Helper\AbstractHelper;

class Ead extends AbstractHelper
{
    /**
     * @param EadPlugin
     */
    protected $ead;

    /**
     * @param ItemRepresentation
     */
    protected $item;

    /**
     * @param EadPlugin $ead
     */
    public function __construct(EadPlugin $ead)
    {
        $this->ead = $ead;
    }

    /**
     * Get the thesaurus helper.
     *
     * @param ItemRepresentation $item
     * @return mixed.
     */
     public function __invoke(ItemRepresentation $item)
     {
        $ead = $this->ead;
        $ead($item);
        return $this;
    }

    /**
     * This item is an Ead archive if it's a finding aid or if it belongs to one.
     *
     * Required properties when a class "ead" is not set are dcterms:isPartOf
     * and dcterms:referencedBy.
     * For performance reason, it is recommended to use dcterms:referencedBy
     * currently, with a reference to the item that is a finding aid.
     *
     * The terms are checked with the prefix "ead" only.
     * The class may not be a ead class in order to manage sub classes of it,
     * not directly managed by Omeka.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::isArchive()
     * @return bool
     */
    public function isArchive()
    {
        return $this->ead->isArchive();
    }

    /**
     * This item is an archival finding aid  if it has the class ead:ArchivalFindingAid.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::isArchivalFindingAid()
     * @return bool
     */
    public function isArchivalFindingAid()
    {
        return $this->ead->isArchivalFindingAid();
    }

    /**
     * This item is an archival description  if it has the class ead:ArchivalDescription.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::isArchivalDescription()
     * @return bool
     */
    public function isArchivalDescription()
    {
        return $this->ead->isArchivalDescription();
    }

    /**
     * This item is a component if it has the class Component or belongs to an
     * archival finding aid.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::isComponent()
     * @return bool
     */
    public function isComponent()
    {
        return $this->ead->isComponent();
    }

    /**
     * Get the archival finding aid of this item.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::archivalFindingAid()
     * @return ItemRepresentation|null
     */
    public function archivalFindingAid()
    {
        return $this->ead->archivalFindingAid();
    }

    /**
     * Get the archival description of this item.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::archivalDescription()
     * @return ItemRepresentation|null
     */
    public function archivalDescription()
    {
        return $this->ead->archivalDescription();
    }

    /**
     * Check if the item is the root, i.e. archival finding aid itself.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::isRoot()
     * @return bool
     */
    public function isRoot()
    {
        return $this->ead->isRoot();
    }

    /**
     * Get the root item of this item (the archival finding aid).
     *
     * @todo Check performance to get the root item.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::root()
     * @return ItemRepresentation|null
     */
    public function root()
    {
        return $this->ead->root();
    }

    /**
     * Get the broader concept of this item.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::broader()
     * @return ItemRepresentation|null
     */
    public function broader()
    {
        return $this->ead->broader();
    }

    /**
     * Get the narrower concepts of this item.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::narrowers()
     * @return ItemRepresentation[]
     */
    public function narrowers()
    {
        return $this->ead->narrowers();
    }

    /**
     * Get the related items of this item via the term "dcterms:relation".
     *
     * Note: They may be outside of the finding aid.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::relateds()
     * @return ItemRepresentation[]
     */
    public function relateds()
    {
        return $this->ead->relateds();
    }

    /**
     * Get the sibling items of this item (self not included).
     *
     * To include this item, get the children (narrower iterms) of the broader
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::siblings()
     * @return ItemRepresentation[]
     */
    public function siblings()
    {
        return $this->ead->siblings();
    }

    /**
     * Get the list of ascendants of this item, from closest to archival finding aid.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::ascendants()
     * @return ItemRepresentation[]
     */
    public function ascendants()
    {
        return $this->ead->ascendants();
    }

    /**
     * Get the list of descendants of this item.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::descendants()
     * @return ItemRepresentation[]
     */
    public function descendants()
    {
        return $this->ead->descendants();
    }

    /**
     * Get the hierarchy of this item from the root (archival finding aid).
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::tree()
     * @return ItemRepresentation[]
     */
    public function tree()
    {
        return $this->ead->tree();
    }

    /**
     * Get the hierarchy branch of this item, self included.
     *
     * @uses \BulkImportEad\Mvc\Controller\Plugin\Ead::branch()
     * @return array
     */
    public function branch()
    {
        return $this->ead->branch();
    }

    /**
     * Display part of an archive.
     *
     * @param string|array|ItemRepresentation $typeOrData Type may be "archivalFindingAid"
     * (is "root"), or "archivalDescription" or "broader" (single), "narrowers",
     * "relateds", "siblings", "ascendants", or "descendants" (list), or "tree"
     * or "branch" (tree).
     * @param array $options Options for the partial. Managed default are
     * "title", "hideIfEmpty", and "partial".
     * @return string
     */
    public function display($typeOrData, array $options = [])
    {
        $type = $data = $typeOrData;
        if (is_string($typeOrData)) {
            $partialTypes = [
                'archivalFindingAid' => 'single',
                'archivalDescription' => 'single',
                'root' => 'single',
                'broader' => 'single',
                'narrowers' => 'list',
                'relateds' => 'list',
                'siblings' => 'list',
                'ascendants' => 'list',
                'descendants' => 'list',
                'tree' => 'tree',
                'branch' => 'tree',
            ];
            if (isset($partialTypes[$type])) {
                $data = $this->{$type}();
                $partial = $partialTypes[$type];
            } else {
                return '';
            }
        } else {
            $type = 'custom';
            if (is_array($data)) {
                $partial = is_array(reset($data)) ? 'tree' : 'list';
            } else {
                $partial = 'single';
            }
        }

        $partial = empty($options['partial'])
            ? 'common/ead-' . $partial
            : $options['partial'];
        unset($options['partial']);

        $options += ['title' => '', 'hideIfEmpty' => false];

        return $this->getView()->partial($partial, [
            'item' => $this->item,
            'type' => $type,
            'data' => $data,
            'options' => $options,
        ]);
    }
}
