<?php
namespace Ead\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Mvc\Controller\Plugin\Api;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * @todo Optimize structure building via direct queries to the database. See Omeka plugin Ead.
 */
class Ead extends AbstractPlugin
{
    const ROOT_CLASS = 'ead:ArchivalFindingAid';
    const ITEM_CLASS = 'ead:Component';
    const PARENT_TERM = 'dcterms:isPartOf';
    const CHILD_TERM = 'dcterms:hasPart';

    /**
     * @var ItemRepresentation
     */
    protected $item;

    /**
     * @var ItemRepresentation
     */
    protected $archivalFindingAid;

    /**
     * @var ItemRepresentation
     */
    protected $archivalDescription;

    /**
     * @var bool
     */
    protected $isArchive;

    /**
     * @var bool
     */
    protected $isArchivalFindingAid;

    /**
     * @var bool
     */
    protected $isArchivalDescription;

    /**
     * @var bool
     */
    protected $isComponent;

    /**
     * @param EntityManager
     */
    protected $entityManager;

    /**
     * @param Api
     */
    protected $api;

    /**
     * @param EntityManager $entityManager
     * @param Api $api
     */
    public function __construct(EntityManager $entityManager, Api $api)
    {
        $this->entityManager = $entityManager;
        $this->api = $api;
    }

    /**
     * Manage an Ead archive.
     *
     * @todo Build the lists and the tree without loading items.
     *
     * @param ItemRepresentation $item
     * @return self
     */
    public function __invoke(ItemRepresentation $item)
    {
        $this->item = $item;
        return $this;
    }

    /**
     * This item is an Ead archive if it's a finding aid or if it belongs to one.
     *
     * Required properties when a class "ead" is not set are ead:ead, dcterms:isPartOf
     * or dcterms:referencedBy.
     * For performance reason, it is recommended to use dcterms:referencedBy
     * currently, with a reference to the item that is a finding aid.
     *
     * The terms are checked with the prefix "ead" only.
     * The class may not be a ead class in order to manage sub classes of it,
     * not directly managed by Omeka.
     *
     * @return bool
     */
    public function isArchive()
    {
        if (is_null($this->isArchive)) {
            $class = $this->resourceClassName($this->item);
            $this->isArchive = in_array($class, [self::ROOT_CLASS, self::ITEM_CLASS, 'ead:ArchivalDescription']);
            if (!$this->isArchive) {
                $value = $this->item->value('ead:ead', ['type' => 'literal', 'default' => false]);
                $this->isArchive = $value && $value->value();
                if (!$this->isArchive) {
                    $this->isArchive = (bool) $this->resourceWithClassFromValue($this->item, 'dcterms:referencedBy', self::ROOT_CLASS);
                    if (!$this->isArchive) {
                        $root = $this->root();
                        $this->isArchive = $root
                            && $this->resourceClassName($root) === self::ROOT_CLASS;
                    }
                }
            }
        }
        return $this->isArchive;
    }

    /**
     * This item is an archival finding aid  if it has the class ead:ArchivalFindingAid.
     *
     * @return bool
     */
    public function isArchivalFindingAid()
    {
        if (is_null($this->isArchivalFindingAid)) {
            $this->isArchivalFindingAid = $this->resourceClassName($this->item) === self::ROOT_CLASS;
        }
        return $this->isArchivalFindingAid;
    }

    /**
     * This item is an archival description  if it has the class ead:ArchivalDescription.
     *
     * @return bool
     */
    public function isArchivalDescription()
    {
        if (is_null($this->isArchivalDescription)) {
            $this->isArchivalFindingAid = $this->resourceClassName($this->item) === 'ead:ArchivalDescription';
        }
        return $this->isArchivalFindingAid;
    }

    /**
     * This item is a component if it has the class Component or belongs to an
     * archival finding aid.
     *
     * @return bool
     */
    public function isComponent()
    {
        if (is_null($this->isComponent)) {
            if ($this->resourceClassName($this->item) === self::ITEM_CLASS) {
                $this->isComponent = true;
            } else {
                $this->isComponent = $this->item->value('ead:ead', ['type' => 'literal', 'default' => false]);
                if (!$this->isComponent) {
                    $root = $this->root();
                    $this->isComponent = $root
                        ? $this->resourceClassName($root) === self::ROOT_CLASS
                        : false;
                }
            }
        }
        return $this->isComponent;
    }

