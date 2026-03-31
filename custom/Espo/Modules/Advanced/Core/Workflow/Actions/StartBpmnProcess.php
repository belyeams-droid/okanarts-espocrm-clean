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

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\Advanced\Entities\BpmnFlowchart;
use Espo\ORM\Entity;
use Espo\Modules\Advanced\Core\Bpmn\BpmnManager;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class StartBpmnProcess extends Base
{
    /**
     * @throws Error
     */
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        $target = $actionData->target ?? null;
        $flowchartId = $actionData->flowchartId ?? null;
        $elementId = $actionData->elementId;

        if (!$flowchartId || !$elementId) {
            throw new Error('Workflow StartBpmnProcess: Not flowchart data.');
        }

        $targetEntity = $this->getFirstTargetFromTargetItem($entity, $target);

        if (!$targetEntity) {
            $GLOBALS['log']->notice('Workflow StartBpmnProcess: No target.');

            return false;
        }

        if (
            $targetEntity->getEntityType() === $entity->getEntityType() &&
            $targetEntity->getId() === $entity->getId()
        ) {
            $targetEntity = $entity;
        }

        $flowchart = $this->getFlowchart($flowchartId, $targetEntity);

        $bpmnManager = $this->injectableFactory->create(BpmnManager::class);

        $bpmnManager->startProcess(
            $targetEntity,
            $flowchart,
            $elementId,
            null,
            $this->getWorkflowId(),
            $this->getSignalParams()
        );

        return true;
    }

    /**
     * @throws Error
     */
    private function getFlowchart(string $flowchartId, Entity $targetEntity): BpmnFlowchart
    {
        /** @var ?BpmnFlowchart $flowchart */
        $flowchart = $this->entityManager->getEntityById(BpmnFlowchart::ENTITY_TYPE, $flowchartId);

        if (!$flowchart) {
            throw new Error("StartBpmnProcess: Could not find flowchart $flowchartId.");
        }

        if ($flowchart->getTargetType() !== $targetEntity->getEntityType()) {
            throw new Error("Workflow StartBpmnProcess: Target entity type doesn't match flowchart target type.");
        }

        return $flowchart;
    }

    private function getSignalParams(): ?stdClass
    {
        $signalParams = $this->getVariables()->__signalParams ?? null;

        if (!$signalParams instanceof stdClass) {
            $signalParams = null;
        }

        if ($signalParams) {
            $signalParams = clone $signalParams;
        }

        return $signalParams;
    }
}
