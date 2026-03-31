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

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Modules\Advanced\Core\Workflow\Utils;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;
use stdClass;

class FieldValueHelper
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private FieldLoaderHelper $fieldLoaderHelper,
    ) {}

    /**
     * Get field value for a field/related field. If this field has a relation, get the value from the relation.
     *
     * @param ?string $path A field path.
     */
    public function getValue(
        CoreEntity $entity,
        ?string $path,
        bool $returnEntity = false,
        ?stdClass $createdEntitiesData = null
    ): mixed {

        if (str_starts_with($path, 'created:')) {
            [$alias, $field] = explode('.', substr($path, 8));

            if (!$createdEntitiesData || !isset($createdEntitiesData->$alias)) {
                return null;
            }

            $entityTypeValue = $createdEntitiesData->$alias->entityType ?? null;
            $entityIdValue = $createdEntitiesData->$alias->entityId ?? null;

            if (!$entityTypeValue || !$entityIdValue) {
                return null;
            }

            $entity = $this->entityManager->getEntityById($entityTypeValue, $entityIdValue);

            if (!$entity) {
                return null;
            }

            $path = $field;
        } else if (str_contains($path, '.')) {
            [$first, $foreignName] = explode('.', $path);

            $relatedEntity = $this->getRelatedEntity($entity, $first);

            if ($relatedEntity instanceof CoreEntity) {
                $entity = $relatedEntity;

                $path = $foreignName;
            } else {
                $this->log->warning("Workflow: Could not get related entity by path '$path'.");

                return null;
            }
        }

        if (!$entity instanceof CoreEntity) {
            throw new RuntimeException();
        }

        if ($path && $entity->hasRelation($path)) {
            $relatedEntity = $this->getRelatedEntityForRelation($entity, $path);

            if ($relatedEntity instanceof CoreEntity) {
                $foreignKey = $entity->getRelationParam($path, 'foreignKey') ?? 'id';

                return $returnEntity ? $relatedEntity : $relatedEntity->get($foreignKey);
            }

            if (!$relatedEntity) {
                $normalizedFieldName = Utils::normalizeFieldName($entity, $path);

                if (!$entity->isNew() && $entity->hasLinkMultipleField($path)) {
                    $entity->loadLinkMultipleField($path);
                }

                if ($entity->getRelationType($path) === Entity::BELONGS_TO_PARENT && !$returnEntity) {
                    return null;
                }

                $fieldValue = $returnEntity ?
                    $this->getParentEntity($entity, $path) :
                    $this->getParentValue($entity, $normalizedFieldName);

                if (isset($fieldValue)) {
                    return $fieldValue;
                }
            }

            if ($entity->hasLinkMultipleField($path)) {
                $entity->loadLinkMultipleField($path);
            }

            if ($relatedEntity) {
                return null;
            }

            return $entity->get($path . 'Ids');
        }

        switch ($entity->getAttributeType($path)) {
            // @todo Revise.
            case 'linkParent':
                $path .= 'Id';

                break;
        }

        if ($returnEntity) {
            return $entity;
        }

        if (!$entity->hasAttribute($path)) {
            return null;
        }

        if (!$entity->has($path)) {
            $this->fieldLoaderHelper->load($entity, $path);
        }

        return $entity->get($path);

    }

    /**
     * @return CoreEntity|Entity|null
     */
    private function getParentEntity(CoreEntity $entity, string $fieldName)
    {
        if (!$entity->hasRelation($fieldName)) {
            return $entity;
        }

        $normalizedFieldName = Utils::normalizeFieldName($entity, $fieldName);

        $fieldValue = $this->getParentValue($entity, $normalizedFieldName);

        if (isset($fieldValue) && is_string($fieldValue)) {
            $fieldEntityDefs = $this->entityManager->getMetadata()->get($entity->getEntityType());

            if (isset($fieldEntityDefs['relations'][$fieldName]['entity'])) {
                $fieldEntity = $fieldEntityDefs['relations'][$fieldName]['entity'];

                return $this->entityManager->getEntityById($fieldEntity, $fieldValue);
            }
        }

        return null;
    }

    /**
     * Get parent field value. Works for parent and regular fields,
     *
     * @param string|string[] $normalizedFieldName
     * @return mixed
     */
    private function getParentValue(Entity $entity, $normalizedFieldName)
    {
        if (is_array($normalizedFieldName)) {
            $value = [];

            foreach ($normalizedFieldName as $fieldName) {
                if ($entity->hasAttribute($fieldName)) {
                    $value[$fieldName] = $entity->get($fieldName);
                }
            }

            return $value;
        }

        if ($entity->hasAttribute($normalizedFieldName)) {
            return $entity->get($normalizedFieldName);
        }

        return null;
    }

    private function getRelatedEntityForRelation(CoreEntity $entity, string $relation): ?Entity
    {
        if ($entity->getRelationType($relation) === Entity::BELONGS_TO_PARENT) {
            $valueType = $entity->get($relation . 'Type');
            $valueId = $entity->get($relation . 'Id');

            if ($valueType && $valueId) {
                return $this->entityManager->getEntityById($valueType, $valueId);
            }

            return null;
        }

        if (in_array($entity->getRelationType($relation), [Entity::BELONGS_TO, Entity::HAS_ONE])) {
            return $this->entityManager
                ->getRDBRepository($entity->getEntityType())
                ->getRelation($entity, $relation)
                ->findOne();
        }

        return null;
    }

    private function getRelatedEntity(CoreEntity $entity, string $relation): ?Entity
    {
        if (!$entity->hasRelation($relation)) {
            return null;
        }

        if (
            in_array($entity->getRelationType($relation), [
                Entity::BELONGS_TO,
                Entity::HAS_ONE,
                Entity::BELONGS_TO_PARENT,
            ])
        ) {
            $relatedEntity = $this->entityManager
                ->getRDBRepository($entity->getEntityType())
                ->getRelation($entity, $relation)
                ->findOne();

            if ($relatedEntity) {
                return $relatedEntity;
            }
        }

        // If the entity is just created and doesn't have added relations.

        $foreignEntityType = $entity->getRelationParam($relation, 'entity');
        $idAttribute = Utils::normalizeFieldName($entity, $relation);

        if (
            !$foreignEntityType ||
            !$entity->hasAttribute($idAttribute) ||
            !$entity->get($idAttribute)
        ) {
            return null;
        }

        return $this->entityManager->getEntityById($foreignEntityType, $entity->get($idAttribute));
    }
}