    /**
     * Get the archival finding aid of this item.
     *
     * @return ItemRepresentation|null
     */
    public function archivalFindingAid()
    {
        if (is_null($this->archivalFindingAid)) {
            $this->archivalFindingAid = $this->isArchivalFindingAid()
                ? $this->item
                : $this->root();
        }
        return $this->archivalFindingAid;
    }

    /**
     * Get the archival description of this item.
     *
     * @return ItemRepresentation|null
     */
    public function archivalDescription()
    {
        if (is_null($this->archivalDescription)) {
            if ($this->isArchivalDescription()) {
                $this->archivalDescription = $this->item;
            } else {
                $root = $this->root();
                $children = $this->children($root);
                foreach ($children as $child) {
                    $resourceClass = $child->resourceClass();
                    if ($resourceClass && $resourceClass->term() === 'ead:ArchiveDescription') {
                        $this->archivalDescription = $child;
                        break;
                    }
                }
            }
        }
        return $this->archivalDescription;
    }

    /**
     * Check if the item is the root, i.e. archival finding aid itself.
     *
     * @return bool
     */
    public function isRoot()
    {
        return $this->isArchivalFindingAid();
    }

    /**
     * Get the root item of this item (the archival finding aid).
     *
     * @todo Check performance to get the root item.
     *
     * @return ItemRepresentation|null
     */
    public function root()
    {
        return $this->ancestor($this->item);
    }

    /**
     * Get the broader concept of this item.
     *
     * @return ItemRepresentation|null
     */
    public function broader()
    {
        return $this->parent($this->item);
    }

    /**
     * Get the narrower concepts of this item.
     *
     * @return ItemRepresentation[]
     */
    public function narrowers()
    {
        return $this->children($this->item);
    }

    /**
     * Get the related items of this item via the term "dcterms:relation".
     *
     * Note: They may be outside of the finding aid.
     *
     * @return ItemRepresentation[]
     */
    public function relateds()
    {
        return $this->resourcesFromValue($this->item, 'dcterms:relation');
    }

    /**
     * Get the sibling items of this item (self not included).
     *
     * @return ItemRepresentation[]
     */
    public function siblings()
    {
        $result = [];

        if ($this->isArchivalFindingAid() || $this->isArchivalDescription()) {
            return $result;
        }

        $broader = $this->broader();
        if ($broader) {
            $result = $this->children($broader);
        } else {
            return $result;
        }

        $id = $this->item->id();
        foreach ($result as $key => $narrower) {
            if ($narrower->id() === $id) {
                unset($result[$key]);
                break;
            }
        }

        return $result;
    }

    /**
     * Get the sibling items of this item (self included).
     *
     * @return ItemRepresentation[]
     */
    public function siblingsOrSelf()
    {
        $result = [];

        if ($this->isArchivalFindingAid() || $this->isArchivalDescription()) {
            return $result;
        }

        $broader = $this->broader();
        if ($broader) {
            $result = $this->children($broader);
        } else {
            return $result;
        }

        return $result;
    }

    /**
     * Get the list of ascendants of this item, from closest to archival finding aid.
     *
     * @return ItemRepresentation[]
     */
    public function ascendants()
    {
        return $this->ancestors($this->item);
    }

    /**
     * Get the list of descendants of this item.
     *
     * @return ItemRepresentation[]
     */
    public function descendants()
    {
        return $this->listDescendants($this->item);
    }

    /**
     * Get the hierarchy of this item from the root (archival finding aid).
     *
     * @return ItemRepresentation[]
     */
    public function tree()
    {
        $result = [];
        $item = $this->ArchivalFindingAid();
        if ($item) {
            $result[] = [
                'self' => $item,
                'children' => $this->recursiveBranch($item),
            ];
        }
        return $result;
    }

    /**
     * Get the hierarchy branch of this item, self included.
     *
     * @return array
     */
    public function branch()
    {
        $result = [];
        $result[] = [
            'self' => $this->item,
            'children' => $this->recursiveBranch($this->item),
        ];
        return $result;
    }

    /**
     * Get the name of the current resource class.
     *
     * @param ItemRepresentation $item
     * @return string
     */
    protected function resourceClassName(ItemRepresentation $item)
    {
        $resourceClass = $item->resourceClass();
        return $resourceClass
            ? $resourceClass->term()
            : '';
    }

