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

namespace Espo\Modules\Advanced\Business\Workflow\AssignmentRules;

use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Exceptions\Error;
use Espo\Modules\Advanced\Entities\WorkflowRoundRobin;
use Espo\ORM\Query\Part\WhereItem;

class RoundRobin
{
    /** @var EntityManager */
    private $entityManager;
    /** @var string */
    private $entityType;
    /** @var string */
    private $actionId;
    /** @var string|null */
    private $workflowId;
    /** @var string|null */
    private $flowchartId;

    public function __construct(
        EntityManager $entityManager,
        string $entityType,
        string $actionId,
        ?string $workflowId = null,
        ?string $flowchartId = null
    ) {
        $this->entityManager = $entityManager;
        $this->entityType = $entityType;
        $this->actionId = $actionId;
        $this->workflowId = $workflowId;
        $this->flowchartId = $flowchartId;
    }

    /**
     * @param array<string|int, mixed>|WhereItem|null $whereClause
     * @return array<string, mixed>
     * @throws Error
     */
    public function getAssignmentAttributes(
        Entity $entity,
        string $targetTeamId,
        ?string $targetUserPosition,
        ?string $listReportId = null,
        $whereClause = null
    ): array {

        $team = $this->entityManager->getEntityById(Team::ENTITY_TYPE, $targetTeamId);

        if (!$team) {
            throw new Error('RoundRobin: No team with id ' . $targetTeamId);
        }

        $where = [
            'isActive' => true,
        ];

        if ($targetUserPosition) {
            $where['@relation.role'] = $targetUserPosition;
        }

        $userList = $this->entityManager
            ->getRDBRepository(Team::ENTITY_TYPE)
            ->getRelation($team, 'users')
            ->select('id')
            ->order('id')
            ->where($where)
            ->find();

        if (is_countable($userList) && count($userList) === 0) {
            throw new Error('RoundRobin: No users found in team ' . $targetTeamId);
        }

        $userIdList = [];

        foreach ($userList as $user) {
            $userIdList[] = $user->getId();
        }

        // @todo lock table ?

        $lastUserId = $this->getLastUserId();

        if ($lastUserId === null) {
            $num = 0;
        }
        else {
            $num = array_search($lastUserId, $userIdList);

            if ($num === false || $num == count($userIdList) - 1) {
                $num = 0;
            }
            else {
                $num++;
            }
        }

        $userId = $userIdList[$num];

        $this->storeLastUserId($userId);

        $attributes = ['assignedUserId' => $userId];

        if ($attributes['assignedUserId']) {
            /** @var ?User $user */
            $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $attributes['assignedUserId']);

            if ($user) {
                $attributes['assignedUserName'] = $user->getName();
            }
        }

        return $attributes;
    }

    private function getLastUserId(): ?string
    {
        $item = $this->entityManager
            ->getRDBRepository(WorkflowRoundRobin::ENTITY_TYPE)
            ->select(['lastUserId'])
            ->where([
                'actionId' => $this->actionId,
                'workflowId' => $this->workflowId,
                'flowchartId' => $this->flowchartId,
            ])
            ->findOne();

        if (!$item) {
            return null;
        }

        return $item->get('lastUserId');
    }

    private function storeLastUserId(string $userId): void
    {
        $item = $this->entityManager
            ->getRDBRepository(WorkflowRoundRobin::ENTITY_TYPE)
            ->select(['id', 'lastUserId'])
            ->where([
                'actionId' => $this->actionId,
                'workflowId' => $this->workflowId,
                'flowchartId' => $this->flowchartId,
            ])
            ->findOne();

        if (!$item) {
            $this->entityManager->createEntity(WorkflowRoundRobin::ENTITY_TYPE, [
                'actionId' => $this->actionId,
                'lastUserId' => $userId,
                'entityType' => $this->entityType,
                'workflowId' => $this->workflowId,
                'flowchartId' => $this->flowchartId,
            ]);

            return;
        }

        $item->set('lastUserId', $userId);

        $this->entityManager->saveEntity($item);
    }
}
