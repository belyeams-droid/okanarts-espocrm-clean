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
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Modules\Advanced\Entities\BpmnProcess;

/**
 * @noinspection PhpUnused
 */
class GatewayInclusive extends Gateway
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

        $nextElementIdList = [];

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
                $nextElementIdList[] = $flowData->elementId;
            }
        }

        //$isDefaultFlow = false;

        if (!count($nextElementIdList) && $defaultNextElementId) {
            //$isDefaultFlow = true;

            $nextElementIdList[] = $defaultNextElementId;
        }

        $flowNode = $this->getFlowNode();

        $nextDivergentFlowNodeId = $flowNode->getId();

        if (count($nextElementIdList)) {
            $flowNode->setStatus(BpmnFlowNode::STATUS_IN_PROCESS);
            $this->getEntityManager()->saveEntity($flowNode);

            $nextFlowNodeList = [];

            foreach ($nextElementIdList as $nextElementId) {
                $nextFlowNode = $this->prepareNextFlowNode($nextElementId, $nextDivergentFlowNodeId);

                if ($nextFlowNode) {
                    $nextFlowNodeList[] = $nextFlowNode;
                }
            }

            $this->setProcessed();

            foreach ($nextFlowNodeList as $nextFlowNode) {
                if ($this->getProcess()->getStatus() !== BpmnProcess::STATUS_STARTED) {
                    break;
                }

                $this->getManager()->processPreparedFlowNode($this->getTarget(), $nextFlowNode, $this->getProcess());
            }

            $this->getManager()->tryToEndProcess($this->getProcess());

            return;
        }

        $this->endProcessFlow();
    }

    /**
     * @throws FormulaError
     */
    protected function processConvergent(): void
    {
        $flowNode = $this->getFlowNode();

        $item = $flowNode->getElementData();
        $previousElementIdList = $item->previousElementIdList;

        $nextDivergentFlowNodeId = null;
        $divergentFlowNode = null;

        $convergingFlowCount = 1;

        if ($flowNode->getDivergentFlowNodeId()) {
            /** @var ?BpmnFlowNode $divergentFlowNode */
            $divergentFlowNode = $this->getEntityManager()
                ->getEntityById(BpmnFlowNode::ENTITY_TYPE, $flowNode->getDivergentFlowNodeId());

            if ($divergentFlowNode) {
                $nextDivergentFlowNodeId = $divergentFlowNode->getDivergentFlowNodeId();

                /** @var iterable<BpmnFlowNode> $forkFlowNodeList */
                $forkFlowNodeList = $this->getEntityManager()
                    ->getRDBRepository(BpmnFlowNode::ENTITY_TYPE)
                    ->where([
                        'processId' => $flowNode->getProcessId(),
                        'previousFlowNodeId' => $divergentFlowNode->getId(),
                    ])
                    ->find();

                $convergingFlowCount = 0;

                foreach ($previousElementIdList as $previousElementId) {
                    $isActual = false;

                    foreach ($forkFlowNodeList as $forkFlowNode) {
                        if (
                            $this->checkElementsBelongSingleFlow(
                                $divergentFlowNode->getElementId(),
                                $forkFlowNode->getElementId(),
                                $previousElementId
                            )
                        ) {
                            $isActual = true;

                            break;
                        }
                    }

                    if ($isActual) {
                        $convergingFlowCount++;
                    }
                }
            }
        }

        $concurrentFlowNodeList = $this->getEntityManager()
            ->getRDBRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where([
                'elementId' => $flowNode->getElementId(),
                'processId' => $flowNode->getProcessId(),
                'divergentFlowNodeId' => $flowNode->getDivergentFlowNodeId(),
            ])
            ->find();

        $concurrentCount = count(iterator_to_array($concurrentFlowNodeList));

        if ($concurrentCount < $convergingFlowCount) {
            $this->setRejected();

            return;
        }

        $isBalancingDivergent = true;

        if ($divergentFlowNode) {
            $divergentElementData = $divergentFlowNode->getElementData();

            if (isset($divergentElementData->nextElementIdList)) {
                foreach ($divergentElementData->nextElementIdList as $forkId) {
                    if (
                        !$this->checkElementsBelongSingleFlow(
                            $divergentFlowNode->getElementId(),
                            $forkId,
                            $flowNode->getElementId()
                        )
                    ) {
                        $isBalancingDivergent = false;

                        break;
                    }
                }
            }
        }

        if ($isBalancingDivergent) {
            if ($divergentFlowNode) {
                $nextDivergentFlowNodeId = $divergentFlowNode->getDivergentFlowNodeId();
            }

            $this->processNextElement(null, $nextDivergentFlowNodeId);

            return;
        }

        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $this->processNextElement(null, false);
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
