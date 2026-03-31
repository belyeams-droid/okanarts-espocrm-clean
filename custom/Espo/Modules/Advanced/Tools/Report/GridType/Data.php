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

namespace Espo\Modules\Advanced\Tools\Report\GridType;

use Espo\Core\Select\Where\Item as WhereItem;
use stdClass;

class Data
{
    public const COLUMN_TYPE_SUMMARY = 'Summary';

    private string $entityType;
    private ?string $success;
    /** @var string[] */
    private array $columns;
    /** @var string[] */
    private array $groupBy;
    /** @var string[] */
    private array $orderBy;
    private bool $applyAcl;
    private ?WhereItem $filtersWhere;
    private ?string $chartType;
    /** @var ?array<string, string> */
    private ?array $chartColors;
    private ?string $chartColor;
    /** @var ?stdClass[] */
    private ?array $chartDataList;
    /** @var string[] */
    private array $aggregatedColumns = [];
    private stdClass $columnsData;

    /**
     * @param string[] $columns
     * @param string[] $groupBy
     * @param string[] $orderBy
     * @param ?string[] $chartColors
     * @param ?stdClass[] $chartDataList
     */
    public function __construct(
        string $entityType,
        array $columns,
        array $groupBy,
        array $orderBy,
        bool $applyAcl,
        ?WhereItem $filtersWhere,
        ?string $chartType,
        ?array $chartColors,
        ?string $chartColor,
        ?array $chartDataList,
        ?string $success,
        ?stdClass $columnsData
    ) {
        $this->entityType = $entityType;
        $this->columns = $columns;
        $this->groupBy = $groupBy;
        $this->orderBy = $orderBy;
        $this->applyAcl = $applyAcl;
        $this->filtersWhere = $filtersWhere;
        $this->chartType = $chartType;
        $this->chartColors = $chartColors;
        $this->chartColor = $chartColor;
        $this->chartDataList = $chartDataList;
        $this->success = $success;
        $this->columnsData = $columnsData;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getSuccess(): ?string
    {
        return $this->success;
    }

    /**
     * @return string[]
     */
    public function getOrderBy(): array
    {
        return $this->orderBy;
    }

    /**
     * @return string[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return string[]
     */
    public function getGroupBy(): array
    {
        return $this->groupBy;
    }

    public function applyAcl(): bool
    {
        return $this->applyAcl;
    }

    public function getFiltersWhere(): ?WhereItem
    {
        return $this->filtersWhere;
    }

    public function getChartType(): ?string
    {
        return $this->chartType;
    }

    /**
     * @return ?string[]
     */
    public function getChartColors(): ?array
    {
        return $this->chartColors;
    }

    public function getChartColor(): ?string
    {
        return $this->chartColor;
    }

    /**
     * @return ?stdClass[]
     */
    public function getChartDataList(): ?array
    {
        return $this->chartDataList;
    }

    /**
     * @return string[]
     */
    public function getAggregatedColumns(): array
    {
        return $this->aggregatedColumns;
    }

    public function getColumnLabel(string $column): ?string
    {
        if (!isset($this->columnsData->$column)) {
            return null;
        }

        $item = $this->columnsData->$column;

        if (!is_object($item)) {
            return null;
        }

        return $item->label ?? null;
    }

    public function getColumnType(string $column): ?string
    {
        if (!isset($this->columnsData->$column)) {
            return null;
        }

        $item = $this->columnsData->$column;

        if (!is_object($item)) {
            return null;
        }

        return $item->type ?? null;
    }

    public function getColumnDecimalPlaces(string $column): ?int
    {
        if (!isset($this->columnsData->$column)) {
            return null;
        }

        $item = $this->columnsData->$column;

        if (!is_object($item)) {
            return null;
        }

        return $item->decimalPlaces ?? null;
    }

    /**
     * @param string[] $aggregatedColumns
     */
    public function withAggregatedColumns(array $aggregatedColumns): self
    {
        $obj = clone $this;
        $obj->aggregatedColumns = $aggregatedColumns;

        return $obj;
    }
}
