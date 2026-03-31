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

namespace Espo\Modules\Advanced\Classes\FieldProcessing\ReportPanel;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Modules\Advanced\Entities\Report as ReportEntity;
use Espo\Modules\Advanced\Entities\ReportPanel;
use Espo\Modules\Advanced\Tools\Report\GridType\Helper;
use Espo\Modules\Advanced\Tools\Report\ReportHelper;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * @implements Loader<ReportPanel>
 */
class Additional implements Loader
{
    public function __construct(
        private Helper $helper,
        private EntityManager $entityManager,
        private ReportHelper $reportHelper
    ) {}

    /**
     * @throws Forbidden
     * @throws Error
     */
    public function process(Entity $entity, Params $params): void
    {
        if (
            $entity->get('reportType') === ReportEntity::TYPE_GRID &&
            $entity->get('reportId')
        ) {
            /** @var ?ReportEntity $report */
            $report = $this->entityManager->getEntityById(ReportEntity::ENTITY_TYPE, $entity->get('reportId'));

            if ($report) {
                $columnList = $report->getColumns();

                $numericColumnList = [];

                $gridData = $this->reportHelper->fetchGridDataFromReport($report);

                foreach ($columnList as $column) {
                    if ($this->helper->isColumnNumeric($column, $gridData)) {
                        $numericColumnList[] = $column;
                    }
                }

                if (
                    (
                        count($report->getGroupBy()) === 1 ||
                        count($report->getGroupBy()) === 0
                    ) &&
                    count($numericColumnList) > 1
                ) {
                    array_unshift($numericColumnList, '');
                }

                $entity->set('columnList', $numericColumnList);
            }

            $entity->set('columnsData', $report->getColumnsData());
        }

        $displayType = $entity->get('displayType');
        $reportType = $entity->get('reportType');
        $displayTotal = $entity->get('displayTotal');
        $displayOnlyTotal = $entity->get('displayOnlyTotal');

        if (!$displayType) {
            if (
                $reportType === ReportEntity::TYPE_GRID ||
                $reportType === ReportEntity::TYPE_JOINT_GRID
            ) {
                if ($displayOnlyTotal) {
                    $displayType = 'Total';
                }
                else if ($displayTotal) {
                    $displayType = 'Chart-Total';
                }
                else {
                    $displayType = 'Chart';
                }
            }
            else if ($reportType === ReportEntity::TYPE_LIST) {
                if ($displayOnlyTotal) {
                    $displayType = 'Total';
                }
                else {
                    $displayType = 'List';
                }
            }

            $entity->set('displayType', $displayType);
        }
    }
}
