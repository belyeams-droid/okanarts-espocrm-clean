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

use Espo\ORM\Collection;
use Espo\ORM\Entity;
use stdClass;

class Result
{
    /** @var Collection<Entity>  */
    private Collection $collection;
    private int $total;
    /** @var ?string[] */
    private ?array $columns;
    private ?stdClass $columnsData;

    /**
     * @param Collection<Entity> $collection
     * @param ?string[] $columns
     */
    public function __construct(
        Collection $collection,
        int $total,
        ?array $columns = null,
        ?stdClass $columnsData = null
    ) {
        $this->collection = $collection;
        $this->total = $total;
        $this->columns = $columns;
        $this->columnsData = $columnsData;
    }

    /**
     * @return Collection<Entity>
     */
    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * @return ?string[]
     */
    public function getColumns(): ?array
    {
        return $this->columns;
    }

    public function getColumnsData(): ?stdClass
    {
        return $this->columnsData;
    }
}
