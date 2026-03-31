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

namespace Espo\Modules\Advanced\Core\Workflow;

use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\Exceptions\Error as FormulaException;

use stdClass;

class ConditionManager extends BaseManager
{
    protected string $dirName = 'Conditions';

    /** @var string[]  */
    protected array $requiredOptions = [
        'comparison',
        'fieldToCompare',
    ];

    /**
     * @param ?stdClass[] $all
     * @param ?stdClass[] $any
     * @throws Error
     * @throws FormulaException
     */
    public function check(
        ?array $all = null,
        ?array $any = null,
        ?string $formula = null
    ): bool {

        return $this->conditionManager->check($this->getEntity(), $all, $any, $formula);
    }

    /**
     * @param stdClass[] $conditions
     * @throws Error
     */
    public function checkConditionsAny(array $conditions): bool
    {
        return $this->conditionManager->checkConditionsAny($this->getEntity(), $conditions);
    }

    /**
     * @param stdClass[] $conditions
     * @throws Error
     */
    public function checkConditionsAll(array $conditions): bool
    {
        return $this->conditionManager->checkConditionsAll($this->getEntity(), $conditions);
    }

    /**
     * @param array<string, mixed> $variables Formula variables to pass.
     * @throws Error
     * @throws FormulaException
     */
    public function checkConditionsFormula(?string $formula, array $variables = []): bool
    {
        return $this->conditionManager->checkConditionsFormula($this->getEntity(), $formula, (object) $variables);
    }
}
