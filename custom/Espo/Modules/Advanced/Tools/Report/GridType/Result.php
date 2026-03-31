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

use stdClass;

class Result
{
    private bool $isJoint = false;
    /** @var string[] */
    private array $subListColumnList;
    private stdClass $nonSummaryColumnGroupMap;
    private stdClass $subListData;
    private stdClass $sums;
    /** @var array<string, array<string, mixed>> */
    private array $groupValueMap;
    private stdClass $cellValueMaps;
    private stdClass $reportData;
    private stdClass $nonSummaryData;
    /** @var ?string */
    private ?string $success = null;
    private stdClass $chartColors;
    /** @var ?string */
    private ?string $chartColor = null;
    private stdClass $columnDecimalPlacesMap;
    /** @var ?string[] */
    private ?array $group1NonSummaryColumnList = null;
    /** @var ?string[] */
    private ?array $group2NonSummaryColumnList = null;
    private ?stdClass $group1Sums = null;
    private ?stdClass $group2Sums = null;

    /** @var array<string, string> */
    private array $columnEntityTypeMap = [];
    /** @var array<string, string> */
    private array $columnOriginalMap = [];

    /** @var ?string[] */
    private ?array $entityTypeList = null;
    /** @var array<string, string> */
    private array $columnReportIdMap = [];
    /** @var array<string, string> */
    private array $columnSubReportLabelMap = [];

