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

namespace Espo\Modules\Advanced\Tools\Report\ListType;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Report\GridType\Data as GridData;
use Espo\Modules\Advanced\Tools\Report\GridType\Helper as GridHelper;
use Espo\Modules\Advanced\Tools\Report\GridType\QueryPreparator as GridQueryPreparator;
use Espo\Modules\Advanced\Tools\Report\SelectHelper;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Selection;
use Espo\ORM\Query\SelectBuilder;

use RuntimeException;

class SubReportQueryPreparator
{

    public function __construct(
        private Metadata $metadata,
        private SelectHelper $selectHelper,
        private GridHelper $gridHelper,
        private SelectBuilderFactory $selectBuilderFactory,
        private GridQueryPreparator $gridQueryPreparator
    ) {}

    /**
     * A sub-report query preparator.
     *
     * Complex expression check is not applied for search parameters as it's supposed
     * to be checked the by runtime filter checker.
     *
     * @throws Forbidden
     * @throws BadRequest
     */
    public function prepare(
        GridData $data,
        SearchParams $searchParams,
        SubReportParams $subReportParams,
        ?User $user = null,
    ): SelectBuilder {

        $entityType = $data->getEntityType();

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from($data->getEntityType())
            ->withSearchParams($searchParams);

        if ($user) {
            $selectBuilder
                ->withWherePermissionCheck()
                ->forUser($user);
        }

        if ($user && $data->applyAcl()) {
            $selectBuilder->withAccessControlFilter();
        }

        $queryBuilder = $selectBuilder->buildQueryBuilder();

        $selectColumns = $queryBuilder->build()->getSelect();

        $this->gridHelper->checkColumnsAvailability($entityType, $data->getGroupBy());

        [$groupBy, $groupByOther] = $this->handleGroupBy($data, $subReportParams, $queryBuilder);

        // Prevent issue in ORM (not needed as of v7.5).
        $selectColumns = array_map(function (Selection $selection) {
            return !$selection->getAlias() ?
                $selection->getExpression() :
                $selection;
        }, $selectColumns);

        $queryBuilder
            ->from($data->getEntityType(), lcfirst($data->getEntityType()))
            ->select($selectColumns);

        if ($data->getFiltersWhere()) {
            [$whereItem,] = $this->selectHelper->splitHavingItem($data->getFiltersWhere());

            $this->selectHelper->handleFiltersWhere($whereItem, $queryBuilder);

            $this->handleHaving(
                data: $data,
                subReportParams: $subReportParams,
                where: $searchParams->getWhere(),
                user: $user,
                groupBy: $groupBy,
                groupByOther: $groupByOther,
                queryBuilder: $queryBuilder,
            );
        }

        if ($searchParams->getWhere()) {
            /** @noinspection PhpDeprecationInspection */
            $this->selectHelper->applyDistinctFromWhere($searchParams->getWhere(), $queryBuilder);
        }

        $this->applyGroupWhereAll(
            data: $data,
            subReportParams: $subReportParams,
            groupBy: $groupBy,
            groupByOther: $groupByOther,
            queryBuilder: $queryBuilder,
        );

        return $queryBuilder;
    }

    /**
     * @return array{?string, ?string}
     */
    private function handleGroupBy(
        GridData $data,
        SubReportParams $subReportParams,
        SelectBuilder $queryBuilder
    ): array {

        if (!$data->getGroupBy()) {
            return [null, null];
        }

        $groupIndex = $subReportParams->getGroupIndex();

        $this->selectHelper->handleGroupBy($data->getGroupBy(), $queryBuilder);

        $groupByExpressions = $queryBuilder->build()->getGroup();

        if (!isset($groupByExpressions[$groupIndex])) {
            throw new RuntimeException('No group by.');
        }

        $groupBy = $groupByExpressions[$groupIndex]->getValue();

        $queryBuilder->group([]);

        if (count($data->getGroupBy()) === 1) {
            return [$groupBy, null];
        }

        $groupBy1Type = $this->metadata
            ->get(['entityDefs', $data->getEntityType(), 'fields', $data->getGroupBy()[0], 'type']);

        if ($groupIndex === 1) {
            $groupByOther = $groupByExpressions[0]->getValue();

            if ($groupBy1Type === 'linkParent') {
                $groupBy = $groupByExpressions[2]->getValue();
            }

            return [$groupBy, $groupByOther];
        }

        $groupByOther = $groupBy1Type === 'linkParent' ?
            $groupByExpressions[2]->getValue() :
            $groupByExpressions[1]->getValue();

        return [$groupBy, $groupByOther];
    }

