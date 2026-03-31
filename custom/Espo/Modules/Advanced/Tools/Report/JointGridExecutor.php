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

namespace Espo\Modules\Advanced\Tools\Report;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Language;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Reports\GridReport;
use Espo\Modules\Advanced\Tools\Report\GridType\Helper as GridHelper;
use Espo\Modules\Advanced\Tools\Report\GridType\JointData;
use Espo\Modules\Advanced\Tools\Report\GridType\Result as GridResult;
use Espo\Modules\Advanced\Tools\Report\GridType\ResultHelper;
use Espo\Modules\Advanced\Tools\Report\GridType\Util as GridUtil;
use Espo\ORM\EntityManager;

use LogicException;

class JointGridExecutor
{
    private const STUB_KEY = '__STUB__';

    private EntityManager $entityManager;
    private ReportHelper $reportHelper;
    private GridHelper $gridHelper;
    private ResultHelper $resultHelper;
    private Language $language;
    private GridUtil $gridUtil;
    private Service $service;

    public function __construct(
        EntityManager $entityManager,
        ReportHelper $reportHelper,
        GridHelper $gridHelper,
        ResultHelper $resultHelper,
        Language $language,
        GridUtil $gridUtil,
        Service $service
    ) {
        $this->entityManager = $entityManager;
        $this->reportHelper = $reportHelper;
        $this->gridHelper = $gridHelper;
        $this->resultHelper = $resultHelper;
        $this->language = $language;
        $this->gridUtil = $gridUtil;
        $this->service = $service;
    }

