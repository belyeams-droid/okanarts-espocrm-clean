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

namespace Espo\Modules\Advanced\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\Modules\Advanced\Tools\ReportPanel\Service;

use stdClass;

class ReportPanel extends Record
{
    /**
     * @throws Error
     */
    public function postActionRebuild(): bool
    {
        $this->getPanelService()->rebuild();

        return true;
    }

    /**
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function getActionRunList(Request $request): stdClass
    {
        $id = $request->getQueryParam('id');

        $parentType = $request->getQueryParam('parentType');
        $parentId = $request->getQueryParam('parentId');

        if (!$id) {
            throw new BadRequest();
        }

        $searchParams = $this->injectableFactory
            ->create(SearchParamsFetcher::class)
            ->fetch($request);

        $subReportParams = null;

        if ($request->hasQueryParam('groupValue')) {
            $groupValue = $request->getQueryParam('groupValue');

            if ($groupValue === '') {
                $groupValue = null;
            }

            $groupValue2 = null;

            if ($request->hasQueryParam('groupValue2')) {
                $groupValue2 = $request->getQueryParam('groupValue2');

                if ($groupValue2 === '') {
                    $groupValue2 = null;
                }
            }

            $subReportParams = new SubReportParams(
                (int) ($request->getQueryParam('groupIndex') ?? 0),
                $groupValue,
                $request->hasQueryParam('groupValue2'),
                $groupValue2
            );
        }

        $subReportId = $request->getQueryParam('subReportId');

        $result = $subReportParams ?
            $this->getPanelService()->runSubReportList(
                $id,
                $parentType,
                $parentId,
                $searchParams,
                $subReportParams,
                $subReportId
            ) :
            $this->getPanelService()->runList($id, $parentType, $parentId, $searchParams);

        return (object) [
            'list' => $result->getCollection()->getValueMapList(),
            'total' => $result->getTotal(),
            'columns' => $result->getColumns(),
            'columnsData' => $result->getColumnsData(),
        ];
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function getActionRunGrid(Request $request): stdClass
    {
        $id = $request->getQueryParam('id');
        $parentType = $request->getQueryParam('parentType');
        $parentId = $request->getQueryParam('parentId');

        if (!$id) {
            throw new BadRequest();
        }

        return $this->getPanelService()
            ->runGrid($id, $parentType, $parentId)
            ->toRaw();
    }

    private function getPanelService(): Service
    {
        return $this->injectableFactory->create(Service::class);
    }
}
