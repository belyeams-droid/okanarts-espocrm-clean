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

class ColumnData
{
    public ?string $function;
    public string $field;
    public ?string $entityType;
    public ?string $link;
    public ?string $fieldType;

    public function __construct(
        ?string $function,
        string $field,
        ?string $entityType,
        ?string $link,
        ?string $fieldType
    ) {
        $this->function = $function;
        $this->field = $field;
        $this->entityType = $entityType;
        $this->link = $link;
        $this->fieldType = $fieldType;
    }
}
