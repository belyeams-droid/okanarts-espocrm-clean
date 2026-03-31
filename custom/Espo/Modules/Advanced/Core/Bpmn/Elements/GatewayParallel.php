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

use Espo\Core\Exceptions\Error;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Modules\Advanced\Entities\BpmnProcess;

/**
 * @noinspection PhpUnused
 */
class GatewayParallel extends Gateway
{
    /**
     * @throws Error
     */
    protected function processDivergent(): void
    {
        $flowNode = $this->getFlowNode();

        $item = $flowNode->getElementData();

        $nextElementIdList = $item->nextElementIdList ?? [];

        if (count($nextElementIdList)) {
            $flowNode->setStatus(BpmnFlowNode::STATUS_IN_PROCESS);
            $this->getEntityManager()->saveEntity($flowNode);

            $nextFlowNodeList = [];

            foreach ($nextElementIdList as $nextElementId) {
                $nextFlowNode = $this->prepareNextFlowNode($nextElementId, $flowNode->getId());

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
     * @throws Error
     */
    protected function processConvergent(): void
    {
        $flowNode = $this->getFlowNode();

        $item = $flowNode->getElementData();

        $previousElementIdList = $item->previousElementIdList;
        $convergingFlowCount = count($previousElementIdList);

        //$nextDivergentFlowNodeId = null;
        $divergentFlowNode = null;

        //$divergedFlowCount = 1;

        if ($flowNode->getDivergentFlowNodeId()) {
            /** @var ?BpmnFlowNode $divergentFlowNode */
            $divergentFlowNode = $this->getEntityManager()
                ->getEntityById(BpmnFlowNode::ENTITY_TYPE, $flowNode->getDivergentFlowNodeId());

            /*if ($divergentFlowNode) {
                $divergentElementData = $divergentFlowNode->getElementData();

                $divergedFlowCount = count($divergentElementData->nextElementIdList ?? []);
            }*/
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
            $nextDivergentFlowNodeId = $divergentFlowNode?->getDivergentFlowNodeId();

            $this->processNextElement(null, $nextDivergentFlowNodeId);

            return;
        }

        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $this->processNextElement(null, false);
    }
}
