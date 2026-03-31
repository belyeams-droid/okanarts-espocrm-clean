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

namespace Espo\Modules\Advanced\Hooks\Report;

use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Entities\Report as ReportEntity;
use Espo\ORM\Entity;

class Prepare
{
    /**
     * @param Report $entity
     */
    public function beforeSave(Entity $entity): void
    {
        if (
            $entity->isAttributeChanged('emailSendingInterval') ||
            $entity->isAttributeChanged('emailSendingTime') ||
            $entity->isAttributeChanged('emailSendingSettingWeekdays') ||
            $entity->isAttributeChanged('emailSendingSettingDay')
        ) {
            /** @noinspection PhpRedundantOptionalArgumentInspection */
            $entity->set('emailSendingLastDateSent', null);
        }

        if (
            $entity->get('type') === ReportEntity::TYPE_GRID &&
            ($entity->has('chartOneColumns') || $entity->has('chartOneY2Columns'))
        ) {
            $this->handleChartDataList($entity);
        }
    }

    private function handleChartDataList(Report $entity): void
    {
        $groupBy = $entity->getGroupBy();

        if (count($groupBy) > 1) {
            /** @noinspection PhpRedundantOptionalArgumentInspection */
            $entity->set('chartDataList', null);

            return;
        }

        $chartDataList = $entity->get('chartDataList');

        $y = null;
        $y2 = null;

        if ($chartDataList && count($chartDataList) !== 0) {
            $y = $chartDataList[0]->columnList ?? null;
            $y2 = $chartDataList[0]->y2ColumnList ?? null;
        }

        $newY = $y ?? null;
        $newY2 = $y2 ?? null;

        if ($entity->has('chartOneColumns')) {
            $newY = $entity->get('chartOneColumns') ?? [];

            if ($newY && count($newY) === 0) {
                $newY = null;
            }
        }

        if ($entity->has('chartOneY2Columns')) {
            $newY2 = $entity->get('chartOneY2Columns') ?? [];

            if ($newY2 && count($newY2) === 0) {
                $newY2 = null;
            }
        }

        $chartType = $entity->get('chartType');

        if (!in_array($chartType, ['BarVertical', 'BarHorizontal', 'Line'])) {
            $newY2 = null;
        }

        if ($newY || $newY2) {
            $newItem = (object) [
                'columnList' => $newY,
                'y2ColumnList' => $newY2,
            ];

            $entity->set('chartDataList', [$newItem]);

            return;
        }

        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $entity->set('chartDataList', null);
    }
}
