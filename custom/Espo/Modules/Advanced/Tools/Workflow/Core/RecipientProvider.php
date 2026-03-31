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

use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Record\ServiceFactory;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Workflow\Core\FieldValueHelper;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\Stream\Service;
use RuntimeException;

class RecipientProvider
{
    public function __construct(
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory,
        private ServiceFactory $serviceFactory,
        private FieldValueHelper $fieldValueHelper,
    ) {}

    public function get(Entity $entity, string $target): RecipientIds
    {
        if (!$entity instanceof CoreEntity) {
            return new RecipientIds();
        }

        $link = $target;
        $targetEntity = $entity;

        if (str_starts_with($link, 'link:')) {
            $link = substr($link, 5);
        }

        if (strpos($link, '.')) {
            [$firstLink, $link] = explode('.', $link);

            $relationType = $entity->getRelationType($firstLink);

            if (in_array($relationType, [Entity::HAS_MANY, Entity::MANY_MANY])) {
                $collection = $this->entityManager
                    ->getRDBRepository($entity->getEntityType())
                    ->getRelation($entity, $firstLink)
                    ->sth()
                    ->find();

                $ids = [];
                $entityType = null;

                foreach ($collection as $targetEntity) {
                    $entityType ??= $targetEntity->getEntityType();

                    $itemIds = $this->get($targetEntity, "link:$link")->getIds();

                    $ids = array_merge($ids, $itemIds);
                }

                return new RecipientIds($entityType, array_unique($ids));
            }

            $targetEntity = $this->entityManager
                ->getRDBRepository($entity->getEntityType())
                ->getRelation($entity, $firstLink)
                ->findOne();

            if (!$targetEntity) {
                return new RecipientIds();
            }
        }

        if ($link === 'followers') {
            if (!class_exists("Espo\\Tools\\Stream\\Service")) {
                /** @noinspection PhpUndefinedMethodInspection */
                return new RecipientIds(
                    User::ENTITY_TYPE,
                    /** @phpstan-ignore-next-line  */
                    $this->serviceFactory->create('Stream')->getEntityFolowerIdList($targetEntity)
                );
            }

            /** @var Service $streamService */
            $streamService = $this->injectableFactory->create("Espo\\Tools\\Stream\\Service");

            return new RecipientIds(
                User::ENTITY_TYPE,
                $streamService->getEntityFollowerIdList($targetEntity)
            );
        }

        if (
            $targetEntity->hasRelation($link) &&
            (
                $targetEntity->getRelationType($link) === Entity::HAS_MANY ||
                $targetEntity->getRelationType($link) === Entity::MANY_MANY
            )
        ) {
            $collection = $this->entityManager
                ->getRDBRepository($targetEntity->getEntityType())
                ->getRelation($targetEntity, $link)
                ->select(['id'])
                ->sth()
                ->find();

            $ids = [];
            $entityType = null;

            foreach ($collection as $e) {
                $ids[] = $e->getId();

                $entityType ??= $e->getEntityType();
            }

            return new RecipientIds($entityType, $ids);
        }

        if (!$targetEntity instanceof CoreEntity) {
            throw new RuntimeException();
        }

        $fieldEntity = $this->fieldValueHelper->getValue($targetEntity, $link, true);

        if ($fieldEntity instanceof Entity) {
            return new RecipientIds($fieldEntity->getEntityType(), [$fieldEntity->getId()], true);
        }

        return new RecipientIds();
    }
}
