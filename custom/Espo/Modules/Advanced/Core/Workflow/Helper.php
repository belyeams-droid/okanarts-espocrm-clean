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

namespace Espo\Modules\Advanced\Core\Workflow;

use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\Stream\Service as StreamService;

class Helper
{
    public function __construct(
        private EntityManager $entityManager,
        private StreamService $streamService,
    ) {}

    /**
     * Get followers users ids.
     *
     * @return string[]
     */
    public function getFollowerUserIds(Entity $entity): array
    {
        return $this->streamService->getEntityFollowerIdList($entity);
    }

    /**
     * Get followers users ids excluding assignedUserId.
     *
     * @param Entity $entity
     * @return string[]
     */
    public function getFollowerUserIdsExcludingAssignedUser(Entity $entity): array
    {
        $userIds = $this->getFollowerUserIds($entity);

        if ($entity->get('assignedUserId')) {
            $assignedUserId = $entity->get('assignedUserId');
            $userIds = array_diff($userIds, [$assignedUserId]);
        }

        return $userIds;
    }

    /**
     * Get user ids for team ids.
     *
     * @param string[] $teamIds
     * @return string[]
     */
    public function getUserIdsByTeamIds(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $userIds = [];

        $users = $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->select('id')
            ->distinct()
            ->join('teams', 'teams')
            ->where(['teams.id' => $teamIds])
            ->where(['isActive' => true])
            ->find();

        foreach ($users as $user) {
            $userIds[] = $user->getId();
        }

        return $userIds;
    }

    /**
     * Get email addresses for an entity with specified ids.
     *
     * @param string $entityType
     * @param string[] $entityIds
     * @return string[]
     */
    public function getEmailAddressesForEntity(string $entityType, array $entityIds): array
    {
        $entityList = $this->entityManager
            ->getRDBRepository($entityType)
            ->select(['id', 'emailAddress'])
            ->where(['id' => $entityIds])
            ->find();

        $list = [];

        foreach ($entityList as $entity) {
            $emailAddress = $entity->get('emailAddress');

            if ($emailAddress) {
                $list[] = $emailAddress;
            }
        }

        return $list;
    }

    /**
     * Get primary email addresses for user list.
     *
     * @param string[] $userIds
     * @return string[]
     */
    public function getUsersEmailAddress(array $userIds): array
    {
        return $this->getEmailAddressesForEntity(User::ENTITY_TYPE, $userIds);
    }
}