    /**
     * @param ?array<string, ?WhereItem> $idWhereMap
     * @throws Error
     * @throws Forbidden
     */
    public function execute(
        JointData $data,
        ?User $user = null,
        ?array $idWhereMap = null
    ): GridResult {

        if ($data->getJoinedReportDataList() === []) {
            throw new Error("Bad report.");
        }

        $result = null;
        $groupColumn = null;
        $reportList = [];
        $groupCount = null;

        foreach ($data->getJoinedReportDataList() as $item) {
            if (empty($item->id)) {
                throw new Error("Bad report.");
            }

            /** @var ?Report $report */
            $report = $this->entityManager->getEntity(Report::ENTITY_TYPE, $item->id);

            if (!$report) {
                throw new Error("Sub-report $item->id doesn't exist.");
            }

            $reportList[] = $report;
        }

        foreach ($data->getJoinedReportDataList() as $i => $item) {
            $report = $reportList[$i];

            $where = null;

            if ($idWhereMap && isset($idWhereMap[$item->id])) {
                $where = $idWhereMap[$item->id];
            }

            if ($report->isInternal()) {
                $reportObj = $this->reportHelper->createInternalReport($report);

                if (!$reportObj instanceof GridReport) {
                    throw new Error("Bad report class.");
                }

                $subReportResult = $reportObj->run($where, $user);
            }
            else {
                if ($report->getType() !== Report::TYPE_GRID) {
                    throw new Error("Bad sub-report.");
                }

                $this->reportHelper->checkReportCanBeRun($report);

                $subReportResult = $this->service->executeGridReport(
                    $this->reportHelper->fetchGridDataFromReport($report),
                    $where,
                    $user
                );
            }

            $subReportNumericColumnList = $subReportResult->getNumericColumnList();
            $subReportAggregatedColumnList = $subReportResult->getAggregatedColumnList();

            $subReportResult->setColumnOriginalMap([]);
            $subReportResult->setNumericColumnList([]);
            $subReportResult->setAggregatedColumnList([]);

            $columnToUnsetList = [];
            $columnOriginalMap = $subReportResult->getColumnOriginalMap();
            $subReportColumnList = $subReportResult->getColumnList();

            foreach ($subReportColumnList as &$columnPointer) {
                $originalColumnName = $columnPointer;

                $newColumnName = $columnPointer . '@'. $i;

                $columnOriginalMap[$newColumnName] = $columnPointer;

                if (in_array($originalColumnName, $subReportNumericColumnList)) {
                    $subReportResult->setNumericColumnList(
                        array_merge(
                            $subReportResult->getNumericColumnList(),
                            [$newColumnName]
                        )
                    );
                }

                if (
                    $subReportAggregatedColumnList &&
                    in_array($originalColumnName, $subReportAggregatedColumnList)
                ) {
                    $subReportResult->setAggregatedColumnList(
                        array_merge(
                            $subReportResult->getAggregatedColumnList(),
                            [$newColumnName]
                        )
                    );
                }

                if (!in_array($columnPointer, $subReportAggregatedColumnList)) {
                    $columnToUnsetList[] = $newColumnName;
                }

                $columnPointer = $newColumnName;
            }

            $subReportColumnList = array_values(array_filter(
                $subReportColumnList,
                function (string $item) use ($columnToUnsetList) {
                    return !in_array($item, $columnToUnsetList);
                }
            ));

            $subReportResult->setColumnList($subReportColumnList);
            $subReportResult->setColumnOriginalMap($columnOriginalMap);

            $sums = [];

            foreach (get_object_vars($subReportResult->getSums()) as $key => $sum) {
                $sums[$key . '@'. $i] = $sum;
            }

            $subReportResult->setSums((object) $sums);

            $columnNameMap = [];

            foreach ($subReportResult->getColumnNameMap() as $key => $name) {
                if (!str_contains($key, '.')) {
                    if (!empty($item->label)) {
                        $name = $item->label . ' · ' . $name;
                    }
                }

                $columnNameMap[$key . '@'. $i] = $name;
            }

            $subReportResult->setColumnNameMap($columnNameMap);

            $columnTypeMap = [];

            foreach ($subReportResult->getColumnTypeMap() as $key => $type) {
                $columnTypeMap[$key . '@'. $i] = $type;
            }

            $subReportResult->setColumnTypeMap($columnTypeMap);

            $columnDecimalPlacesMap = [];

            foreach (get_object_vars($subReportResult->getColumnDecimalPlacesMap()) as $key => $type) {
                $columnDecimalPlacesMap[$key . '@'. $i] = $type;
            }

            $subReportResult->setColumnDecimalPlacesMap((object) $columnDecimalPlacesMap);

            $chartColors = [];

            if ($subReportResult->getChartColor()) {
                $chartColors[$subReportResult->getColumnList()[0]] = $subReportResult->getChartColor();
            }

            foreach (get_object_vars($subReportResult->getChartColors()) as $key => $color) {
                $chartColors[$key . '@'. $i] = $color;
            }

            $subReportResult->setChartColors((object) $chartColors);

            $cellValueMaps = (object) [];

            foreach (get_object_vars($subReportResult->getCellValueMaps()) as $column => $map) {
                $cellValueMaps->{$column . '@'. $i} = $map;
            }

            $subReportResult->setCellValueMaps($cellValueMaps);

            $reportData = $subReportResult->getReportData();

            foreach (get_object_vars($subReportResult->getReportData()) as $key => $dataItem) {
                $newDataItem = (object) [];

                foreach (get_object_vars($dataItem) as $key1 => $value) {
                    $newDataItem->{$key1 . '@'. $i} = $value;
                }

                $reportData->$key = $newDataItem;
            }

            $subReportResult->setReportData($reportData);

            if ($i === 0) {
                $groupCount = count($report->getGroupBy());

                if ($groupCount) {
                    $groupColumn = $report->getGroupBy()[0];
                }

                if ($groupCount > 2) {
                    throw new Error("Grouping by 2 columns is not supported in joint reports.");
                }

                $result = $subReportResult;

                $result->setEntityTypeList([$report->getTargetEntityType()]);
                $result->setColumnEntityTypeMap([]);
                $result->setColumnReportIdMap([]);
                $result->setColumnSubReportLabelMap([]);
            } else {
                if ($groupCount === null) {
                    throw new LogicException();
                }

                if (count($report->getGroupBy()) !== $groupCount) {
                    throw new Error("Sub-reports must have the same Group By number.");
                }

                foreach ($subReportResult->getColumnList() as $column) {
                    $columnList = $result->getColumnList();
                    $columnList[] = $column;
                    $result->setColumnList($columnList);
                }

                foreach (get_object_vars($subReportResult->getSums()) as $key => $value) {
                    $sums = $result->getSums();
                    $sums->$key = $value;
                    $result->setSums($sums);
                }

                foreach (($subReportResult->getColumnNameMap() ?? []) as $key => $name) {
                    $map = $result->getColumnNameMap();
                    $map[$key] = $name;
                    $result->setColumnNameMap($map);
                }

                foreach (($subReportResult->getColumnTypeMap() ?? []) as $key => $type) {
                    $map = $result->getColumnTypeMap();
                    $map[$key] = $type;
                    $result->setColumnTypeMap($map);
                }

                foreach (get_object_vars($subReportResult->getChartColors()) as $key => $value) {
                    $map = $result->getChartColors();
                    $map->$key = $value;
                    $result->setChartColors($map);
                }

                foreach ($subReportResult->getColumnOriginalMap() as $key => $value) {
                    $map = $result->getColumnOriginalMap();
                    $map[$key] = $value;
                    $result->setColumnOriginalMap($map);
                }

                foreach (get_object_vars($subReportResult->getCellValueMaps()) as $column => $value) {
                    $map = $result->getCellValueMaps();
                    $map->$column = $value;
                    $result->setCellValueMaps($map);
                }

                foreach ($subReportResult->getGroupValueMap() as $group => $v) {
                    $map = $result->getGroupValueMap();

                    if (!array_key_exists($group, $map)) {
                        continue;
                    }

                    $map[$group] = array_replace($map[$group], $v);

                    $result->setGroupValueMap($map);
                }

                foreach ($subReportResult->getNumericColumnList() as $item1) {
                    $list = $result->getNumericColumnList();
                    $list[] = $item1;
                    $result->setNumericColumnList($list);
                }

                foreach ($subReportResult->getAggregatedColumnList() as $item1) {
                    $list = $result->getAggregatedColumnList();
                    $list[] = $item1;
                    $result->setAggregatedColumnList($list);
                }

                foreach (($subReportResult->getGrouping()[0] ?? []) as $groupName) {
                    if (in_array($groupName, $result->getGrouping()[0] ?? [])) {
                        continue;
                    }

                    $list = $result->getGrouping()[0] ?? [];
                    $list[] = $groupName;
                    $result->setGrouping([$list]);
                }

                foreach (get_object_vars($subReportResult->getReportData()) as $key => $dataItem) {
                    $reportData = $result->getReportData();

                    if (property_exists($reportData, $key)) {
                        foreach (get_object_vars($dataItem) as $key1 => $value) {
                            $reportData->$key->$key1 = $value;
                        }
                    } else {
                        $reportData->$key = $dataItem;
                    }

                    $result->setReportData($reportData);
                }

                $entityTypeList = $result->getEntityTypeList() ?? [];
                $entityTypeList[] = $report->getTargetEntityType();
                $result->setEntityTypeList($entityTypeList);
            }

            foreach ($subReportResult->getColumnList() as $column) {
                $columnEntityTypeMap = $result->getColumnEntityTypeMap();
                $columnReportIdMap = $result->getColumnReportIdMap();
                $columnSubReportLabelMap = $result->getColumnSubReportLabelMap();

                $columnEntityTypeMap[$column] = $report->getTargetEntityType();
                $columnReportIdMap[$column] = $report->getId();

                $columnSubReportLabelMap[$column] = !empty($item->label) ?
                    $item->label :
                    $this->language->translateLabel($report->getTargetEntityType(), 'scopeNamesPlural');

                $result->setColumnEntityTypeMap($columnEntityTypeMap);
                $result->setColumnReportIdMap($columnReportIdMap);
                $result->setColumnSubReportLabelMap($columnSubReportLabelMap);
            }
        }

        if (
            $groupColumn &&
            isset($result->getGrouping()[0])
        ) {
            $list = $result->getGrouping()[0];
            $this->resultHelper->prepareGroupingRange($groupColumn, $list);

            $result->setGrouping([$list]);
        }

        foreach (get_object_vars($result->getReportData()) as $key => $dataItem) {
            foreach ($result->getColumnList() as $column) {
                if (property_exists($dataItem, $column)) {
                    continue;
                }

                $originalColumn = $result->getColumnOriginalMap()[$column];
                $originalEntityType = $result->getColumnEntityTypeMap()[$column];

                [, $i] = explode('@', $column);

                $i = (int) $i;

                $report = $reportList[$i];

                $gridData = $this->reportHelper->fetchGridDataFromReport($report);

                $reportData = $result->getReportData();

                if ($this->gridHelper->isColumnNumeric($originalColumn, $gridData)) {
                    $reportData->$key->$column = 0;

                    continue;
                }

                $value = null;

                if ($groupColumn && $groupColumn !== self::STUB_KEY) {
                    $subReportGroupColumn = $report->getGroupBy()[0];

                    if (str_starts_with($originalColumn, $subReportGroupColumn)) {
                        $displayValue = null;

                        $columnData = $this->gridHelper->getDataFromColumnName($originalEntityType, $originalColumn);

                        $e = $this->entityManager->getEntity($columnData->entityType, $key);

                        if ($e) {
                            $value = $e->get($columnData->field);

                            if ($columnData->fieldType === 'link') {
                                $value = $e->get($columnData->field . 'Id');

                                $displayValue = $e->get($columnData->field . 'Name');
                            } else {
                                $displayValue = $this->gridUtil->getCellDisplayValue($value, $columnData);
                            }
                        }

                        if (!is_null($displayValue)) {
                            $maps = $result->getCellValueMaps();

                            if (!property_exists($maps, $column)) {
                                $maps->$column = (object) [];
                            }

                            $maps->$column->$value = $displayValue;

                            $result->setCellValueMaps($maps);
                        }
                    }
                }

                $reportData->$key->$column = $value;

                $result->setReportData($reportData);
            }
        }

        $this->setSummaryColumnList($result, $reportList);

        $result->setSubListColumnList([]);
        $result->setChartType($data->getChartType());
        $result->setIsJoint(true);

        $this->setChartColors($result);
        $this->setChartDataList($reportList, $result);

        return $result;
    }

