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

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\Entity;
use Espo\Core\Utils\Util;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class CreateRelatedEntity extends CreateEntity
{
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        $linkEntityType = $this->getLinkEntityType($entity, $actionData->link);

        if (!isset($linkEntityType)) {
            $GLOBALS['log']->error(
                'Workflow\Actions\\'.$actionData->type.': ' .
                'Cannot find an entity type of the link ['.$actionData->link.'] in the entity ' .
                '[' . $entity->getEntityType() . '].');

            return false;
        }

        $newEntity = $this->entityManager->getNewEntity($linkEntityType);

        $data = $this->getDataToFill($newEntity, $actionData->fields);

        $newEntity->set($data);

        $link = $actionData->link;

        $linkType = $entity->getRelationParam($link, 'type');

        $isRelated = false;

        if ($foreignLink = $entity->getRelationParam($link, 'foreign')) {
            $foreignRelationType = $newEntity->getRelationType($foreignLink);

            if (in_array($foreignRelationType, [Entity::BELONGS_TO, Entity::BELONGS_TO_PARENT])) {
                if ($foreignRelationType === Entity::BELONGS_TO) {
                    $newEntity->set($foreignLink. 'Id', $entity->getId());

                    $isRelated = true;
                } else if ($foreignRelationType === 'belongsToParent') {
                    $newEntity->set($foreignLink. 'Id', $entity->getId());
                    $newEntity->set($foreignLink. 'Type', $entity->getEntityType());

                    $isRelated = true;
                }
            }
        }

        $newEntity->set('id', $this->generateId());

        if (!empty($actionData->formula)) {
            $this->formulaManager->run($actionData->formula, $newEntity, $this->getFormulaVariables());
        }

        $saveOptions = [
            'workflowId' => $this->getWorkflowId(),
            'createdById' => $newEntity->get('createdById') ?? 'system',
        ];

        $this->entityManager->saveEntity($newEntity, $saveOptions);

        $newEntityId = $newEntity->getId();

        $newEntity = $this->entityManager->getEntityById($newEntity->getEntityType(), $newEntityId);

        if (!$isRelated && $linkType === Entity::BELONGS_TO) {
            $entity->set($link . 'Id', $newEntity->getId());
            $entity->set($link . 'Name', $newEntity->get('name'));

            $this->entityManager->saveEntity($entity, [
                'skipWorkflow' => true,
                'noStream' => true,
                'noNotifications' => true,
                'skipModifiedBy' => true,
                'skipCreatedBy' => true,
                'skipHooks' => true,
                'silent' => true,
            ]);

            $isRelated = true;
        }

        if (!$isRelated) {
            $this->entityManager
                ->getRDBRepository($entity->getEntityType())
                ->getRelation($entity, $actionData->link)
                ->relate($newEntity);
        }

        if ($this->createdEntitiesData && !empty($actionData->elementId) && !empty($actionData->id)) {
            $this->createdEntitiesDataIsChanged = true;

            $alias = $actionData->elementId . '_' . $actionData->id;

            $this->createdEntitiesData->$alias = (object) [
                'entityType' => $newEntity->getEntityType(),
                'entityId' => $newEntity->getId(),
            ];
        }

        if ($this->variables) {
            $this->variables->__lastCreatedEntityId = $newEntity->getId();
        }

        return true;
    }

    private function generateId(): string
    {
        if (
            interface_exists('Espo\\Core\\Utils\\Id\\RecordIdGenerator') &&
            method_exists($this->injectableFactory, 'createResolved') /** @phpstan-ignore-line */
        ) {
            return $this->injectableFactory->createResolved('Espo\\Core\\Utils\\Id\\RecordIdGenerator')
                ->generate();
        }

        return Util::generateId();
    }

    /**
     * Get an Entity name of a link.
     *
     * @return ?string
     */
    protected function getLinkEntityType(CoreEntity $entity, string $linkName)
    {
        if ($entity->hasRelation($linkName)) {
            return $entity->getRelationParam($linkName, 'entity');
        }

        return null;
    }
}
