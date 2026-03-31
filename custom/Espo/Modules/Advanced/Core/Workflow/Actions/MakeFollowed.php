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
use Espo\Tools\Stream\Service as StreamService;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class MakeFollowed extends BaseEntity
{
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        if (empty($actionData->whatToFollow)) {
            $actionData->whatToFollow = 'targetEntity';
        }

        $target = $actionData->whatToFollow;

        $targetEntity = null;

        if ($target === 'targetEntity') {
            $targetEntity = $entity;
        }
        else if (str_starts_with($target, 'created:')) {
            $targetEntity = $this->getCreatedEntity($target);
        }
        else {
            $link = $target;

            if (str_starts_with($target, 'link:')) {
                $link = substr($target, 5);
            }

            $type = $this->metadata
                ->get("entityDefs.{$entity->getEntityType()}.links.$link.type");

            if (empty($type)) {
                return false;
            }

            $idField = $link . 'Id';

            if ($type == Entity::BELONGS_TO) {
                if (!$entity->get($idField)) {
                    return false;
                }

                $foreignEntityType = $this->metadata
                    ->get("entityDefs.{$entity->getEntityType()}.links.$link.entity");

                if (empty($foreignEntityType)) {
                    return false;
                }

                $targetEntity = $this->entityManager
                    ->getEntityById($foreignEntityType, $entity->get($idField));
            }
            else if ($type === Entity::BELONGS_TO_PARENT) {
                $typeField = $link . 'Type';

                if (!$entity->get($idField)) {
                    return false;
                }

                if (!$entity->get($typeField)) {
                    return false;
                }

                $targetEntity = $this->entityManager
                    ->getEntityById($entity->get($typeField), $entity->get($idField));
            }
        }

        if (!$targetEntity) {
            return false;
        }

        $userIdList = $this->getUserIdList($actionData);

        $streamService = $this->injectableFactory->create(StreamService::class);

        $streamService->followEntityMass($targetEntity, $userIdList);

        return true;
    }

    /**
     * @return string[]
     */
    protected function getUserIdList(stdClass $actionData): array
    {
        $entity = $this->getEntity();

        if (!empty($actionData->recipient)) {
            $recipient = $actionData->recipient;
        } else {
            $recipient = 'specifiedUsers';
        }

        $userIdList = [];

        if (isset($actionData->userIdList) && is_array($actionData->userIdList)) {
            $userIdList = $actionData->userIdList;
        }

        $teamIdList = [];

        if (isset($actionData->specifiedTeamsIds) && is_array($actionData->specifiedTeamsIds)) {
            $teamIdList = $actionData->specifiedTeamsIds;
        }

        return match ($recipient) {
            'specifiedUsers' => $userIdList,
            'specifiedTeams' => $this->workflowHelper->getUserIdsByTeamIds($teamIdList),
            'currentUser' => [$this->user->getId()],
            'teamUsers' => $this->workflowHelper->getUserIdsByTeamIds($entity->getLinkMultipleIdList('teams')),
            'followers' => $this->workflowHelper->getFollowerUserIds($entity),
            default => $this->getRecipients($this->getEntity(), $actionData->recipient)->getIds(),
        };
    }
}
