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

namespace Espo\Modules\Advanced\Entities;

use Espo\Core\Field\LinkMultiple;
use Espo\Core\ORM\Entity;
use stdClass;

class Report extends Entity
{
    public const ENTITY_TYPE = 'Report';

    public const TYPE_LIST = 'List';
    public const TYPE_GRID = 'Grid';
    public const TYPE_JOINT_GRID = 'JointGrid';

    public function getName(): ?string
    {
        return $this->get('name');
    }

    public function getType(): string
    {
        return $this->get('type');
    }

    /**
     * @return string[]
     */
    public function getRuntimeFilters(): array
    {
        return $this->get('runtimeFilters') ?? [];
    }

    public function isInternal(): bool
    {
        return $this->get('isInternal');
    }

    public function getTargetEntityType(): ?string
    {
        return $this->get('entityType');
    }

    public function getPortals(): LinkMultiple
    {
        /** @var LinkMultiple */
        return $this->getValueObject('portals');
    }

    /**
     * @return bool
     */
    protected function _hasJoinedReportsIds()
    {
        return $this->has('joinedReportDataList');
    }

    /**
     * @return bool
     */
    protected function _hasJoinedReportsNames()
    {
        return $this->has('joinedReportDataList');
    }

    /**
     * @return bool
     */
    protected function _hasJoinedReportsColumns()
    {
        return $this->has('joinedReportDataList');
    }

    /**
     * @return string[][]
     */
    protected function _getJoinedReportsIds()
    {
        $idList = [];
        $dataList = $this->get('joinedReportDataList');

        if (!is_array($dataList)) {
            return [];
        }

        foreach ($dataList as $item) {
            if (empty($item->id)) {
                continue;
            }

            $idList[] = $item->id;
        }

        return $idList;
    }

    /**
     * @return object
     */
    protected function _getJoinedReportsNames()
    {
        $nameMap = (object) [];
        $dataList = $this->get('joinedReportDataList');

        if (!is_array($dataList)) {
            return $nameMap;
        }

        foreach ($dataList as $item) {
            if (empty($item->id)) {
                continue;
            }
            $report = $this->entityManager->getEntity('Report', $item->id);

            if (!$report) {
                continue;
            }

            $nameMap->{$item->id} = $report->get('name');
        }

        return $nameMap;
    }

    /**
     * @return object
     */
    protected function _getJoinedReportsColumns()
    {
        $map = (object) [];
        $dataList = $this->get('joinedReportDataList');

        if (!is_array($dataList)) {
            return $map;
        }

        foreach ($dataList as $item) {
            if (empty($item->id)) {
                continue;
            }

            if (!isset($item->label)) {
                continue;
            }

            $map->{$item->id} = (object) [
                'label' => $item->label
            ];
        }

        return $map;
    }

    public function getOrderByList(): ?string
    {
        return $this->get('orderByList');
    }

    /**
     * @return string[]
     */
    public function getColumns(): array
    {
        return $this->get('columns') ?? [];
    }

    /**
     * @return string[]
     */
    public function getGroupBy(): array
    {
        return $this->get('groupBy') ?? [];
    }

    /**
     * @return string[]
     */
    public function getOrderBy(): array
    {
        return $this->get('orderBy') ?? [];
    }

    public function getColumnsData(): stdClass
    {
        return $this->get('columnsData') ?? (object) [];
    }

    public function getApplyAcl(): bool
    {
        return (bool) $this->get('applyAcl');
    }

    public function setName(string $name): self
    {
        $this->set('name', $name);

        return $this;
    }

    public function setApplyAcl(bool $applyAcl = true): self
    {
        $this->set('applyAcl', $applyAcl);

        return $this;
    }

    /**
     * @return string[]
     */
    public function getJoinedReportIdList(): array
    {
        return $this->get('joinedReportsIds') ?? [];
    }
}
