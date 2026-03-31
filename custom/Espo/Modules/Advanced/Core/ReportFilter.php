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

namespace Espo\Modules\Advanced\Core;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Entities\ReportFilter as ReportFilterEntity;
use Espo\Modules\Advanced\Tools\Report\Service;
use Espo\ORM\EntityManager;

/**
 * @deprecated As of v7.5 PrimaryFilter is used.
 */
class ReportFilter
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private User $user,
        private Service $service
    ) {}

    /**
     * @throws Forbidden
     * @throws Error
     * @phpstan-ignore-next-line
     */
    public function applyFilter(string $entityType, string $filterName, &$result, $selectManger)
    {
        $reportFilterId = $this->metadata
            ->get(['entityDefs', $entityType, 'collection', 'filters', $filterName, 'id']);

        if (!$reportFilterId) {
            throw new Error('Report Filter error.');
        }

        $reportFilter = $this->entityManager
            ->getRDBRepositoryByClass(ReportFilterEntity::class)
            ->getById($reportFilterId);

        if (!$reportFilter) {
            throw new Error('Report Filter not found.');
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
                throw new Forbidden("Access denied to Report Filter.");
            }
        }

        $reportId = $reportFilter->get('reportId');

        if (!$reportId) {
            throw new Error('Report Filter error.');
        }

        /** @var ?Report $report */
        $report = $this->entityManager->getEntity(Report::ENTITY_TYPE, $reportId);

        if (!$report) {
            throw new Error('Report Filter error. Report not found.');
        }

        $selectParams = $this->service
            ->prepareSelectBuilder($report)
            ->build()
            ->getRaw();

        $result['whereClause'][] = $selectParams['whereClause'];

        foreach ($selectParams['joins'] ?? [] as $join) {
            $selectManger->addJoin($join, $result);
        }

        foreach ($selectParams['leftJoins'] ?? [] as $join) {
            $selectManger->addLeftJoin($join, $result);
        }

        if (!empty($selectParams['distinct'])) {
            $selectManger->setDistinct(true, $result);
        }
    }
}