    /**
     * @param Report[] $reportList
     */
    private function setChartDataList(array $reportList, GridResult $result): void
    {
        $chartColumnList = [];
        $chartY2ColumnList = [];

        foreach ($reportList as $i => $report) {
            $gridData = $this->reportHelper->fetchGridDataFromReport($report);

            $itemDataList = $gridData->getChartDataList();

            if ($itemDataList && count($itemDataList)) {
                foreach ($itemDataList[0]->columnList ?? [] as $item) {
                    $chartColumnList[] = $item . '@' . $i;
                }

                foreach ($itemDataList[0]->y2ColumnList ?? [] as $item) {
                    $chartY2ColumnList[] = $item . '@' . $i;
                }
            }
        }

        if ($chartColumnList === [] && $chartY2ColumnList === []) {
            return;
        }

        $result->setChartDataList([
            (object) [
                'columnList' => $chartColumnList,
                'y2ColumnList' => $chartY2ColumnList,
            ]
        ]);
    }

    private function setChartColors(GridResult $result): void
    {
        $colorList = [];
        $chartColors = $result->getChartColors();

        foreach (get_object_vars($chartColors) as $key => $value) {
            if (in_array($value, $colorList)) {
                unset($chartColors->$key);
            }

            $colorList[] = $value;
        }

        if (array_keys(get_object_vars($chartColors)) === []) {
            $chartColors = null;
        }

        $result->setChartColors($chartColors);
    }

    /**
     * @param Report[] $reportList
     */
    private function setSummaryColumnList(GridResult $result, array $reportList): void
    {
        $summaryColumnList = [];

        foreach ($result->getColumnList() as $column) {
            [, $i] = explode('@', $column);

            $report = $reportList[$i];

            $gridData = $this->reportHelper->fetchGridDataFromReport($report);

            if ($this->gridHelper->isColumnSummary($column, $gridData)) {
                $summaryColumnList[] = $column;
            }
        }

        $result->setSummaryColumnList($summaryColumnList);
    }
}
