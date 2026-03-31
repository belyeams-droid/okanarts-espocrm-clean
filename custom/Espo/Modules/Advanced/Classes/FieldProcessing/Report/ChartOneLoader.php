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

namespace Espo\Modules\Advanced\Classes\FieldProcessing\Report;

use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Modules\Advanced\Entities\Report ;
use Espo\ORM\Entity;

/**
 * @implements Loader<Report>
 */
class ChartOneLoader implements Loader
{
    public function process(Entity $entity, Params $params): void
    {

        $entity->set('chartOneColumns', []);
        $entity->set('chartOneY2Columns', []);

        if ($entity->getType() === Report::TYPE_GRID) {
            $chartDataList = $entity->get('chartDataList');

            if ($chartDataList && count($chartDataList)) {
                $y = $chartDataList[0]->columnList ?? null;
                $y2 = $chartDataList[0]->y2ColumnList ?? null;

                $entity->set('chartOneColumns', $y);
                $entity->set('chartOneY2Columns', $y2);
            }
        }
    }
}