    /**
     * Get a linked resource of this item for a term.
     *
     * @param ItemRepresentation $item
     * @param string $term
     * @return ItemRepresentation|null
     */
    protected function resourceFromValue(ItemRepresentation $item, $term)
    {
        $values = $item->values();
        if (isset($values[$term])) {
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($values[$term]['values'] as $value) {
                if (in_array($value->type(), ['resource', 'resource:item'])) {
                    return $value->valueResource();
                }
            }
        }
    }

    /**
     * Get a linked resource with class of this item for a ter<m.
     *
     * @param ItemRepresentation $item
     * @param string $term
     * @param string|null $resourceClass
     * @return ItemRepresentation|null
     */
    protected function resourceWithClassFromValue(ItemRepresentation $item, $term, $resourceClass)
    {
        if (empty($resourceClass)) {
            return $this->resourceFromValue($item, $term);
        }

        $values = $item->values();
        if (isset($values[$term])) {
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($values[$term]['values'] as $value) {
                if (in_array($value->type(), ['resource', 'resource:item'])) {
                    $checkClass = $value->valueResource()->resourceClass();
                    if ($checkClass && $checkClass->term() === $resourceClass) {
                        return $value->valueResource();
                    }
                }
            }
        }
    }

    /**
     * Get all linked resources of this item for a term.
     *
     * @param ItemRepresentation $item
     * @param string $term
     * @return ItemRepresentation[]
     */
    protected function resourcesFromValue(ItemRepresentation $item, $term)
    {
        $result = [];
        $values = $item->values();
        if (isset($values[$term])) {
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($values[$term]['values'] as $value) {
                if (in_array($value->type(), ['resource', 'resource:item'])) {
                    // Manage private resources.
                    if ($resource = $value->valueResource()) {
                        // Manage duplicates.
                        $result[$resource->id()] = $resource;
                    }
                }
            }
        }
        return array_values($result);
    }

    /**
     * Get the broader item of an item.
     *
     * @param ItemRepresentation $item
     * @return ItemRepresentation|null
     */
    protected function parent(ItemRepresentation $item)
    {
        return $this->resourceFromValue($item, self::PARENT_TERM);
    }

    /**
     * Get the narrower items of an item.
     *
     * @param ItemRepresentation $item
     * @return ItemRepresentation|null
     */
    protected function children(ItemRepresentation $item)
    {
        return $this->resourcesFromValue($item, self::CHILD_TERM);
    }

    /**
     * Recursive method to get the top concept of an item.
     *
     * @param ItemRepresentation $item
     * @return ItemRepresentation
     */
    protected function ancestor(ItemRepresentation $item)
    {
        $parent = $this->parent($item);
        return $parent
            ? $this->ancestor($parent)
            : $item;
    }

    /**
     * Recursive method to get the ancestors of an item
     *
     * @param ItemRepresentation $item
     * @param array $list Internal param for recursive process.
     * @return ItemRepresentation[]
     */
    protected function ancestors(ItemRepresentation $item, array $list = [])
    {
        $parent = $this->parent($item);
        if ($parent) {
            $list[] = $parent;
            return $this->ancestors($parent, $list);
        }
        return $list;
    }

    /**
     * Recursive method to get the descendants of an item.
     *
     * @param ItemRepresentation $item$list
     * @param array $list Internal param for recursive process.
     * @return ItemRepresentation[]
     */
    protected function listDescendants(ItemRepresentation $item, array $list = [])
    {
        $children = $this->children($item);
        foreach ($children as $child) {
            $id = $child->id();
            if (!isset($list[$id])) {
                $list[$id] = $child;
                $list += $this->listDescendants($child, $list);
            }
        }
        return $list;
    }

    /**
     * Recursive method to get the descendant tree of an item.
     *
     * @param ItemRepresentation $item
     * @param array $branch Internal param for recursive process.
     * @return array
     */
    protected function recursiveBranch(ItemRepresentation $item, array $branch = [])
    {
        $children = $this->children($item);
        foreach ($children as $child) {
            $id = $child->id();
            if (!isset($branch[$id])) {
                $branch[$id] = [
                    'self' => $child,
                    'children' => $this->recursiveBranch($child),
                ];
            }
        }
        return $branch;
    }
}
