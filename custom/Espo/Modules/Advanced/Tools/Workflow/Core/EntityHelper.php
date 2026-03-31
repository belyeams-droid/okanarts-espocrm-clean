<?php
/***********************************************************************************
 * The contents of this file are subject to the Extension License Agreement
 * ("Agreement") which can be viewed at
 * https://www.espocrm.com/extension-license-agreement/.
 * By copying, installing downloading, or using this file, You have unconditionally
 * agreed to the terms and conditions of the Agreement, and You may not use this
 * file except in compliance with the Agreement. Under the terms of the Agreement,
 * You shall not license, sublicense, sell, resell, rent, lease, lend, distribute,
 * redistribute, market, publish, commercialize, or otherwise transfer rights or
 * usage to the software or any modified version or derivative work of the software
 * created by or for you.
 *
 * Copyright (C) 2015-2025 EspoCRM, Inc.
 *
 * License ID: 33bfb348770a2d79a1348fb026046b7f
 ************************************************************************************/

namespace Espo\Modules\Advanced\Tools\Workflow\Core;

use Espo\Core\Container;
use Espo\Core\FieldProcessing\SpecificFieldLoader;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\FieldUtil;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

use Exception;
use stdClass;

class EntityHelper
{
    /**
     * For bc the type is in the docblock.
     *
     * @var ?SpecificFieldLoader
     */
    private $specificFieldLoader = null;

    public function __construct(
        private Container $container,
        private EntityManager $entityManager,
        private ServiceContainer $serviceContainer,
        private Metadata $metadata,
        private FieldUtil $fieldUtil,
    ) {}

    private function getSpecificFieldLoader(): ?SpecificFieldLoader
    {
        if (!class_exists("Espo\\Core\\FieldProcessing\\SpecificFieldLoader")) {
            return null;
        }

        if (!$this->specificFieldLoader) {
            $this->specificFieldLoader = $this->container
                ->getByClass(InjectableFactory::class)
                ->create(SpecificFieldLoader::class);
        }

        return $this->specificFieldLoader;
    }

    /**
     * @param string $fieldName
     * @return string
     */
    private function normalizeRelatedFieldName(CoreEntity $entity, $fieldName)
    {
        if ($entity->hasRelation($fieldName)) {
            $type = $entity->getRelationType($fieldName);

            $key = $entity->getRelationParam($fieldName, 'key');
            $foreignKey = $entity->getRelationParam($fieldName, 'foreignKey');

            switch ($type) {
                case Entity::HAS_CHILDREN:
                    if ($foreignKey) {
                        $fieldName = $foreignKey;
                    }

                    break;

                case Entity::BELONGS_TO:
                    if ($key) {
                        $fieldName = $key;
                    }

                    break;

                case Entity::HAS_MANY:
                case Entity::MANY_MANY:
                    $fieldName .= 'Ids';

                    break;
            }
        }

        return $fieldName;
    }

