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

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

class TargetProvider
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private User $user
    ) {}

    /**
     * @return iterable<Entity>
     */
    public function get(Entity $entity, ?string $target, ?stdClass $createdEntitiesData = null): iterable
    {
        if (!$target || $target === 'targetEntity') {
            if (!$entity->hasId()) {
                return [];
            }

            $targetEntity = $this->entityManager->getEntityById($entity->getEntityType(), $entity->getId());

            return self::wrapEntityIntoArray($targetEntity);
        }

        if (str_starts_with($target, 'created:')) {
            return self::wrapEntityIntoArray(
                $this->getCreated($target, $createdEntitiesData)
            );
        }

        if (str_starts_with($target, 'link:')) {
            $path = explode('.', substr($target, 5));

            $pointerEntity = $entity;

            foreach ($path as $i => $link) {
                $type = $this->metadata->get(['entityDefs', $pointerEntity->getEntityType(), 'links', $link, 'type']);

                if (!$type) {
                    throw new Error("Workflow action: Bad target $target. Not existing link.");
                }

                $isLast = $i === count($path) - 1;

                $relation = $this->entityManager
                    ->getRDBRepository($pointerEntity->getEntityType())
                    ->getRelation($pointerEntity, $link);

                if ($isLast) {
                    return $relation->sth()->find();
                }

                $pointerEntity = $this->entityManager
                    ->getRDBRepository($pointerEntity->getEntityType())
                    ->getRelation($pointerEntity, $link)
                    ->findOne();

                if (!$pointerEntity instanceof Entity) {
                    return [];
                }
            }

            return [];
        }

        if ($target == 'currentUser') {
            return [$this->user];
        }

        return [];
    }

    public function getCreated(string $target, ?stdClass $createdEntitiesData): ?Entity
    {
        $alias = str_starts_with($target, 'created:') ? substr($target, 8) : $target;

        if (!$createdEntitiesData) {
            return null;
        }

        if (!property_exists($createdEntitiesData, $alias)) {
            return null;
        }

        $id = $createdEntitiesData->$alias->entityId ?? null;
        $entityType = $createdEntitiesData->$alias->entityType ?? null;

        if (!$id || !$entityType) {
            return null;
        }

        return $this->entityManager->getEntityById($entityType, $id);
    }

    /**
     * @param Entity|null $entity
     * @return Entity[]
     */
    private static function wrapEntityIntoArray(?Entity $entity): array
    {
        if (!$entity) {
            return [];
        }

        return [$entity];
    }
}
