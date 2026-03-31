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

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Report\SelectHelper;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;

class QueryPreparator
{
    private const WHERE_TYPE_AND = 'and';

    public function __construct(
        private SelectHelper $selectHelper,
        private SelectBuilderFactory $selectBuilderFactory,
        private Helper $helper
    ) {}

    /**
     * @throws Forbidden
     * @throws BadRequest
     */
    public function prepare(Data $data, ?WhereItem $where, ?User $user): Select
    {
        [$whereItem, $havingItem] = $this->obtainWhereAndHavingItems($data);

        $queryBuilder = SelectBuilder::create()
            ->from($data->getEntityType(), lcfirst($data->getEntityType()));

        $this->selectHelper->handleGroupBy($data->getGroupBy(), $queryBuilder);
        $this->selectHelper->handleColumns($data->getAggregatedColumns(), $queryBuilder);
        $this->selectHelper->handleOrderBy($data->getOrderBy(), $queryBuilder);
        $this->selectHelper->handleFiltersHaving($havingItem, $queryBuilder, true);

        $preFilterQuery = $queryBuilder->build();

        $queryBuilder = $this->cloneWithAccessControlAndWhere($data, $where, $user, $preFilterQuery);

        $this->selectHelper->handleFiltersWhere($whereItem, $queryBuilder/*, true*/);
        $this->handleAdditional($queryBuilder);

        if (!$this->useSubQuery($queryBuilder)) {
            return $queryBuilder->build();
        }

        // @todo Remove when v8.5 is min. supported.
        $subQuery = $queryBuilder
            ->select(['id'])
            ->group([])
            ->order([])
            ->having([])
            ->build();

        return SelectBuilder::create()
            ->clone($preFilterQuery)
            ->where(
                Cond::in(Expr::column('id'), $subQuery)
            )
            ->build();
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     */
    private function cloneWithAccessControlAndWhere(
        Data $data,
        ?WhereItem $where,
        ?User $user,
        Select $preFilterQuery
    ): SelectBuilder  {

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->clone($preFilterQuery);

        if ($user) {
            $selectBuilder
                ->forUser($user)
                ->withWherePermissionCheck();
        }

        if ($user && $data->applyAcl()) {
            $selectBuilder->withAccessControlFilter();
        }

        // @todo Revise.
        $selectBuilder->buildQueryBuilder();

        if ($where) {
            $selectBuilder->withWhere($where);
        }

        /** @noinspection PhpUnnecessaryLocalVariableInspection */
        $queryBuilder = $selectBuilder->buildQueryBuilder();

        /*if ($where) {
            // Supposed to be already applied by the scanner.
            $this->selectHelper->applyLeftJoinsFromWhere($where, $queryBuilder);
        }*/

        return $queryBuilder;
    }

    /**
     * @param Data $data
     * @return array{0: WhereItem, 1: WhereItem}
     */
    private function obtainWhereAndHavingItems(Data $data): array
    {
        return $data->getFiltersWhere() ?
            $this->selectHelper->splitHavingItem($data->getFiltersWhere()) :
            [
                WhereItem::createBuilder()
                    ->setType(self::WHERE_TYPE_AND)
                    ->setItemList([])
                    ->build(),
                WhereItem::createBuilder()
                    ->setType(self::WHERE_TYPE_AND)
                    ->setItemList([])
                    ->build()
            ];
    }

    private function useSubQuery(SelectBuilder $queryBuilder): bool
    {
        $isDistinct = $queryBuilder->build()->isDistinct();

        if (!$isDistinct) {
            return false;
        }

        foreach ($queryBuilder->build()->getSelect() as $selectItem) {
            $itemExpr = $selectItem->getExpression()->getValue();

            if (
                str_starts_with($itemExpr, 'SUM:') ||
                str_starts_with($itemExpr, 'AVG:')
            ) {
                return true;
            }
        }

        return false;
    }

    private function handleAdditional(SelectBuilder $queryBuilder): void
    {
        foreach ($queryBuilder->build()->getGroup() as $groupBy) {
            $groupColumn = $groupBy->getValue();

            if ($this->helper->isColumnDateFunction($groupColumn)) {
                $queryBuilder->where(["$groupColumn!=" => null]);
            }
        }
    }
}