    /**
     * @param ?string $entityType
     * @param string[] $groupByList
     * @param string[] $columnList
     * @param string[] $numericColumnList
     * @param string[] $summaryColumnList
     * @param string[] $nonSummaryColumnList
     * @param string[] $subListColumnList
     * @param string[] $aggregatedColumnList
     * @param ?stdClass $nonSummaryColumnGroupMap
     * @param ?stdClass $subListData
     * @param ?stdClass $sums
     * @param ?array<string, array<string, mixed>> $groupValueMap
     * @param ?string[] $columnNameMap
     * @param ?string[] $columnTypeMap
     * @param ?stdClass $cellValueMaps
     * @param array{0?: string[], 1?: string[]} $grouping
     * @param ?stdClass $reportData
     * @param ?stdClass $nonSummaryData
     * @param ?string $chartType
     * @param ?stdClass[] $chartDataList
     * @param ?stdClass $columnDecimalPlacesMap
     */
    public function __construct(
        private ?string $entityType,
        private array $groupByList,
        private array $columnList,
        private array $numericColumnList = [],
        private array $summaryColumnList = [],
        private array $nonSummaryColumnList = [],
        ?array $subListColumnList = null,
        private array $aggregatedColumnList = [],
        ?stdClass $nonSummaryColumnGroupMap = null,
        ?stdClass $subListData = null,
        ?stdClass $sums = null,
        ?array $groupValueMap = null,
        private ?array $columnNameMap = null,
        private ?array $columnTypeMap = null,
        ?stdClass $cellValueMaps = null,
        private array $grouping = [],
        ?stdClass $reportData = null,
        ?stdClass $nonSummaryData = null,
        private ?string $chartType = null,
        private ?array $chartDataList = null,
        ?stdClass $columnDecimalPlacesMap = null,
        private bool $emptyStringGroupExcluded = false
    ) {
        $this->subListColumnList = $subListColumnList ?? [];
        $this->nonSummaryColumnGroupMap = $nonSummaryColumnGroupMap ?? (object) [];
        $this->subListData = $subListData ?? (object) [];
        $this->sums = $sums ?? (object) [];
        $this->groupValueMap = $groupValueMap ?? [];
        $this->cellValueMaps = $cellValueMaps ?? (object) [];
        $this->reportData = $reportData ?? (object) [];
        $this->nonSummaryData = $nonSummaryData ?? (object) [];
        $this->columnDecimalPlacesMap = $columnDecimalPlacesMap ?? (object) [];
        $this->chartColors = (object) [];
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    /**
     * @return string[]
     */
    public function getGroupByList(): array
    {
        return $this->groupByList;
    }

    /**
     * @return string[]
     */
    public function getColumnList(): array
    {
        return $this->columnList;
    }

    /**
     * @return string[]
     */
    public function getNumericColumnList(): array
    {
        return $this->numericColumnList;
    }

    /**
     * @return string[]
     */
    public function getSummaryColumnList(): array
    {
        return $this->summaryColumnList;
    }

    /**
     * @return string[]
     */
    public function getNonSummaryColumnList(): array
    {
        return $this->nonSummaryColumnList;
    }

    /**
     * @return string[]
     */
    public function getSubListColumnList(): array
    {
        return $this->subListColumnList;
    }

    /**
     * @return string[]
     */
    public function getAggregatedColumnList(): array
    {
        return $this->aggregatedColumnList;
    }

    public function getNonSummaryColumnGroupMap(): stdClass
    {
        return $this->nonSummaryColumnGroupMap;
    }

    public function getSubListData(): stdClass
    {
        return $this->subListData;
    }

    public function getSums(): stdClass
    {
        return $this->sums;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getGroupValueMap(): array
    {
        return $this->groupValueMap;
    }

    /**
     * @return array<string, string>|null
     */
    public function getColumnNameMap(): ?array
    {
        return $this->columnNameMap;
    }

    /**
     * @return string[]|null
     */
    public function getColumnTypeMap(): ?array
    {
        return $this->columnTypeMap;
    }

    public function getCellValueMaps(): stdClass
    {
        return $this->cellValueMaps;
    }

    /**
     * @return array{0?: string[], 1?: string[]}
     */
    public function getGrouping(): array
    {
        return $this->grouping;
    }

    public function getReportData(): stdClass
    {
        return $this->reportData;
    }

    public function getNonSummaryData(): stdClass
    {
        return $this->nonSummaryData;
    }

    public function getChartType(): ?string
    {
        return $this->chartType;
    }

    public function getSuccess(): ?string
    {
        return $this->success;
    }

    public function getChartColors(): stdClass
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

    public function getColumnDecimalPlacesMap(): stdClass
    {
        return $this->columnDecimalPlacesMap;
    }

    /**
     * @return ?string[]
     */
    public function getGroup1NonSummaryColumnList(): ?array
    {
        return $this->group1NonSummaryColumnList;
    }

    /**
     * @return ?string[]
     */
    public function getGroup2NonSummaryColumnList(): ?array
    {
        return $this->group2NonSummaryColumnList;
    }

    public function getGroup1Sums(): ?stdClass
    {
        return $this->group1Sums;
    }

    public function getGroup2Sums(): ?stdClass
    {
        return $this->group2Sums;
    }

    public function isJoint(): bool
    {
        return $this->isJoint;
    }

    public function setEntityType(?string $entityType): Result
    {
        $this->entityType = $entityType;
        return $this;
    }

    /**
     * @param string[] $groupByList
     */
    public function setGroupByList(array $groupByList): Result
    {
        $this->groupByList = $groupByList;
        return $this;
    }

    /**
     * @param string[] $columnList
     */
    public function setColumnList(array $columnList): Result
    {
        $this->columnList = $columnList;
        return $this;
    }

    /**
     * @param string[] $numericColumnList
     */
    public function setNumericColumnList(array $numericColumnList): Result
    {
        $this->numericColumnList = $numericColumnList;
        return $this;
    }

    /**
     * @param string[] $summaryColumnList
     */
    public function setSummaryColumnList(array $summaryColumnList): Result
    {
        $this->summaryColumnList = $summaryColumnList;
        return $this;
    }

    /**
     * @param string[] $nonSummaryColumnList
     */
    public function setNonSummaryColumnList(array $nonSummaryColumnList): Result
    {
        $this->nonSummaryColumnList = $nonSummaryColumnList;
        return $this;
    }

    /**
     * @param string[] $subListColumnList
     */
    public function setSubListColumnList(array $subListColumnList): Result
    {
        $this->subListColumnList = $subListColumnList;
        return $this;
    }

    /**
     * @param string[] $aggregatedColumnList
     */
    public function setAggregatedColumnList(array $aggregatedColumnList): Result
    {
        $this->aggregatedColumnList = $aggregatedColumnList;

        return $this;
    }

    public function setNonSummaryColumnGroupMap(stdClass $nonSummaryColumnGroupMap): Result
    {
        $this->nonSummaryColumnGroupMap = $nonSummaryColumnGroupMap;

        return $this;
    }

    public function setSubListData(stdClass $subListData): Result
    {
        $this->subListData = $subListData;

        return $this;
    }

    public function setSums(stdClass $sums): Result
    {
        $this->sums = $sums;

        return $this;
    }

    /**
     * @param ?array<string, array<string, mixed>> $groupValueMap
     * @return Result
     */
    public function setGroupValueMap(?array $groupValueMap): Result
    {
        $this->groupValueMap = $groupValueMap;

        return $this;
    }

    /**
     * @param ?string[] $columnNameMap
     */
    public function setColumnNameMap(?array $columnNameMap): Result
    {
        $this->columnNameMap = $columnNameMap;

        return $this;
    }

    /**
     * @param ?string[] $columnTypeMap
     */
    public function setColumnTypeMap(?array $columnTypeMap): Result
    {
        $this->columnTypeMap = $columnTypeMap;

        return $this;
    }

    public function setCellValueMaps(?stdClass $cellValueMaps): Result
    {
        $this->cellValueMaps = $cellValueMaps;

        return $this;
    }

    /**
     * @param array{0?: string[], 1?: string[]} $grouping
     */
    public function setGrouping(array $grouping): Result
    {
        $this->grouping = $grouping;

        return $this;
    }

    public function setReportData(stdClass $reportData): Result
    {
        $this->reportData = $reportData;

        return $this;
    }

    public function setNonSummaryData(stdClass $nonSummaryData): Result
    {
        $this->nonSummaryData = $nonSummaryData;

        return $this;
    }

    public function setChartType(?string $chartType): Result
    {
        $this->chartType = $chartType;

        return $this;
    }

    public function setSuccess(?string $success): Result
    {
        $this->success = $success;
        return $this;
    }

    public function setChartColors(?stdClass $chartColors): Result
    {
        $this->chartColors = $chartColors ?? (object) [];
        return $this;
    }

    public function setChartColor(?string $chartColor): Result
    {
        $this->chartColor = $chartColor;

        return $this;
    }

    /**
     * @param ?stdClass[] $chartDataList
     */
    public function setChartDataList(?array $chartDataList): Result
    {
        $this->chartDataList = $chartDataList;
        return $this;
    }

    public function setColumnDecimalPlacesMap(stdClass $columnDecimalPlacesMap): Result
    {
        $this->columnDecimalPlacesMap = $columnDecimalPlacesMap;
        return $this;
    }

    /**
     * @param ?string[] $group1NonSummaryColumnList
     * @return Result
     */
    public function setGroup1NonSummaryColumnList(?array $group1NonSummaryColumnList): Result
    {
        $this->group1NonSummaryColumnList = $group1NonSummaryColumnList;
        return $this;
    }

    /**
     * @param ?string[] $group2NonSummaryColumnList
     */
    public function setGroup2NonSummaryColumnList(?array $group2NonSummaryColumnList): Result
    {
        $this->group2NonSummaryColumnList = $group2NonSummaryColumnList;
        return $this;
    }

    public function setGroup1Sums(?stdClass $group1Sums): Result
    {
        $this->group1Sums = $group1Sums;

        foreach (get_object_vars($this->group1Sums) as $k => $v) {
            if (is_array($v)) {
                $this->group1Sums->$k = (object) $v;
            }
        }

        return $this;
    }

    public function setGroup2Sums(?stdClass $group2Sums): Result
    {
        $this->group2Sums = $group2Sums;

        foreach (get_object_vars($this->group2Sums) as $k => $v) {
            if (is_array($v)) {
                $this->group2Sums->$k = (object) $v;
            }
        }

        return $this;
    }

    public function setIsJoint(bool $isJoint): void
    {
        $this->isJoint = $isJoint;
    }

    /**
     * @return array<string, string>
     */
    public function getColumnEntityTypeMap(): array
    {
        return $this->columnEntityTypeMap;
    }

    /**
     * @param array<string, string> $columnEntityTypeMap
     * @return Result
     */
    public function setColumnEntityTypeMap(array $columnEntityTypeMap): Result
    {
        $this->columnEntityTypeMap = $columnEntityTypeMap;
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getColumnOriginalMap(): array
    {
        return $this->columnOriginalMap;
    }

    /**
     * @param array<string, string> $columnOriginalMap
     * @return Result
     */
    public function setColumnOriginalMap(array $columnOriginalMap): Result
    {
        $this->columnOriginalMap = $columnOriginalMap;
        return $this;
    }

    /**
     * @return ?string[]
     */
    public function getEntityTypeList(): ?array
    {
        return $this->entityTypeList;
    }

    /**
     * @param ?string[] $entityTypeList
     * @return Result
     */
    public function setEntityTypeList(?array $entityTypeList): Result
    {
        $this->entityTypeList = $entityTypeList;
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getColumnReportIdMap(): array
    {
        return $this->columnReportIdMap;
    }

    /**
     * @param array<string, string> $columnReportIdMap
     * @return Result
     */
    public function setColumnReportIdMap(array $columnReportIdMap): Result
    {
        $this->columnReportIdMap = $columnReportIdMap;
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getColumnSubReportLabelMap(): array
    {
        return $this->columnSubReportLabelMap;
    }

    /**
     * @param array<string, string> $columnSubReportLabelMap
     */
    public function setColumnSubReportLabelMap(array $columnSubReportLabelMap): Result
    {
        $this->columnSubReportLabelMap = $columnSubReportLabelMap;
        return $this;
    }

    /** @noinspection PhpUnused */
    public function isEmptyStringGroupExcluded(): bool
    {
        return $this->emptyStringGroupExcluded;
    }

    public function toRaw(): stdClass
    {
        return (object) [
            'type' => 'Grid',
            'entityType' => $this->entityType, // string
            'depth' => count($this->groupByList), // int
            'columnList' => $this->columnList, // string[]
            'groupByList' => $this->groupByList, // string[]
            'numericColumnList' => $this->numericColumnList,
            'summaryColumnList' => $this->summaryColumnList,
            'nonSummaryColumnList' => $this->nonSummaryColumnList,
            'subListColumnList' => $this->subListColumnList, // string[]
            'aggregatedColumnList' => $this->aggregatedColumnList, // string[]
            'nonSummaryColumnGroupMap' => $this->nonSummaryColumnGroupMap, // stdClass
            'subListData' => $this->subListData, // object<stdClass[]>
            'sums' => $this->sums, // object<int|float>
            'groupValueMap' => $this->groupValueMap, // array<string, array<string, mixed>>
            'columnNameMap' => $this->columnNameMap, // array<string, string>
            'columnTypeMap' => $this->columnTypeMap, // array<string, string>
            'cellValueMaps' => $this->cellValueMaps, // object<object> (when grouping by link)
            'grouping' => $this->grouping, // array{string[]}|array{string[], string[]}
            'reportData' => $this->reportData, // object<object>|object<object<object>>
            // group => (group-value => value-map, only for grid-2
            'nonSummaryData' => $this->nonSummaryData, // object<object<object>>
            'success' => $this->success,
            'chartColors' => $this->chartColors, // stdClass
            'chartColor' => $this->chartColor, // ?string
            'chartType' => $this->chartType, // ?string
            'chartDataList' => $this->chartDataList, // stdClass[]
            'columnDecimalPlacesMap' => $this->columnDecimalPlacesMap, // object<?int>
            'group1NonSummaryColumnList' => $this->group1NonSummaryColumnList,
            'group2NonSummaryColumnList' => $this->group2NonSummaryColumnList,
            'group1Sums' => $this->group1Sums,
            'group2Sums' => $this->group2Sums,
            'isJoint' => $this->isJoint,
            'entityTypeList' => $this->entityTypeList,
            'columnEntityTypeMap' => (object) $this->columnEntityTypeMap,
            'columnOriginalMap' => (object) $this->columnOriginalMap,
            'columnReportIdMap' => (object) $this->columnReportIdMap,
            'columnSubReportLabelMap' => (object) $this->columnSubReportLabelMap,
            'emptyStringGroupExcluded' => $this->emptyStringGroupExcluded,
        ];
    }
}
