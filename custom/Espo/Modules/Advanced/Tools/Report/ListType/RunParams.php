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

class RunParams
{
    private bool $skipRuntimeFiltersCheck = false;
    private bool $returnSthCollection = false;
    private bool $isExport = false;
    private bool $fullSelect = false;
    /** @var ?string[] */
    private ?array $customColumnList = null;

    private function __construct() {}

    public function skipRuntimeFiltersCheck(): bool
    {
        return $this->skipRuntimeFiltersCheck;
    }

    public function withSkipRuntimeFiltersCheck(bool $value = true): self
    {
        $obj = clone $this;
        $obj->skipRuntimeFiltersCheck = $value;

        return $obj;
    }

    public static function create(): self
    {
        return new self();
    }

    public function withReturnSthCollection(bool $value = true): self
    {
        $obj = clone $this;
        $obj->returnSthCollection = $value;

        return $obj;
    }

    public function withIsExport(bool $value = true): self
    {
        $obj = clone $this;
        $obj->isExport = $value;

        return $obj;
    }

    public function withFullSelect(bool $value = true): self
    {
        $obj = clone $this;
        $obj->fullSelect = $value;

        return $obj;
    }

    /**
     * @param ?string[] $value
     */
    public function withCustomColumnList(?array $value): self
    {
        $obj = clone $this;
        $obj->customColumnList = $value;

        return $obj;
    }

    public function returnSthCollection(): bool
    {
        return $this->returnSthCollection;
    }

    public function isExport(): bool
    {
        return $this->isExport;
    }

    public function isFullSelect(): bool
    {
        return $this->fullSelect;
    }

    /**
     * @return ?string[]
     */
    public function getCustomColumnList(): ?array
    {
        return $this->customColumnList;
    }
}
