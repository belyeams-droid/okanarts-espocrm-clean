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

use Espo\Core\Select\Where\Item as WhereItem;

use stdClass;

class Data
{
    /** @var string[] */
    private array $columns;
    /** @var ?string */
    private ?string $orderBy;

    /**
     * @param string[] $columns
     */
    public function __construct(
        private string $entityType,
        array $columns,
        ?string $orderBy,
        private ?stdClass $columnsData,
        private ?WhereItem $filtersWhere
    ) {
        $this->columns = $columns;
        $this->orderBy = $orderBy;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    /**
     * @return string[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getOrderBy(): ?string
    {
        return $this->orderBy;
    }

    public function getColumnsData(): ?stdClass
    {
        return $this->columnsData;
    }

    /**
     * @param string[] $columns
     */
    public function withColumns(array $columns): self
    {
        $obj = clone $this;
        $obj->columns = $columns;

        return $obj;
    }

    public function getFiltersWhere(): ?WhereItem
    {
        return $this->filtersWhere;
    }
}
