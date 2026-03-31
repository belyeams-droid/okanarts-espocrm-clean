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

use Espo\Modules\Advanced\Tools\Report\GridType\Data as GridData;
use stdClass;

class GridBuilder
{
    private const ROUND_PRECISION = 4;
    private const STUB_KEY = '__STUB__';

    public function __construct(
        private Util $util,
        private Helper $helper
    ) {}

    /**
     * @param array<string, mixed>[] $rows
     * @param string[] $groupList
     * @param string[] $columns
     * @param array<string, numeric> $sums
     * @param string[] $groups
     */
    public function build(
        Data $data,
        array $rows,
        array $groupList,
        array $columns,
        array &$sums,
        stdClass $cellValueMaps,
        array $groups = [],
        int $number = 0
    ): stdClass {

        $gridData = $this->buildInternal(
            $data,
            $rows,
            $groupList,
            $columns,
            $sums,
            $cellValueMaps,
            $groups,
            $number
        );

        foreach ($gridData as $k => $v) {
            $gridData[$k] = (object) $v;

            /** @var array<string, mixed> $v */

            foreach ($v as $k1 => $v1) {
                if (is_array($v1)) {
                    $gridData[$k]->$k1 = (object) $v1;
                }
            }
        }

        return (object) $gridData;
    }

    /**
     * @param array<string, mixed>[] $rows
     * @param string[] $groupList
     * @param string[] $columns
     * @param array<string, numeric> $sums
     * @param string[] $groups
     * @return array<string|int, array<string|int, mixed>|numeric>
     */
    public function buildInternal(
        Data $data,
        array $rows,
        array $groupList,
        array $columns,
        array &$sums,
        stdClass $cellValueMaps,
        array $groups,
        int $number
    ): array {

        $entityType = $data->getEntityType();

        if (count($data->getGroupBy()) === 0) {
            $groupList = [self::STUB_KEY];
        }

        $k = count($groups);

        $gridData = [];

        if ($k <= count($groupList) - 1) {
            $groupColumn = $groupList[$k];

            $keys = [];

            foreach ($rows as $row) {
                foreach ($groups as $i => $g) {
                    $groupAlias = $this->util->sanitizeSelectAlias($groupList[$i]);

                    if ($row[$groupAlias] !== $g) {
                        continue 2;
                    }
                }

                $groupAlias = $this->util->sanitizeSelectAlias($groupColumn);

                $key = $row[$groupAlias];

                if (!in_array($key, $keys)) {
                    $keys[] = $key;
                }
            }

            foreach ($keys as $number => $key) {
                $gr = $groups;
                $gr[] = $key;

                $gridData[$key] = $this->buildInternal(
                    $data,
                    $rows,
                    $groupList,
                    $columns,
                    $sums,
                    $cellValueMaps,
                    $gr,
                    $number + 1
                );
            }

            return $gridData;
        }

        $s = &$sums;

        for ($i = 0; $i < count($groups) - 1; $i++) {
            /** @var array<string, mixed> $s */

            $group = $groups[$i];

            if (!array_key_exists($group, $s)) {
                $s[$group] = [];
            }

            $s = &$s[$group];
        }

        foreach ($rows as $row) {
            foreach ($groups as $i => $g) {
                $groupAlias = $this->util->sanitizeSelectAlias($groupList[$i]);

                if ($row[$groupAlias] != $g) {
                    continue 2;
                }
            }

            foreach ($columns as $column) {
                $selectAlias = $this->util->sanitizeSelectAlias($column);

                if ($this->helper->isColumnNumeric($column, $data)) {
                    if (empty($s[$column])) {
                        $s[$column] = 0;

                        if (str_starts_with($column, 'MIN:')) {
                            $s[$column] = null;
                        }
                        else if (str_starts_with($column, 'MAX:')) {
                            $s[$column] = null;
                        }
                    }

                    $value = str_starts_with($column, 'COUNT:') ?
                        intval($row[$selectAlias]) :
                        floatval($row[$selectAlias]);

                    if (str_starts_with($column, 'MIN:')) {
                        if (is_null($s[$column]) || $s[$column] >= $value) {
                            $s[$column] = $value;
                        }
                    }
                    else if (str_starts_with($column, 'MAX:')) {
                        if (is_null($s[$column]) || $s[$column] < $value) {
                            $s[$column] = $value;
                        }
                    }
                    else if (str_starts_with($column, 'AVG:')) {
                        $s[$column] = $s[$column] + ($value - $s[$column]) / floatval($number);
                    }
                    else {
                        $s[$column] = $s[$column] + $value;
                    }

                    if (is_float($s[$column])) {
                        $s[$column] = round($s[$column], self::ROUND_PRECISION);
                    }

                    $gridData[$column] = $value;

                    continue;
                }

                $columnData = $this->helper->getDataFromColumnName($entityType, $column);

                if (!property_exists($cellValueMaps, $column)) {
                    $cellValueMaps->$column = (object) [];
                }

                $fieldType = $columnData->fieldType;

                $value = null;

                if (array_key_exists($selectAlias, $row)) {
                    $value = $row[$selectAlias];
                }

                if ($fieldType === 'link') {
                    $selectAlias = $this->util->sanitizeSelectAlias($column . 'Id');

                    $value = $row[$selectAlias];
                }

                $gridData[$column] = $value;

                if (!is_null($value) && !property_exists($cellValueMaps->$column, $value)) {
                    $displayValue = $this->util->getCellDisplayValue($value, $columnData);

                    if (!is_null($displayValue)) {
                        $cellValueMaps->$column->$value = $displayValue;
                    }
                }
            }
        }

        return $gridData;
    }

