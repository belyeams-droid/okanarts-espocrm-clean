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

use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Exception;
use stdClass;

/** @noinspection PhpUnused */
class UpdateRelatedEntity extends BaseEntity
{
    /**
     * @throws FormulaError
     * @throws Error
     */
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        $link = $actionData->link;

        $relatedEntities = $this->getRelatedEntities($entity, $link);

        $saveOptions = [
            'modifiedById' => 'system',
            'workflowId' => $this->getWorkflowId(),
        ];

        foreach ($relatedEntities as $relatedEntity) {
            if (!($relatedEntity instanceof Entity)) {
                continue;
            }

            $update = true;

            if (
                $entity->hasRelation($link) &&
                $entity->getRelationType($link) === 'belongsToParent' &&
                !empty($actionData->parentEntityType)
            ) {
                if ($actionData->parentEntityType !== $relatedEntity->getEntityType()) {
                    $update = false;
                }
            }

            if (!$update) {
                continue;
            }

            $data = $this->getDataToFill($relatedEntity, $actionData->fields);

            $relatedEntity->set($data);

            if (!empty($actionData->formula)) {
                $clonedVariables = clone $this->getFormulaVariables();

                $this->formulaManager->run($actionData->formula, $relatedEntity, $clonedVariables);
            }

            if (!$relatedEntity->has('modifiedById')) {
                $relatedEntity->set('modifiedByName', 'System');
            }

            $this->entityManager->saveEntity($relatedEntity, $saveOptions);
        }

        return true;
    }

    /**
     * Get Related Entity,
     *
     * @return EntityCollection|iterable<Entity>
     */
    protected function getRelatedEntities(Entity $entity, string $link)
    {
        if (empty($link) || !$entity->hasRelation($link)) {
            return [];
        }

        switch ($entity->getRelationType($link)) {
            case Entity::BELONGS_TO_PARENT:
                $parentType = $entity->get($link . 'Type');
                $parentId = $entity->get($link . 'Id');

                if (!$parentType || !$parentId) {
                    return [];
                }

                try {
                    $relatedEntity = $this->entityManager->getEntityById($parentType, $parentId);
                }
                catch (Exception $e) {
                    $GLOBALS['log']->info(
                        'Workflow[UpdateRelatedEntity]: Cannot getRelatedEntities(), error: '. $e->getMessage());

                    return [];
                }

                $fetched = $this->entityManager
                    ->getCollectionFactory()
                    ->create($entity->getEntityType(), [$relatedEntity]);

                break;

            case Entity::HAS_MANY:
            case Entity::HAS_CHILDREN:
            case Entity::MANY_MANY:
                $fetched = $this->entityManager
                    ->getRDBRepository($entity->getEntityType())
                    ->getRelation($entity, $link)
                    ->sth()
                    ->find();

                break;

            default:
                try {
                    $fetched = $this->entityManager
                        ->getRDBRepository($entity->getEntityType())
                        ->getRelation($entity, $link)
                        ->findOne();
                } catch (Exception $e) {
                    $GLOBALS['log']->info(
                        'Workflow[UpdateRelatedEntity]: Cannot getRelatedEntities(), error: '. $e->getMessage());

                    return [];
                }

                break;
        }

        if ($fetched instanceof Entity) {
            return $this->entityManager
                ->getCollectionFactory()
                ->create($entity->getEntityType(), [$fetched]);
        }

        return $fetched ?? [];
    }
}
