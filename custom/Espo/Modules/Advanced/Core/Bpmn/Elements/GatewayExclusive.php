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

namespace Espo\Modules\Advanced\Core\Bpmn\Elements;

use Espo\Core\Exceptions\Error as FormulaError;
use Espo\Core\Formula\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Modules\Advanced\Core\Bpmn\Utils\ConditionManager;

/**
 * @noinspection PhpUnused
 */
class GatewayExclusive extends Gateway
{
    /**
     * @throws Error
     * @throws FormulaError
     */
    protected function processDivergent(): void
    {
        $conditionManager = $this->getConditionManager();

        $flowList = $this->getAttributeValue('flowList');

        if (!is_array($flowList)) {
            $flowList = [];
        }

        $defaultNextElementId = $this->getAttributeValue('defaultNextElementId');
        $nextElementId = null;

        foreach ($flowList as $flowData) {
            $conditionsAll = $flowData->conditionsAll ?? null;
            $conditionsAny = $flowData->conditionsAny ?? null;
            $conditionsFormula = $flowData->conditionsFormula ?? null;

            $result = $conditionManager->check(
                $this->getTarget(),
                $conditionsAll,
                $conditionsAny,
                $conditionsFormula,
                $this->getVariablesForFormula()
            );

            if ($result) {
                $nextElementId = $flowData->elementId;

                break;
            }
        }

        if (!$nextElementId && $defaultNextElementId) {
            $nextElementId = $defaultNextElementId;
        }

        if ($nextElementId) {
            $this->processNextElement($nextElementId);

            return;
        }

        $this->endProcessFlow();
    }

    /**
     * @throws FormulaError
     */
    protected function processConvergent(): void
    {
        $this->processNextElement();
    }

    protected function getConditionManager(): ConditionManager
    {
        $conditionManager = $this->getContainer()
            ->getByClass(InjectableFactory::class)
            ->create(ConditionManager::class);

        $conditionManager->setCreatedEntitiesData($this->getCreatedEntitiesData());

        return $conditionManager;
    }
}
