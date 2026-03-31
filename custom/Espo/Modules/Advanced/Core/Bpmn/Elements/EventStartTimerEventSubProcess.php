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
use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\ORM\Entity;

/**
 * @noinspection PhpUnused
 */
class EventStartTimerEventSubProcess extends EventIntermediateTimerCatch
{
    /** @var string */
    protected $pendingStatus = BpmnFlowNode::STATUS_STANDBY;

    /**
     * @throws FormulaError
     * @throws Error
     */
    protected function getConditionsTarget(): ?Entity
    {
        return $this->getSpecificTarget($this->getFlowNode()->getDataItemValue('subProcessTarget'));
    }

    /**
     * @param string|bool|null $divergentFlowNodeId
     * @throws Error
     */
    protected function processNextElement(
        ?string $nextElementId = null,
        $divergentFlowNodeId = false,
        bool $dontSetProcessed = false
    ): ?BpmnFlowNode {

        return parent::processNextElement($this->getFlowNode()->getDataItemValue('subProcessElementId'));
    }

    public function proceedPending(): void
    {
        $flowNode = $this->getFlowNode();

        $flowNode->setStatus(BpmnFlowNode::STATUS_IN_PROCESS);

        $this->getEntityManager()->saveEntity($flowNode);

        $subProcessIsInterrupting = $this->getFlowNode()->getDataItemValue('subProcessIsInterrupting');

        if (!$subProcessIsInterrupting) {
            $standbyFlowNode = $this->getManager()->prepareStandbyFlow(
                $this->getTarget(),
                $this->getProcess(),
                $this->getFlowNode()->getDataItemValue('subProcessElementId')
            );

            if ($standbyFlowNode) {
                $this->getManager()->processPreparedFlowNode(
                    $this->getTarget(),
                    $standbyFlowNode,
                    $this->getProcess()
                );
            }
        }

        if ($subProcessIsInterrupting) {
            $this->getManager()->interruptProcessByEventSubProcess($this->getProcess(), $this->getFlowNode());
        }

        $this->processNextElement();
    }
}