    /**
     * Get actual attribute list w/o additional.
     *
     * @param Entity $entity
     * @param string $field
     * @return string[]
     */
    public function getActualAttributes(Entity $entity, string $field): array
    {
        $entityType = $entity->getEntityType();

        $list = [];
        $actualList = $this->fieldUtil->getActualAttributeList($entityType, $field);
        $additionalList = $this->fieldUtil->getAdditionalActualAttributeList($entityType, $field);

        foreach ($actualList as $item) {
            if (!in_array($item, $additionalList)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Get field value for a field/related field. If this field has a relation, get value from the relation.
     */
    public function getFieldValues(
        CoreEntity $fromEntity,
        CoreEntity $toEntity,
        string $fromField,
        string $toField
    ): stdClass {

        $entity = $fromEntity;
        $field = $fromField;

        $values = (object) [];

        if (str_contains($field, '.')) {
            [$relation, $foreignField] = explode('.', $field);

            $relatedEntity = $this->getRelatedEntity($entity, $relation);

            if (!$relatedEntity instanceof CoreEntity) {
                $GLOBALS['log']->debug(
                    "Workflow EntityHelper:getFieldValues: No related record for '$field', entity " .
                    "{$entity->getEntityType()}.");

                return (object) [];
            }

            $entity = $relatedEntity;
            $field = $foreignField;
        }

        if ($entity->hasRelation($field) && !$entity->isNew()) {
            $this->loadLink($entity, $field);
        }

        $fromType = $this->getFieldType($entity, $field);
        $toType = $this->getFieldType($toEntity, $toField);

        if (
            $fromType === 'link' &&
            $toType === 'linkParent'
        ) {
            return $this->getFieldValuesLinkToLinkParent($entity, $field, $toField);
        }

        if (
            $fromField === 'id' &&
            $toType === 'linkParent'
        ) {
            return $this->getFieldValuesIdToLinkParent($entity, $toField);
        }

        $attributeMap = $this->getRelevantAttributeMap($entity, $toEntity, $field, $toField);

        $service = $this->serviceContainer->get($entity->getEntityType());

        $toAttribute = null;

        $this->loadFieldForAttributes($entity, $field, array_keys($attributeMap));

        foreach ($attributeMap as $fromAttribute => $toAttribute) {
            // @todo Revise.
            $getCopiedMethodName = 'getCopied' . ucfirst($fromAttribute);

            if (method_exists($entity, $getCopiedMethodName)) {
                $values->$toAttribute = $entity->$getCopiedMethodName();

                continue;
            }

            // @todo Revise.
            $getCopiedMethodName = 'getCopiedEntityAttribute' . ucfirst($fromAttribute);

            if (method_exists($service, $getCopiedMethodName)) {
                $values->$toAttribute = $service->$getCopiedMethodName($entity);

                continue;
            }

            $values->$toAttribute = $entity->get($fromAttribute);
        }

        $toFieldType = $this->getFieldType($toEntity, $toField);

        if ($toFieldType === 'personName' && $toAttribute) {
            $this->handlePersonName($toAttribute, $values, $toField);
        }

        // Correct field types. E.g. set teamsIds from defaultTeamId.
        if ($toEntity->hasRelation($toField)) {
            $normalizedFieldName = $this->normalizeRelatedFieldName($toEntity, $toField);

            if (
                $toEntity->getRelationType($toField) === Entity::MANY_MANY &&
                isset($values->$normalizedFieldName) &&
                !is_array($values->$normalizedFieldName)
            ) {
                $values->$normalizedFieldName = (array) $values->$normalizedFieldName;
            }
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function getRelevantAttributeMap(
        Entity $fromEntity,
        Entity $toEntity,
        string $fromField,
        string $toField
    ): array {

        $fromAttributeList = $this->getActualAttributes($fromEntity, $fromField);
        $toAttributeList = $this->getActualAttributes($toEntity, $toField);

        $fromType = $this->getFieldType($fromEntity, $fromField);
        $toType = $this->getFieldType($toEntity, $toField);

        $ignoreActualAttributesOnValueCopyFieldList = $this->metadata
            ->get(['entityDefs', 'Workflow', 'ignoreActualAttributesOnValueCopyFieldList'], []);

        if (in_array($fromType, $ignoreActualAttributesOnValueCopyFieldList)) {
            $fromAttributeList = [$fromField];
        }

        if (in_array($toType, $ignoreActualAttributesOnValueCopyFieldList)) {
            $toAttributeList = [$toField];
        }

        $attributeMap = [];

        if (count($fromAttributeList) == count($toAttributeList)) {
            if (
                $fromType === 'datetimeOptional' &&
                $toType === 'datetimeOptional'
            ) {
                if ($fromEntity->get($fromAttributeList[1])) {
                    $attributeMap[$fromAttributeList[1]] = $toAttributeList[1];
                } else {
                    $attributeMap[$fromAttributeList[0]] = $toAttributeList[0];
                }

                return $attributeMap;
            }

            foreach ($fromAttributeList as $key => $name) {
                $attributeMap[$name] = $toAttributeList[$key];
            }

            return $attributeMap;
        }

        if (
            $fromType === 'datetimeOptional' ||
            $toType === 'datetimeOptional'
        ) {
            if (count($toAttributeList) > count($fromAttributeList)) {
                if ($fromType === 'date') {
                    $attributeMap[$fromAttributeList[0]] = $toAttributeList[1];
                } else {
                    $attributeMap[$fromAttributeList[0]] = $toAttributeList[0];
                }

                return $attributeMap;
            }

            if ($toType === 'date') {
                if ($fromEntity->get($fromAttributeList[1])) {
                    $attributeMap[$fromAttributeList[1]] = $toAttributeList[0];
                } else {
                    $attributeMap[$fromAttributeList[0]] = $toAttributeList[0];
                }
            } else {
                $attributeMap[$fromAttributeList[0]] = $toAttributeList[0];
            }
        }

        return $attributeMap;
    }

    private function handlePersonName(string $toAttribute, stdClass $values, string $toField): void
    {
        if (empty($values->$toAttribute)) {
            return;
        }

        $fullNameValue = trim($values->$toAttribute);

        $firstNameAttribute = 'first' . ucfirst($toField);
        $lastNameAttribute = 'last' . ucfirst($toField);

        if (!str_contains($fullNameValue, ' ')) {
            $lastNameValue = $fullNameValue;
            $firstNameValue = null;
        } else {
            $index = strrpos($fullNameValue, ' ');
            $firstNameValue = substr($fullNameValue, 0, $index ?: 0);
            $lastNameValue = substr($fullNameValue, $index + 1);
        }

        $values->$firstNameAttribute = $firstNameValue;
        $values->$lastNameAttribute = $lastNameValue;
    }

    private function loadLink(Entity $entity, string $field): void
    {
        if (!$entity instanceof CoreEntity) {
            return;
        }

        switch ($entity->getRelationType($field)) { // ORM types
            case Entity::MANY_MANY:
            case Entity::HAS_CHILDREN:
                try {
                    $entity->loadLinkMultipleField($field);
                } catch (Exception) {}

                break;

            case Entity::BELONGS_TO:
            case Entity::HAS_ONE:
                try {
                    $entity->loadLinkField($field);
                } catch (Exception) {}

                break;
        }
    }

    public function getFieldType(Entity $entity, string $field): ?string
    {
        return $this->metadata->get(['entityDefs', $entity->getEntityType(), 'fields', $field, 'type']);
    }

    private function getRelatedEntity(CoreEntity $entity, string $relation): ?Entity
    {
        if (!$entity->hasRelation($relation)) {
            return null;
        }

        $relatedEntity = null;

        if ($entity->hasId()) {
            $relatedEntity = $this->entityManager
                ->getRDBRepository($entity->getEntityType())
                ->getRelation($entity, $relation)
                ->findOne();

            if ($relatedEntity) {
                return $relatedEntity;
            }
        }

        // If the entity is just created and doesn't have relations yet.

        $foreignEntityType = $entity->getRelationParam($relation, 'entity');
        $idAttribute = $this->normalizeRelatedFieldName($entity, $relation);

        if (
            $foreignEntityType &&
            $entity->hasAttribute($idAttribute) &&
            $entity->get($idAttribute)
        ) {
            $relatedEntity = $this->entityManager->getEntityById($foreignEntityType, $entity->get($idAttribute));
        }

        return $relatedEntity;
    }

    private function getFieldValuesLinkToLinkParent(
        CoreEntity $fromEntity,
        string $fromField,
        string $toField
    ): stdClass {

        $sourceRecordId = $fromEntity->get($fromField . 'Id');
        $foreignEntityType = $fromEntity->getRelationParam($fromField, 'entity');

        if (!$sourceRecordId || !$foreignEntityType) {
            return (object) [
                $toField . 'Id' => null,
                $toField . 'Type' => null,
                $toField . 'Name' => null,
            ];
        }

        return (object) [
            $toField . 'Id' => $sourceRecordId,
            $toField . 'Type' => $foreignEntityType,
            $toField . 'Name' => $fromEntity->get($fromField . 'Name'),
        ];
    }

    private function getFieldValuesIdToLinkParent(CoreEntity $fromEntity, string $toField): stdClass
    {
        return (object) [
            $toField . 'Id' => $fromEntity->getId(),
            $toField . 'Type' => $fromEntity->getEntityType(),
            $toField . 'Name' => $fromEntity->get('name'),
        ];
    }

    /**
     * @param string[] $attributes
     */
    private function loadFieldForAttributes(CoreEntity $entity, string $field, array $attributes): void
    {
        $hasNotSet = $this->hasNotSetAttribute($entity, $attributes);

        if (!$hasNotSet) {
            return;
        }

        $this->getSpecificFieldLoader()->process($entity, $field);
    }

    /**
     * @param string[] $attributes
     */
    private function hasNotSetAttribute(CoreEntity $entity, array $attributes): bool
    {
        $hasNotSet = false;

        foreach ($attributes as $it) {
            if (!$entity->has($it)) {
                $hasNotSet = true;

                break;
            }
        }

        return $hasNotSet;
    }
}
