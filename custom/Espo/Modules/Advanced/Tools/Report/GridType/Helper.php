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

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Advanced\Tools\Report\GridType\Result as GridResult;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\QueryComposer\Util as QueryComposerUtil;

class Helper
{
    /** @var string[] */
    private array $numericFieldTypeList = [
        'currency',
        'currencyConverted',
        'int',
        'float',
        'enumInt',
        'enumFloat',
        'duration',
        'decimal',
    ];

    public function __construct(
        private Metadata $metadata,
        private AclManager $aclManager,
        private EntityManager $entityManager
    ) {}

    public function getDataFromColumnName(string $entityType, string $column, ?GridResult $result = null): ColumnData
    {
        if ($result && $result->isJoint()) {
            $entityType = $result->getColumnEntityTypeMap()[$column];
            $column = $result->getColumnOriginalMap()[$column];
        }

        $field = $column;
        $link = null;
        $function = null;

        if (str_contains($field, ':')) {
            [$function, $field] = explode(':', $field, 2);
        }

        if (str_contains($field, ':') || str_contains($field, '(') || substr_count($field, '.') > 2) {
            if (substr_count($field, '.') === 1 && !str_contains($field, ',')) {
                $attrs = QueryComposerUtil::getAllAttributesFromComplexExpression($column);

                if (count($attrs) === 1) {
                    $attr = $attrs[0];
                    [$link, $field] = explode('.', $attr);

                    return new ColumnData(
                        function: $function,
                        field: $field,
                        entityType: null,
                        link: $link,
                        fieldType: null,
                    );
                }
            }

            return new ColumnData(
                function: $function,
                field: '',
                entityType: null,
                link: null,
                fieldType: null,
            );
        }

        $fieldEntityType = $entityType;

        if (str_contains($field, '.')) {
            [$link, $field] = explode('.', $field, 2);

            $fieldEntityType = $this->metadata->get(['entityDefs', $entityType, 'links', $link, 'entity']);
        }

        $fieldType = $this->metadata->get(['entityDefs', $fieldEntityType, 'fields', $field, 'type']);

        return new ColumnData(
            function: $function,
            field: $field,
            entityType: $fieldEntityType,
            link: $link,
            fieldType: $fieldType,
        );
    }

    /**
     * @param Data|Result $data
     */
    public function isColumnNumeric(string $item, $data): bool
    {
        if ($data instanceof Result) {
            if (in_array($item, $data->getNumericColumnList())) {
                return true;
            }
        }
        else if ($data instanceof Data) {
            $type = $data->getColumnType($item);

            if ($type !== null) {
                return $type == Data::COLUMN_TYPE_SUMMARY;
            }
        }

        $columnData = $this->getDataFromColumnName($data->getEntityType(), $item);

        if (in_array($columnData->function, ['COUNT', 'SUM', 'AVG'])) {
            return true;
        }

        if (in_array($columnData->fieldType, $this->numericFieldTypeList)) {
            return true;
        }

        return false;
    }

    public function isColumnEligibleForSubList(string $item, Data $data): bool
    {
        $groupBy = $data->getGroupBy()[0] ?? null;

        $columnData = $this->getDataFromColumnName($data->getEntityType(), $item);

        if (!str_contains($item, '.')) {
            return true;
        }

        if (!$groupBy) {
            return true;
        }

        if ($columnData->link === $groupBy) {
            return false;
        }

        return true;
    }

    public function isColumnSummary(string $item, Data $data): bool
    {
        $type = $data->getColumnType($item);

        if ($type !== null) {
            return $type === Data::COLUMN_TYPE_SUMMARY;
        }

        $function = null;

        if (strpos($item, ':') > 0) {
            [$function] = explode(':', $item);
        }

        if (in_array($function, ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'])) {
            return true;
        }

        return false;
    }

    public function isColumnDateFunction(string $column): bool
    {
        $list = [
            'MONTH:',
            'YEAR:',
            'DAY:',
            'MONTH:',
            'YEAR:',
            'DAY:',
            'QUARTER:',
            'QUARTER_',
            'WEEK_0:',
            'WEEK_1:',
            'YEAR_',
            'QUARTER_FISCAL:',
            'YEAR_FISCAL:',
        ];

        foreach ($list as $item) {
            if (str_starts_with($column, $item)) {
                return true;
            }
        }

        return false;
    }

    public function isColumnSubListAggregated(string $item): bool
    {
        if (!str_contains($item, ':')) {
            return false;
        }

        if (str_contains($item, ',')) {
            return false;
        }

        if (str_contains($item, '.')) {
            return false;
        }

        if (str_contains($item, '(')) {
            return false;
        }

        $function = explode(':', $item)[0];

        if ($function === 'COUNT') {
            return false;
        }

        if (in_array($function, ['SUM', 'MAX', 'MIN', 'AVG'])) {
            return true;
        }

        return false;
    }

    /**
     * @param string[] $itemList
     * @throws Forbidden
     */
    public function checkColumnsAvailability(string $entityType, array $itemList): void
    {
        foreach ($itemList as $item) {
            $this->checkColumnAvailability($entityType, $item);
        }
    }

    /**
     * @throws Forbidden
     */
    private function checkColumnAvailability(string $entityType, string $item): void
    {
        if (str_contains($item, ':')) {
            $argumentList = QueryComposerUtil::getAllAttributesFromComplexExpression($item);

            foreach ($argumentList as $argument) {
                $this->checkColumnAvailability($entityType, $argument);
            }

            return;
        }

        $field = $item;

        if (str_contains($field, '.')) {
            [$link, $field] = explode('.', $field);

            $entityType = $this->metadata->get(['entityDefs', $entityType, 'links', $link,  'entity']);

            if (!$entityType) {
                return;
            }
        }

        if (
            in_array($field, $this->aclManager->getScopeRestrictedFieldList($entityType, 'onlyAdmin')) ||
            in_array($field, $this->aclManager->getScopeRestrictedFieldList($entityType, 'internal')) ||
            in_array($field, $this->aclManager->getScopeRestrictedFieldList($entityType, 'forbidden'))
        ) {
            throw new Forbidden;
        }
    }

    /**
     * @todo Check whether it's working.
     * @return string[]
     */
    public function obtainLinkColumnList(Data $data): array
    {
        $list = [];

        foreach ($data->getGroupBy() as $item) {
            $columnData = $this->getDataFromColumnName($data->getEntityType(), $item);

            if ($columnData->function) {
                continue;
            }

            if (!$columnData->link) {
                if (in_array($columnData->fieldType, ['link', 'file', 'image'])) {
                    $list[] = $item;
                }

                continue;
            }

            $entityDefs = $this->entityManager
                ->getDefs()
                ->getEntity($data->getEntityType());

            if (!$entityDefs->hasRelation($columnData->link)) {
                continue;
            }

            $relationType = $entityDefs
                ->getRelation($columnData->link)
                ->getType();

            if (
                (
                    $relationType === Entity::BELONGS_TO ||
                    $relationType === Entity::HAS_ONE
                ) &&
                in_array($columnData->fieldType, ['link', 'file', 'image'])
            ) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * @param string[] $columns
     * @return string[]
     */
    public function obtainLinkColumnListFromColumns(Data $data, array $columns): array
    {
        $typeList = [
            'link',
            'file',
            'image',
            'linkOne',
            'linkParent',
        ];

        $list = [];

        foreach ($columns as $item) {
            $columnData = $this->getDataFromColumnName($data->getEntityType(), $item);

            if ($columnData->function || $columnData->link) {
                continue;
            }

            if (in_array($columnData->fieldType, $typeList)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
