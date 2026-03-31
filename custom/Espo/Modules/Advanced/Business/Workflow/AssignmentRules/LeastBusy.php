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

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Tools\Report\ReportHelper;
use Espo\Modules\Advanced\Tools\Report\Service;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\SelectBuilder;

use PDO;

class LeastBusy
{
    /** @var EntityManager */
    private $entityManager;
    /** @var string */
    private $entityType;
    /** @var Service */
    private $reportService;
    /** @var ReportHelper */
    private $reportHelper;

    public function __construct(
        EntityManager $entityManager,
        Service $reportService,
        string $entityType,
        ReportHelper $reportHelper
    ) {
        $this->entityManager = $entityManager;
        $this->reportService = $reportService;
        $this->entityType = $entityType;
        $this->reportHelper = $reportHelper;
    }

    /**
     * @param array<int|string, mixed>|WhereItem|null $whereClause
     * @return array<string, mixed>
     * @throws Forbidden
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
            throw new Error("LeastBusy: No team $targetTeamId.");
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
            ->order('userName')
            ->where($where)
            ->find();

        if (is_countable($userList) && count($userList) === 0) {
            throw new Error("LeastBusy: No users in team $targetTeamId.");
        }

        $userIdList = [];

        foreach ($userList as $user) {
            $userIdList[] = $user->getId();
        }

        $counts = [];

        foreach ($userIdList as $id) {
            $counts[$id] = 0;
        }

        $selectBuilder = SelectBuilder::create()->from($this->entityType);

        if ($listReportId) {
            /** @var ?Report $report */
            $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $listReportId);

            if (!$report) {
                throw new Error("No report $listReportId.");
            }

            $this->reportHelper->checkReportCanBeRun($report);

            $selectBuilder = $this->reportService->prepareSelectBuilder($report);
        }

        $selectBuilder
            ->where([
                'assignedUserId' => $userIdList,
                'id!=' => $entity->hasId() ? $entity->getId() : null,
            ])
            ->group('assignedUserId')
            ->select([
                'assignedUserId',
                ['COUNT:(id)', 'COUNT:id'],
            ])
            ->order([])
            ->order('assignedUserId')
            ->leftJoin('assignedUser', 'assignedUserAssignedRule')
            ->where(['assignedUserAssignedRule.isActive' => true]);

        if ($whereClause) {
            $selectBuilder->where($whereClause);
        }

        $sth = $this->entityManager
            ->getQueryExecutor()
            ->execute($selectBuilder->build());

        $rowList = $sth->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rowList as $row) {
            $id = $row['assignedUserId'];

            if (!$id) {
                continue;
            }

            $counts[$id] = $row['COUNT:id'];
        }

        $countValues = array_values($counts);

        if (!count($countValues)) {
            return [];
        }

        $minCount = min($countValues);
        $minCountIdList = [];

        foreach ($counts as $id => $count) {
            if ($count === $minCount) {
                $minCountIdList[] = $id;
            }
        }

        if (!count($minCountIdList)) {
            return [];
        }

        $attributes = [];

        $attributes['assignedUserId'] = $minCountIdList[array_rand($minCountIdList)];

        /** @var ?User $user */
        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $attributes['assignedUserId']);

        if ($user) {
            $attributes['assignedUserName'] = $user->getName();
        }

        return $attributes;
    }
}
