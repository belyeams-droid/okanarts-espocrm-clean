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

namespace Espo\Modules\Advanced\Classes\Select\Common\PrimaryFilters;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\Primary\Filter;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Entities\ReportFilter as ReportFilterEntity;
use Espo\Modules\Advanced\Tools\Report\Service;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;
use RuntimeException;

class ReportFilter implements Filter
{
    public function __construct(
        private string $name,
        private string $entityType,
        private EntityManager $entityManager,
        private Metadata $metadata,
        private User $user,
        private Service $service
    ) {}

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     */
    public function apply(QueryBuilder $queryBuilder): void
    {
        /** @var ?string $reportFilterId */
        $reportFilterId = $this->metadata
            ->get(['entityDefs', $this->entityType, 'collection', 'filters', $this->name, 'id']);

        if (!$reportFilterId) {
            throw new RuntimeException("Report Filter $reportFilterId error.");
        }

        /** @var ?ReportFilterEntity $reportFilter */
        $reportFilter = $this->entityManager->getEntityById(ReportFilterEntity::ENTITY_TYPE, $reportFilterId);

        if (!$reportFilter) {
            throw new RuntimeException("Report Filter $reportFilterId not found.");
        }

        $teamIdList = $reportFilter->getLinkMultipleIdList('teams');

        if (count($teamIdList) && !$this->user->isAdmin()) {
            $isInTeam = false;
            $userTeamIdList = $this->user->getLinkMultipleIdList('teams');

            foreach ($userTeamIdList as $teamId) {
                if (in_array($teamId, $teamIdList)) {
                    $isInTeam = true;
                    break;
                }
            }

            if (!$isInTeam) {
                throw new Forbidden("Access denied to Report Filter $reportFilterId.");
            }
        }

        $reportId = $reportFilter->get('reportId');

        if (!$reportId) {
            throw new RuntimeException('Report Filter error. No report.');
        }

        /** @var ?Report $report */
        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $reportId);

        if (!$report) {
            throw new Error('Report Filter error. Report not found.');
        }

        $query = $this->service
            ->prepareSelectBuilder($report)
            ->build();

        foreach ($query->getLeftJoins() as $join) {
            $queryBuilder->leftJoin($join);
        }

        foreach ($query->getJoins() as $join) {
            $queryBuilder->join($join);
        }

        if ($query->getWhere()) {
            $queryBuilder->where($query->getWhere());
        }

        if ($query->isDistinct()) {
            $queryBuilder->distinct();
        }
    }
}
