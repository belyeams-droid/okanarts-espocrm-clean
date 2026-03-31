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

namespace Espo\Modules\Advanced\Core\Workflow\Formula\Functions\BpmGroup;

use Espo\Core\Di\EntityManagerAware;
use Espo\Core\Di\EntityManagerSetter;
use Espo\Core\Di\InjectableFactoryAware;
use Espo\Core\Di\InjectableFactorySetter;
use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\ArgumentList;
use Espo\Core\Formula\Functions\BaseFunction;
use Espo\Modules\Advanced\Core\Bpmn\BpmnManager;
use Espo\Modules\Advanced\Entities\BpmnFlowchart;

class StartProcessType extends BaseFunction implements EntityManagerAware, InjectableFactoryAware
{
    use EntityManagerSetter;
    use InjectableFactorySetter;

    /**
     * @inheritDoc
     * @throws Error
     */
    public function process(ArgumentList $args)
    {
        $args = $this->evaluate($args);

        $flowchartId = $args[0] ?? null;
        $targetType = $args[1] ?? null;
        $targetId = $args[2] ?? null;
        $elementId = $args[3] ?? null;

        if (!$flowchartId || !$targetType || !$targetId)
            throw new Error("Formula: bpm\\startProcess: Too few arguments.");

        if (!is_string($flowchartId) || !is_string($targetType) || !is_string($targetId))
            throw new Error("Formula: bpm\\startProcess: Bad arguments.");

        /** @var ?BpmnFlowchart $flowchart */
        $flowchart = $this->entityManager->getEntityById(BpmnFlowchart::ENTITY_TYPE, $flowchartId);
        $target = $this->entityManager->getEntityById($targetType, $targetId);

        if (!$flowchart) {
            $GLOBALS['log']->notice("Formula: bpm\\startProcess: Flowchart '$flowchartId' not found.");

            return null;
        }

        if (!$target) {
            $GLOBALS['log']->notice("Formula: bpm\\startProcess: Target $targetType '$targetId' not found.");

            return null;
        }

        if ($flowchart->getTargetType() !== $targetType) {
            throw new Error("Formula: bpm\\startProcess: Target entity type doesn't match flowchart target type.");
        }

        $bpmnManager = $this->injectableFactory->create(BpmnManager::class);

        $bpmnManager->startProcess($target, $flowchart, $elementId);

        return true;
    }
}
