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

namespace Espo\Modules\Advanced\Core\Workflow\Conditions;

use DateTime;
use Espo\Core\Exceptions\Error;
use Exception;
use RuntimeException;

class After extends Base
{
    /**
     * @throws Error
     */
    protected function compare($fieldValue): bool
    {
        $subjectValue = $this->getSubjectValue();

        try {
            $fieldDate = new DateTime($fieldValue);
            $subjectDate = new DateTime($subjectValue);
        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return $fieldDate > $subjectDate;
    }
}