    private function applyGroupWhereAll(
        GridData $data,
        SubReportParams $subReportParams,
        ?string $groupBy,
        ?string $groupByOther,
        SelectBuilder $queryBuilder
    ): void {

        if ($groupBy !== null) {
            $this->applyGroupWhere($data, $subReportParams, $groupBy, $queryBuilder);
        }

        if (!$groupByOther) {
            return;
        }

        if (!$subReportParams->hasGroupValue2()) {
            return;
        }

        $this->applyGroup2Where(
            $data,
            $subReportParams,
            $groupByOther,
            $queryBuilder
        );
    }

    private function applyGroupWhere(
        GridData $data,
        SubReportParams $subReportParams,
        string $groupBy,
        SelectBuilder $queryBuilder
    ): void {

        $index = $subReportParams->getGroupIndex();
        $value = $subReportParams->getGroupValue();

        $this->applyGroupByWhereValue(
            $data,
            $index,
            $value,
            $groupBy,
            $queryBuilder
        );
    }

    private function applyGroup2Where(
        GridData $data,
        SubReportParams $subReportParams,
        string $groupBy,
        SelectBuilder $queryBuilder
    ): void {

        $value = $subReportParams->getGroupValue2();

        $this->applyGroupByWhereValue(
            $data,
            1,
            $value,
            $groupBy,
            $queryBuilder
        );
    }

    /**
     * @param ?scalar $value
     */
    private function applyGroupByWhereValue(
        GridData $data,
        int $index,
        $value,
        string $groupBy,
        SelectBuilder $queryBuilder
    ): void {

        $fieldType = $this->metadata
            ->get(['entityDefs', $data->getEntityType(), 'fields', $data->getGroupBy()[$index], 'type']);

        if ($fieldType === 'linkParent') {
            if ($value === null) {
                $queryBuilder->where([
                    $data->getGroupBy()[$index] . 'Id' => null,
                ]);

                return;
            }

            $arr = explode(':,:', (string) $value);

            $valueType = $arr[0];
            $valueId = null;

            if (count($arr)) {
                $valueId = $arr[1];
            }

            if (!$valueId) {
                $valueId = null;
            }

            $queryBuilder->where([
                $data->getGroupBy()[$index] . 'Type' => $valueType,
                $data->getGroupBy()[$index] . 'Id' => $valueId,
            ]);

            return;
        }

        if ($value === null) {
            $queryBuilder->where([
                'OR' => [
                    [$groupBy => null],
                ]
            ]);

            return;
        }

        $queryBuilder->where([$groupBy => $value]);
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     */
    private function handleHaving(
        GridData $data,
        SubReportParams $subReportParams,
        ?WhereItem $where,
        ?User $user,
        ?string $groupBy,
        ?string $groupByOther,
        SelectBuilder $queryBuilder
    ): void {

        [, $havingItem] = $this->selectHelper->splitHavingItem($data->getFiltersWhere());

        if ($havingItem->getItemList() === []) {
            return;
        }

        $gridQuery = $this->gridQueryPreparator->prepare($data, $where, $user);

        $subQueryBuilder = SelectBuilder::create()
            ->clone($gridQuery);

        $this->applyGroupWhereAll(
            $data,
            $subReportParams,
            $groupBy,
            $groupByOther,
            $subQueryBuilder
        );

        if (!method_exists(Cond::class, 'exists')) { /** @phpstan-ignore-line */
            return;
        }

        $queryBuilder->where(
            Cond::exists($subQueryBuilder->build())
        );
    }
}