    /**
     * @param string[] $columnList
     * @param string[] $summaryColumnList
     * @param array<string, array<string, mixed>> $rows
     * @param string[] $groupList
     */
    public function buildNonSummary(
        array $columnList,
        array $summaryColumnList,
        GridData $data,
        array $rows,
        array $groupList,
        stdClass $cellValueMaps,
        stdClass $nonSummaryColumnGroupMap
    ): ?stdClass {

        if (count($data->getGroupBy()) !== 2) {
            return null;
        }

        if (count($columnList) <= count($summaryColumnList)) {
            return (object) [];
        }

        $nonSummaryData = (object) [];

        foreach ($data->getGroupBy() as $i => $groupColumn) {
            $nonSummaryData->$groupColumn = (object) [];

            $groupAlias = $this->util->sanitizeSelectAlias($groupList[$i]);

            foreach ($columnList as $column) {
                if (in_array($column, $summaryColumnList)) {
                    continue;
                }

                if (!str_starts_with($column, $groupColumn . '.')) {
                    continue;
                }

                $nonSummaryColumnGroupMap->$column = $groupColumn;

                $columnData = $this->helper->getDataFromColumnName($data->getEntityType(), $column);

                $columnKey = $column;

                if ($columnData->fieldType === 'link') {
                    $columnKey .= 'Id';
                }

                $columnAlias = $this->util->sanitizeSelectAlias($columnKey);

                foreach ($rows as $row) {
                    $groupValue = $row[$groupAlias];

                    if (!property_exists($nonSummaryData->$groupColumn, $groupValue)) {
                        $nonSummaryData->$groupColumn->$groupValue = (object) [];
                    }

                    $value = $row[$columnAlias] ?? null;

                    if (is_null($value)) {
                        continue;
                    }

                    $nonSummaryData->$groupColumn->$groupValue->$column = $value;

                    if (!property_exists($cellValueMaps, $column)) {
                        $cellValueMaps->$column = (object) [];
                    }

                    if (!property_exists($cellValueMaps->$column, $value)) {
                        $cellValueMaps->$column->$value = $this->util->getCellDisplayValue($value, $columnData);
                    }
                }
            }
        }

        return $nonSummaryData;
    }
}
