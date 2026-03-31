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

use DateTimeImmutable;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Attachment;
use Espo\Modules\Advanced\Tools\Report\GridType\Data as GridData;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\Util as QueryComposerUtil;

use Exception;
use PDO;
use DateInterval;
use DateTime;

class ResultHelper
{
    private const STUB_KEY = '__STUB__';

    private const WHERE_TYPE_AND = 'and';
    private const WHERE_TYPE_CURRENT_YEAR = 'currentYear';
    private const WHERE_TYPE_LAST_YEAR = 'lastYear';
    private const WHERE_TYPE_CURRENT_FISCAL_YEAR = 'currentFiscalYear';
    private const WHERE_TYPE_LAST_FISCAL_YEAR = 'lastFiscalYear';

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Language $language,
        private Config $config,
        private Helper $helper,
        private Util $util,
        private Log $log
    ) {}

    /**
     * Before PHP 8.1 strings was returned by PDO. Need to preserve the same.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param string[] $groupList
     */
    public function fixRows(array &$rows, array $groupList, bool &$emptyStringGroupExcluded): void
    {
        $excludeEmptyStrings = version_compare(phpversion(), '8.1.0', '>=');

        foreach ($rows as $i => $row) {
            foreach ($row as $column => $value) {
                if (
                    $excludeEmptyStrings &&
                    in_array($column, $groupList) &&
                    $value === ''
                ) {
                    unset($rows[$i]);
                    $emptyStringGroupExcluded = true;

                    break;
                }

                $rows[$i][$column] = (string) $value;
            }
        }

        $rows = array_values($rows);
    }

    /**
     * @param string[] $groupList
     * @param array<string, array<string, mixed>> $groupValueMap
     * @param array<int, array<string, mixed>> $rows
     *
     * @todo Check populateEnumGroupValues is working.
     */
    public function populateGroupValueMap(
        Data $data,
        array &$groupList,
        array &$rows,
        array &$groupValueMap
    ): void {

        if (count($data->getGroupBy()) === 0) {
            $groupValueMap = [
                self::STUB_KEY => [self::STUB_KEY => '']
            ];

            return;
        }

        foreach ($data->getGroupBy() as $groupByItem) {
            $this->populateGroupValueMapItem(
                $data,
                $groupList,
                $rows,
                $groupValueMap,
                $groupByItem
            );
        }
    }

    /**
     * @param string[] $groupList
     * @param array<string, array<string, mixed>> $groupValueMap
     * @param array<int, array<string, mixed>> $rows
     */
    private function populateGroupValueMapItem(
        Data $data,
        array &$groupList,
        array &$rows,
        array &$groupValueMap,
        string $groupByItem
    ): void {

        if ($groupByItem === 'id') {
            foreach ($rows as $row) {
                /** @var ?string $id */
                $id = $row[$groupByItem] ?? null;

                if (!$id) {
                    continue;
                }

                $rowEntity = $this->entityManager->getEntityById($data->getEntityType(), $id);

                if (!$rowEntity) {
                    continue;
                }

                $groupValueMap[$groupByItem][$id] = $rowEntity->get('name');
            }

            return;
        }

        $columnData = $this->helper->getDataFromColumnName($data->getEntityType(), $groupByItem);

        if ($columnData->fieldType === 'enum') {
            $this->populateEnumGroupValues(
                entityType: $columnData->entityType,
                field: $columnData->field,
                groupValueMap: $groupValueMap,
                groupByItem: $groupByItem,
            );

            return;
        }

        if ($columnData->link) {
            return;
        }

        if ($columnData->fieldType === 'linkParent') {
            $this->mergeGroupByColumns(
                rowList: $rows, /** @phpstan-ignore-line parameterByRef.type */
                groupList: $groupList,
                key: $groupByItem,
                columnList: [
                    $groupByItem . 'Type',
                    $groupByItem . 'Id',
                ],
            );

            $groupValueMap[$groupByItem] = [];

            foreach ($rows as $row) {
                $rowValue = $row[$groupByItem] ?? null;

                $itemCompositeValue = $rowValue;

                if (!$itemCompositeValue) {
                    continue;
                }

                [$parentEntityType, $id] = explode(':,:', $itemCompositeValue);

                /** @phpstan-ignore-next-line parameterByRef.type */
                $groupValueMap[$groupByItem][$rowValue] = null;

                if (!$parentEntityType || !$id) {
                    continue;
                }

                $itemEntity = $this->entityManager->getEntityById($parentEntityType, $id);

                $itemScopeLabel = $this->language->translateLabel($parentEntityType, 'scopeNames');

                if (!$itemEntity) {
                    /** @phpstan-ignore-next-line parameterByRef.type */
                    $groupValueMap[$groupByItem][$rowValue] = $itemScopeLabel . ': ' . $id;

                    continue;
                }

                /** @phpstan-ignore-next-line parameterByRef.type */
                $groupValueMap[$groupByItem][$rowValue] = $itemScopeLabel . ': ' . $itemEntity->get('name');
            }

            return;
        }

        if (in_array($columnData->fieldType, ['link', 'file', 'image'])) {
            $groupValueMap[$groupByItem] = [];

            $foreignEntityType = $this->metadata
                ->get(['entityDefs', $data->getEntityType(), 'links', $groupByItem, 'entity']);

            if (in_array($columnData->fieldType, ['file', 'image'])) {
                $foreignEntityType = Attachment::ENTITY_TYPE;
            }

            if (!$foreignEntityType) {
                return;
            }

            foreach ($rows as $row) {
                $id = $row[$groupByItem . 'Id'] ?? null;

                if (!$id) {
                    continue;
                }

                $foreignEntity = $this->entityManager->getEntityById($foreignEntityType, $id);

                if (!$foreignEntity) {
                    continue;
                }

                /** @phpstan-ignore-next-line parameterByRef.type */
                $groupValueMap[$groupByItem][$id] = $foreignEntity->get('name');
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $groupValueMap
     */
    private function populateEnumGroupValues(
        string $entityType,
        string $field,
        array &$groupValueMap,
        string $groupByItem
    ): void {

        /** @var ?array<string, string> $options */
        $options = $this->language->translate($field, 'options', $entityType);

        if (is_array($options)) {
            $groupValueMap[$groupByItem] = $options;

            return;
        }

        $translation = $this->metadata->get(['entityDefs', $entityType, 'fields', $field, 'translation']);
        $optionsReference = $this->metadata->get(['entityDefs', $entityType, 'fields', $field, 'optionsReference']);

        if (!$translation && $optionsReference) {
            $translation = str_replace('.', '.options.', $optionsReference);
        }

        if (!$translation) {
            return;
        }

        /** @var ?array<string, string> $options */
        $options = $this->language->get(explode('.', $translation));

        if (is_array($options)) {
            $groupValueMap[$groupByItem] = $options;
        }
    }

    /**
     * @param array<string, mixed>[] $rowList
     * @param string[] $groupList
     * @param string[] $columnList
     */
    private function mergeGroupByColumns(
        array &$rowList,
        array &$groupList,
        string $key,
        array $columnList
    ): void {

        foreach ($rowList as &$row) {
            $arr = [];

            $isEmpty = true;

            foreach ($columnList as $column) {
                $value = $row[$column];

                if (empty($value)) {
                    $arr[] = '';

                    continue;
                }

                $isEmpty = false;

                $arr[] = $value;
            }

            $row[$key] = !$isEmpty ?
                implode(':,:', $arr) : '';

            foreach ($columnList as $column) {
                unset($row[$column]);
            }
        }

        foreach ($columnList as $j => $column) {
            foreach ($groupList as $i => $groupByItem) {
                if ($groupByItem === $column) {
                    if ($j === 0) {
                        $groupList[$i] = $key;
                    } else {
                        unset($groupList[$i]);
                    }
                }
            }
        }

        $groupList = array_values($groupList);
    }

    /**
     * @param string[] $groupList
     * @param array<string, mixed>[] $rows
     * @param array<int, string[]> $grouping
     */
    public function populateGrouping(
        Data $data,
        array $groupList,
        array $rows,
        ?WhereItem $where,
        array &$grouping
    ): void {

        if (count($data->getGroupBy()) === 0) {
            $grouping[] = [self::STUB_KEY];

            return;
        }

        foreach ($groupList as $i => $groupCol) {
            $groupAlias = $this->util->sanitizeSelectAlias($groupCol);

            /** @phpstan-ignore-next-line parameterByRef.type */ // phpstan bug ?
            $grouping[$i] = [];

            foreach ($rows as $row) {
                if (!in_array($row[$groupAlias], $grouping[$i])) {
                    /** @phpstan-ignore-next-line parameterByRef.type */ // phpstan bug ?
                    $grouping[$i][] = $row[$groupAlias];
                }
            }

            if ($i > 0) {
                if (in_array('ASC:' . $groupCol, $data->getOrderBy())) {
                    sort($grouping[$i]);
                }

                if (in_array('DESC:' . $groupCol, $data->getOrderBy())) {
                    rsort($grouping[$i]);
                }
                /*else if (in_array('LIST:' . $groupCol, $data->getOrderBy())) {
                    // @todo Revise. Where $orderLists came from?
                    if (!empty($orderLists[$groupCol])) {
                        $list = $orderLists[$groupCol];

                        usort($grouping[$i], function ($a, $b) use ($list) {
                            return array_search($a, $list) > array_search($b, $list);
                        });
                    }
                }*/
            }

            $this->prepareGroupingRange(
                groupCol: $groupCol,
                list: $grouping[$i], /** @phpstan-ignore-line parameterByRef.type */
                data: $data,
                where: $where,
            );

            if (count($data->getGroupBy()) === 2 && $data->getOrderBy()) {
                $originalGroupItem = $data->getGroupBy()[$i];

                $this->orderGrouping(
                    entityType: $data->getEntityType(),
                    originalGroupItem: $originalGroupItem,
                    groupCol: $groupCol,
                    orderByParam: $data->getOrderBy()[0],
                    list: $grouping[$i], /** @phpstan-ignore-line parameterByRef.type */
                    rows: $rows,
                );
            }
        }
    }

    /**
     * @param scalar[] $list
     * @param array<int, array<string, mixed>> $rows
     */
    private function orderGrouping(
        string $entityType,
        string $originalGroupItem,
        string $groupCol,
        string $orderByParam,
        array &$list,
        array $rows
    ): void {

        $columnData = $this->helper->getDataFromColumnName($entityType, $originalGroupItem);

        $fieldType = $columnData->fieldType;

        if (in_array($fieldType, ['date', 'datetime', 'datetimeOptional'])) {
            return;
        }

        $order = explode(':', $orderByParam, 2)[0];
        $orderBy = explode(':', $orderByParam, 2)[1];

        $func = explode(':', $orderBy, 2)[0];

        if (!in_array($func, ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'])) {
            return;
        }

        $map = [];

        foreach ($rows as $rowItem) {
            $itemColumn = $rowItem[$groupCol];
            $itemValue = $rowItem[$orderBy] ?? 0;

            $value = $map[$itemColumn] ?? 0;

            $value += $itemValue;

            $map[$itemColumn] = $value;
        }

        usort($list, function ($item1, $item2) use ($map, $order) {
            $v1 = $map[$item1] ?? 0;
            $v2 = $map[$item2] ?? 0;

            if ($order === 'DESC') {
                return $v2 - $v1;
            }

            return $v1 - $v2;
        });
    }

    /**
     * @param string[] $list
     */
    public function prepareGroupingRange(
        string $groupCol,
        array &$list,
        ?GridData $data = null,
        ?WhereItem $where = null
    ): void {

        $isDate = false;

        if (str_starts_with($groupCol, 'MONTH:')) {
            $isDate = true;
            $this->prepareGroupingMonth($list);
        }
        else if (str_starts_with($groupCol, 'QUARTER:') || str_starts_with($groupCol, 'QUARTER_')) {
            $isDate = true;
            $this->prepareGroupingQuarter($list);
        }
        else if (str_starts_with($groupCol, 'WEEK_0:')) {
            $isDate = true;
            $this->prepareGroupingWeek($list);
        }
        else if (str_starts_with($groupCol, 'WEEK_1:')) {
            $isDate = true;
            $this->prepareGroupingWeek($list, true);
        }
        else if (str_starts_with($groupCol, 'DAY:')) {
            $isDate = true;
            $this->prepareGroupingDay($list);
        }
        else if (str_starts_with($groupCol, 'YEAR:') || str_starts_with($groupCol, 'YEAR_')) {
            $isDate = true;
            $this->prepareGroupingYear($list);
        }

        if (!$data) {
            return;
        }

        $filterList = [];

        if ($where && $where->getType() === self::WHERE_TYPE_AND) {
            foreach ($where->getItemList() as $whereItem) {
                $filterList[] = $whereItem;
            }
        }

        if ($data->getFiltersWhere() && $data->getFiltersWhere()->getType() === self::WHERE_TYPE_AND) {
            foreach ($data->getFiltersWhere()->getItemList() as $whereItem) {
                $filterList[] = $whereItem;
            }
        }

        if ($isDate) {
            $this->prepareGroupingRangeDate($groupCol, $list, $data, $filterList);

            return;
        }

        if (str_contains($groupCol, ':')) {
            return;
        }

        $columnData = $this->helper->getDataFromColumnName($data->getEntityType(), $groupCol);

        if ($columnData->fieldType === 'enum') {
            $this->prepareGroupingRangeEnum($groupCol, $list, $data, $filterList);
        }
    }

    /**
     * @param string[] $list
     * @param WhereItem[] $filterList
     */
    private function prepareGroupingRangeDate(
        string $groupCol,
        array &$list,
        Data $data,
        array $filterList
    ): void {

        if (in_array('DESC:' . $groupCol, $data->getOrderBy())) {
            rsort($list);
        }

        if (!$filterList) {
            return;
        }

        if (!str_starts_with($groupCol, 'MONTH:')) {
            return;
        }

        $fillToYearStart = false;
        $subjectItemType = null;

        foreach ($filterList as $item) {
            if (!$item->getAttribute()) {
                continue;
            }

            $subjectItemType = $item->getType();

            if (
                $item->getType() === self::WHERE_TYPE_CURRENT_YEAR ||
                $item->getType() === self::WHERE_TYPE_LAST_YEAR ||
                $item->getType() === self::WHERE_TYPE_CURRENT_FISCAL_YEAR ||
                $item->getType() === self::WHERE_TYPE_LAST_FISCAL_YEAR
            ) {
                if ($item->getAttribute() === substr($groupCol, 6)) {
                    $fillToYearStart = true;

                    break;
                }

                $aList = QueryComposerUtil::getAllAttributesFromComplexExpression($groupCol);

                if (count($aList) && $aList[0] === $item->getAttribute()) {
                    $fillToYearStart = true;

                    break;
                }
            }
        }

        if (!$fillToYearStart || !count($list)) {
            return;
        }

        if (
            $subjectItemType === self::WHERE_TYPE_CURRENT_FISCAL_YEAR ||
            $subjectItemType === self::WHERE_TYPE_LAST_FISCAL_YEAR
        ) {
            $this->fillFiscalYearRange($list, $subjectItemType);

            return;
        }

        $first = $list[0];

        [$year, $month] = explode('-', $first);

        if (intval($month) > 1) {
            for ($m = intval($month) - 1; $m >= 1; $m--) {
                $newDate = $year . '-' . str_pad(strval($m), 2, '0', STR_PAD_LEFT);

                array_unshift($list, $newDate);
            }
        }

        $last = $list[count($list) - 1];

        [$year, $month] = explode('-', $last);

        if ($subjectItemType === self::WHERE_TYPE_CURRENT_YEAR) {
            $dtThisMonthStart = new DateTime();
            $todayMonthNumber = intval($dtThisMonthStart->format('m'));
        }
        else {
            $todayMonthNumber = 12;
        }

        for ($m = intval($month) + 1; $m <= $todayMonthNumber; $m ++) {
            $newDate = $year . '-' . str_pad(strval($m), 2, '0', STR_PAD_LEFT);

            $list[] = $newDate;
        }
    }

    /**
     * @param string[] $list
     * @param WhereItem[] $filterList
     */
    private function prepareGroupingRangeEnum(
        string $groupCol,
        array &$list,
        Data $data,
        array $filterList
    ): void {

        foreach ($filterList as $item) {
            if ($item->getAttribute() === $groupCol) {
                return;
            }
        }

        $columnData = $this->helper->getDataFromColumnName($data->getEntityType(), $groupCol);

        $optionList = $this->metadata
            ->get(['entityDefs', $columnData->entityType, 'fields', $columnData->field, 'options']);

        $optionsReference = $this->metadata
            ->get(['entityDefs', $columnData->entityType, 'fields', $columnData->field, 'optionsReference']);

        if ($optionsReference) {
            [$refEntityType, $refField] = explode('.', $optionsReference);

            $optionList = $this->metadata
                ->get(['entityDefs', $refEntityType, 'fields', $refField, 'options']);
        }

        if (!is_array($optionList)) {
            return;
        }

        foreach ($optionList as $option) {
            if (!in_array($option, $list)) {
                /** @phpstan-ignore-next-line parameterByRef.type */ // phpstan bug ?
                $list[] = $option;
            }
        }

        if (in_array('LIST:'. $groupCol, $data->getOrderBy())) {
            /** @phpstan-ignore-next-line parameterByRef.type */ // phpstan bug ?
            $list = $optionList;
        }
    }

    /**
     * @param string[] $list
     */
    private function prepareGroupingMonth(array &$list): void
    {
        sort($list);
        $fullList = [];

        if (isset($list[0]) && isset($list[count($list) - 1])) {
            try {
                $dt = new DateTime($list[0] . '-01');
                $dtEnd = new DateTime($list[count($list)  - 1] . '-01');
            }
            catch (Exception $e) {
                $this->log->warning("Report grouping error:" . $e->getMessage());

                return;
            }


            $interval = new DateInterval('P1M');

            while ($dt->getTimestamp() <= $dtEnd->getTimestamp()) {
                $fullList[] = $dt->format('Y-m');

                $dt->add($interval);
            }

            $list = $fullList;
        }
    }

    /**
     * @param string[] $list
     */
    private function prepareGroupingQuarter(array &$list): void
    {
        sort($list);
        $fullList = [];

        if (isset($list[0]) && isset($list[count($list) - 1])) {
            $startArr = explode('_', $list[0]);
            $endArr = explode('_', $list[count($list)  - 1]);

            $start1 = intval($startArr[1]);
            $end1 = intval($endArr[1]);

            $start0 = intval($startArr[0]);
            $end0 = intval($endArr[0]);


            $startString = strval((($start1 - 1) * 3) + 1);
            $endString = strval((($end1 - 1) * 3) + 1);

            $startMonth = str_pad($startString, 2, '0', STR_PAD_LEFT);
            $endMonth = str_pad($endString, 2, '0', STR_PAD_LEFT);

            try {
                $dt = new DateTime("$start0-$startMonth-01");
                $dtEnd = new DateTime("$end0-$endMonth-01");
            } catch (Exception $e) {
                $this->log->warning("Report grouping error:" . $e->getMessage());

                return;
            }

            $interval = new DateInterval('P3M');

            while ($dt->getTimestamp() <= $dtEnd->getTimestamp()) {
                $fullList[] = $dt->format('Y') . '_' . (floor(intval($dt->format('m')) / 3) + 1);
                $dt->add($interval);
            }

            $list = $fullList;
        }
    }

    /**
     * @param string[] $list
     */
    private function prepareGroupingDay(array &$list): void
    {
        sort($list);
        $fullList = [];

        if (isset($list[0])) {
            try {
                $dt = new DateTime($list[0]);
                $dtEnd = new DateTime($list[count($list)  - 1]);
            }
            catch (Exception $e) {
                $this->log->warning("Report grouping error:" . $e->getMessage());

                return;
            }

            $interval = new DateInterval('P1D');

            while ($dt->getTimestamp() <= $dtEnd->getTimestamp()) {
                $fullList[] = $dt->format('Y-m-d');
                $dt->add($interval);
            }

            $list = $fullList;
        }
    }

    /**
     * @param string[] $list
     */
    private function prepareGroupingWeek(array &$list, bool $fromMonday = false): void
    {
        sort($list);

        usort($list, function ($v1, $v2) {
            [$year1, $week1] = explode('/', $v1);
            [$year2, $week2] = explode('/', $v2);

            if ($year2 > $year1 || $year2 === $year1 && $week2 > $week1) {
                return -1;
            }

            return 1;
        });

        if (isset($list[0]) && isset($list[count($list) - 1])) {
            $first = $list[0];

            $last = $list[count($list) - 1];

            [$year, $week] = explode('/', $first);

            $week++;

            try {
                $dt = new DateTime($year . '-01-01');
            } catch (Exception $e) {
                $this->log->warning("Report grouping error:" . $e->getMessage());

                return;
            }

            $diff = $this->config->get('weekStart', 0) - $dt->format('N');

            if ($this->config->get('weekStart') && $dt->format('N') === '1') {
                $week--;
            }

            if ($diff > 0) {
                $diff -= 7;
            }

            $dt->modify($diff . ' days');
            $dt->modify($week. ' weeks');

            [$year, $week] = explode('/', $last);

            try {
                $dtEnd = new DateTime($year . '-01-01');
            }
            catch (Exception $e) {
                $this->log->warning("Report grouping error:" . $e->getMessage());

                return;
            }

            $diff = $this->config->get('weekStart', 0) - $dtEnd->format('N');

            if ($diff > 0) {
                $diff -= 7;
            }

            $dtEnd->modify($diff . ' days');

            if ($this->config->get('weekStart') && $dt->format('N') === '1') {
                $week--;
            }

            $dtEnd->modify($week . ' weeks');

            $mSelectList = [];

            while ($dt->getTimestamp() <= $dtEnd->getTimestamp()) {
                $dItem = $dt->format('Y-m-d');
                $fItem = $fromMonday ? "WEEK_1:('" . $dItem . "')" : "WEEK_0:('" . $dItem . "')";

                $mSelectList[] = [$fItem, $dItem];

                $dt->modify('+1 week');
            }

            if (count($mSelectList)) {
                $sth = $this->entityManager
                    ->getQueryExecutor()
                    ->execute(
                         SelectBuilder::create()
                            ->select($mSelectList)
                            ->limit(1)
                            ->build()
                    );

                $row = $sth->fetch(PDO::FETCH_ASSOC);

                foreach ($row as $item) {
                    if (!in_array($item, $list)) {
                        /** @phpstan-ignore-next-line parameterByRef.type */ // phpstan bug ?
                        $list[] = $item;
                    }
                }

                sort($list);
                usort($list, function ($v1, $v2) {
                    [$year1, $week1] = explode('/', $v1);
                    [$year2, $week2] = explode('/', $v2);

                    if ($year2 > $year1 || $year2 === $year1 && $week2 > $week1) {
                        return -1;
                    }

                    return 1;
                });
            }

            if (!in_array($first, $list)) {
                array_unshift($list, $first);
            }

            if (!in_array($last, $list)) {
                /** @phpstan-ignore-next-line parameterByRef.type */ // phpstan bug ?
                $list[] = $last;
            }
        }
    }

    /**
     * @param string[] $list
     */
    private function prepareGroupingYear(array &$list): void
    {
        sort($list);
        $fullList = [];

        if (isset($list[0])) {
            try {
                $dt = new DateTime($list[0] . '-01-01');
                $dtEnd = new DateTime($list[count($list) - 1] . '-01-01');
            }
            catch (Exception $e) {
                $this->log->warning("Report grouping error:" . $e->getMessage());

                return;
            }

            $interval = new DateInterval('P1Y');

            while ($dt->getTimestamp() <= $dtEnd->getTimestamp()) {
                $fullList[] = $dt->format('Y');

                $dt->add($interval);
            }

            $list = $fullList;
        }
    }

    /**
     * @param string[] $groupList
     * @param array<int, string[]> $grouping
     * @param array<string, mixed>[] $rows
     * @param string[] $nonSummaryColumnList
     */
    public function populateRows(
        Data $data,
        array $groupList,
        array $grouping,
        array &$rows,
        array $nonSummaryColumnList
    ): void {

        if (count($data->getGroupBy()) === 0) {
            if (count($rows)) {
                $rows[0][self::STUB_KEY] = self::STUB_KEY;
            }

            return;
        }

        if (count($data->getGroupBy()) === 1) {
            $this->populateRows1($data, $groupList, $grouping, $rows, $nonSummaryColumnList);

            return;
        }

        if (count($data->getGroupBy()) === 2) {
            $this->populateRows2($data, $groupList, $grouping, $rows, $nonSummaryColumnList);
        }
    }

    /**
     * @param string[] $groupList
     * @param array<int, string[]> $grouping
     * @param array<string, mixed>[] $rows
     * @param string[] $nonSummaryColumnList
     */
    private function populateRows1(
        Data $data,
        array $groupList,
        array $grouping,
        array &$rows,
        array $nonSummaryColumnList
    ): void {
        $groupCol = $groupList[0];

        if (
            str_starts_with($groupCol, 'MONTH:') ||
            str_starts_with($groupCol, 'YEAR:') ||
            str_starts_with($groupCol, 'DAY:')
        ) {
            foreach ($grouping[0] as $groupValue) {
                $isMet = false;

                foreach ($rows as $row) {
                    if ($groupValue === $row[$this->util->sanitizeSelectAlias($groupCol)]) {
                        $isMet = true;

                        break;
                    }
                }

                if ($isMet) {
                    continue;
                }

                $newRow = [];
                $newRow[$this->util->sanitizeSelectAlias($groupCol)] = $groupValue;

                foreach ($data->getColumns() as $column) {
                    if (in_array($column, $nonSummaryColumnList)) {
                        continue;
                    }

                    $newRow[$column] = 0;
                }

                $rows[] = $newRow;
            }
        }
    }

    /**
     * @param string[] $groupList
     * @param array<int, string[]> $grouping
     * @param array<string, mixed>[] $rows
     * @param string[] $nonSummaryColumnList
     */
    private function populateRows2(
        Data $data,
        array $groupList,
        array $grouping,
        array &$rows,
        array $nonSummaryColumnList,
    ): void {

        $groupCol1 = $groupList[0];
        $groupCol2 = $groupList[1];

        if (
            str_starts_with($groupCol1, 'MONTH:') ||
            str_starts_with($groupCol1, 'YEAR:') ||
            str_starts_with($groupCol1, 'DAY:') ||
            str_starts_with($groupCol2, 'MONTH:') ||
            str_starts_with($groupCol2, 'YEAR:') ||
            str_starts_with($groupCol2, 'DAY:')
        ) {
            $skipFilling = false;

            if (
                str_starts_with($groupCol1, 'DAY:') ||
                str_starts_with($groupCol2, 'DAY:')
            ) {
                $skipFilling = true;

                foreach ($data->getColumns() as $column) {
                    if (str_starts_with($column, 'AVG:')) {
                        $skipFilling = false;
                    }
                }
            }

            if ($skipFilling) {
                return;
            }

            foreach ($grouping[0] as $groupValue1) {
                foreach ($grouping[1] as $groupValue2) {
                    $isMet = false;

                    foreach ($rows as $row) {
                        if (
                            $groupValue1 === $row[$this->util->sanitizeSelectAlias($groupCol1)] &&
                            $groupValue2 === $row[$this->util->sanitizeSelectAlias($groupCol2)]
                        ) {
                            $isMet = true;

                            break;
                        }
                    }

                    if ($isMet) {
                        continue;
                    }

                    $newRow = [];

                    $newRow[$this->util->sanitizeSelectAlias($groupCol1)] = $groupValue1;
                    $newRow[$this->util->sanitizeSelectAlias($groupCol2)] = $groupValue2;

                    foreach ($data->getColumns() as $column) {
                        if (in_array($column, $nonSummaryColumnList)) {
                            continue;
                        }

                        $newRow[$column] = 0;
                    }

                    $rows[] = $newRow;
                }
            }
        }
    }

    /**
     * @param string[] $linkColumns
     * @param array<string, mixed>[] $rows
     * @param array<string, array<string, mixed>> $groupValueMap
     */
    public function populateGroupValueMapByLinkColumns(
        Data $data,
        array $linkColumns,
        array $rows,
        array &$groupValueMap
    ): void {

        foreach ($linkColumns as $column) {
            if (array_key_exists($column, $groupValueMap)) {
                continue;
            }

            /** @phpstan-ignore-next-line parameterByRef.type */ // phpstan bug ?
            $groupValueMap[$column] = [];

            foreach ($rows as $row) {
                if (!array_key_exists($column . 'Id', $row)) {
                    continue;
                }

                if (array_key_exists($column . 'Name', $row)) {
                    /** @phpstan-ignore-next-line parameterByRef.type */ // phpstan bug ?
                    $groupValueMap[$column][$row[$column . 'Id']] = $row[$column . 'Name'];

                    continue;
                }

                $relatedId = $row[$column . 'Id'];

                if (!str_contains($column, '.')) {
                    continue;
                }

                [$link1, $link2] = explode('.', $column);

                $entityType1 = $this->metadata
                    ->get(['entityDefs', $data->getEntityType(), 'links', $link1, 'entity']);

                if (!$entityType1) {
                    continue;
                }

                $entityType2 = $this->metadata
                    ->get(['entityDefs', $entityType1, 'links', $link2, 'entity']);

                if (!$entityType2) {
                    continue;
                }

                $relatedEntity = $this->entityManager->getEntityById($entityType2, $relatedId);

                if ($relatedEntity) {
                    $idValue = $row[$column . 'Id'];

                    /** @phpstan-ignore-next-line parameterByRef.type */ // phpstan bug ?
                    $groupValueMap[$column][$idValue] = $relatedEntity->get('name');
                }
            }
        }
    }

    /**
     * @param array<string, string> $columnTypeMap
     * @param array<string, ?int> $columnDecimalPlacesMap
     * @param array<string, string> $columnNameMap
     */
    public function populateColumnInfo(
        Data $data,
        array &$columnTypeMap,
        array &$columnDecimalPlacesMap,
        array &$columnNameMap
    ): void {

        foreach ($data->getColumns() as $item) {
            $columnData = $this->helper->getDataFromColumnName($data->getEntityType(), $item);

            /** @var string $type */
            $type = $this->metadata
                ->get(['entityDefs', $columnData->entityType, 'fields', $columnData->field, 'type']);

            if ($data->getEntityType() === 'Opportunity' && $columnData->field === 'amountWeightedConverted') {
                $type = 'currencyConverted';
            }

            if ($columnData->function === 'COUNT') {
                $type = 'int';
            }

            /** @var ?int $decimalPlaces */
            $decimalPlaces = $data->getColumnDecimalPlaces($item) ??
                $this->metadata
                    ->get(['entityDefs', $columnData->entityType, 'fields', $columnData->field, 'decimalPlaces']);

            $columnTypeMap[$item] = $type;
            $columnDecimalPlacesMap[$item] = $decimalPlaces;

            $columnNameMap[$item] = $data->getColumnLabel($item) ??
                $this->util->translateColumnName($data->getEntityType(), $item);
        }
    }

    /**
     * @param array<int, string[]> $grouping
     * @param array<string, array<string, mixed>> $groupValueMap
     */
    public function populateGroupValueMapForDateFunctions(Data $data, array $grouping, array &$groupValueMap): void
    {
        foreach ($data->getGroupBy() as $level => $item) {
            if (str_starts_with($item, 'QUARTER:')) {
                foreach ($grouping[$level] as $value) {
                    $key = $value;
                    [$year, $quarter] = explode('_', $value);
                    $value = 'Q' . $quarter . ' ' . $year;

                    $groupValueMap[$item][$key] = $value;
                }
            }

            if (str_starts_with($item, 'QUARTER_FISCAL:')) {
                foreach ($grouping[$level] as $value) {
                    $key = $value;
                    [$year, $quarter] = explode('_', $value);

                    $nextYear = ((int) $year) + 1;

                    $value = "Q$quarter $year-$nextYear";

                    $groupValueMap[$item][$key] = $value;
                }
            }
            else if (str_starts_with($item, 'YEAR_FISCAL:')) {
                foreach ($grouping[$level] as $value) {
                    $key = $value;

                    $groupValueMap[$item][$key] = $value . '-' . (intval($value) + 1);
                }
            }
        }
    }

    /**
     * Sort grouping for some field types which cannot be ordered with SQL.
     *
     * @param array<int, string[]> $grouping
     * @param array<string, array<string, mixed>> $groupValueMap
     */
    public function sortGrouping(Data $data, array &$grouping, array $groupValueMap): void
    {
        // Order for some field types that cannot be ordered with SQL.
        foreach ($data->getGroupBy() as $i => $groupByItem) {
            $columnData = $this->helper->getDataFromColumnName($data->getEntityType(), $groupByItem);

            $fieldType = $columnData->fieldType;

            if ($columnData->function) {
                continue;
            }

            $index = self::findInOrderBy($data->getOrderBy(), $groupByItem);

            if (
                in_array($fieldType, ['link', 'linkParent']) &&
                $index !== null
            ) {
                $map = $groupValueMap[$groupByItem] ?? [];

                $isDesc = str_starts_with($data->getOrderBy()[$index] ?? '', 'DESC:');

                usort($grouping[$i], function ($k1, $k2) use ($map, $isDesc) {
                    $v1 = $map[$k1] ?? '';
                    $v2 = $map[$k2] ?? '';

                    return strcmp($v1, $v2) * ($isDesc ? -1 : 1);
                });
            }
        }
    }

    /**
     * @param string[] $orderBy
     */
    private static function findInOrderBy(array $orderBy, string $field): ?int
    {
        foreach ($orderBy as $i => $item) {
            if ($item === 'ASC:' . $field || $item === 'DESC:' . $field) {
                return $i;
            }
        }

        return null;
    }

    public function calculateSums(Data $data, Result $result): void
    {
        if (count($data->getGroupBy()) !== 2) {
            return;
        }

        $group1NonSummaryColumnList = [];
        $group2NonSummaryColumnList = [];

        foreach ($result->getNonSummaryColumnList() as $column) {
            $group = $result->getNonSummaryColumnGroupMap()->$column ?? null;

            if ($group === $result->getGroupByList()[0]) {
                $group1NonSummaryColumnList[] = $column;
            }

            if ($group === $result->getGroupByList()[1]) {
                $group2NonSummaryColumnList[] = $column;
            }
        }

        $result->setGroup1NonSummaryColumnList($group1NonSummaryColumnList);
        $result->setGroup2NonSummaryColumnList($group2NonSummaryColumnList);
        $result->setGroup1Sums($result->getSums()); // object<object<int|float>>

        $group2Sums = [];

        foreach ($result->getGrouping()[1] ?? [] as $group2) {
            $o = [];

            foreach ($result->getSummaryColumnList() as $column) {
                $columnData = $this->helper->getDataFromColumnName($data->getEntityType(), $column);

                $function = $columnData->function;

                $sum = 0;

                foreach ($result->getGrouping()[0] ?? [] as $group1) {
                    $value = 0;

                    if (isset($result->getReportData()->$group1->$group2->$column)) {
                        $value = $result->getReportData()->$group1->$group2->$column;
                    }

                    if ($function === 'MAX') {
                        if ($value > $sum) {
                            $sum = $value;
                        }
                    } else if ($function === 'MIN') {
                        if ($value < $sum) {
                            $sum = $value;
                        }
                    } else {
                        $sum += $value;
                    }
                }

                if ($function === 'AVG') {
                    $sum = $sum / count($result->getGrouping()[0] ?? []);
                }

                $o[$column] = $sum;
            }

            $group2Sums[$group2] = $o;
        }

        $sums = (object) [];

        foreach ($result->getSummaryColumnList() as $column) {
            $columnData = $this->helper->getDataFromColumnName($data->getEntityType(), $column);
            $function = $columnData->function;
            $sum = 0;

            foreach ($result->getGrouping()[0] ?? [] as $group1) {
                $value = 0;

                if (isset($result->getGroup1Sums()->$group1->$column)) {
                    $value = $result->getGroup1Sums()->$group1->$column;
                }

                if ($function === 'MAX') {
                    if ($value > $sum) {
                        $sum = $value;
                    }
                } else if ($function === 'MIN') {
                    if ($value < $sum) {
                        $sum = $value;
                    }
                } else {
                    $sum += $value;
                }
            }

            if ($function === 'AVG') {
                $sum = $sum / count($result->getGrouping()[0] ?? []);
            }

            $sums->$column = $sum;
        }

        $result->setSums($sums);
        $result->setGroup2Sums((object) $group2Sums); // object<object<int|float>>
    }

    /**
     * @param string[] $list
     */
    private function fillFiscalYearRange(array &$list, string $type): void
    {
        $startMonth = $this->config->get('fiscalYearShift', 0) + 1;

        $dtCurrentMonth = (new DateTimeImmutable())
            ->setTime(0, 0)
            ->setDate((int) date('Y'), (int) date('m'), 1);

        $isCurrentYear = (int) $dtCurrentMonth->format('m') >= $startMonth;

        $dt = (new DateTimeImmutable())
            ->setDate((int) date('Y'), $startMonth, 1)
            ->setTime(0, 0);

        if (!$isCurrentYear) {
            $dt = $dt->modify('-1 year');
        }

        if ($type === self::WHERE_TYPE_LAST_FISCAL_YEAR) {
            $dt = $dt->modify('-1 year');
        }

        $newList = [];

        for ($i = 0; $i < 12; $i++) {
            $newList[] = $dt->format('Y') . '-' . $dt->format('m');

            $dt = $dt->modify('+1 month');

            if ($dt > $dtCurrentMonth) {
                break;
            }
        }

        // Should not happen, but allows to catch wrong data making through.
        foreach ($list as $item) {
            if (!in_array($item, $newList)) {
                $newList[] = $item;
            }
        }

        $list = $newList;

        sort($list);
    }
}
