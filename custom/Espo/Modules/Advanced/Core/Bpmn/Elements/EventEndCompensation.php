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

class EventEndCompensation extends Base
{
    public function process(): void
    {
        /** @var ?string $activityId */
        $activityId = $this->getAttributeValue('activityId');

        $process = $this->getProcess();

        if (
            $this->getProcess()->getParentProcessFlowNodeId() &&
            $this->getProcess()->getParentProcessId()
        ) {
            /** @var ?BpmnFlowNode $parentNode */
            $parentNode = $this->getEntityManager()
                ->getEntityById(BpmnFlowNode::ENTITY_TYPE, $this->getProcess()->getParentProcessFlowNodeId());

            if (!$parentNode) {
                throw new Error("No parent node.");
            }

            if ($parentNode->getElementType() === 'eventSubProcess') {
                /** @var ?BpmnProcess $process */
                $process = $this->getEntityManager()
                    ->getEntityById(BpmnProcess::ENTITY_TYPE, $this->getProcess()->getParentProcessId());

                if (!$process) {
                    throw new Error("No parent process.");
                }
            }
        }

        $compensationFlowNodeIdList = $this->getManager()->compensate($process, $activityId);

        $this->getFlowNode()->setDataItemValue('compensationFlowNodeIdList', $compensationFlowNodeIdList);
        $this->saveFlowNode();

        $this->refreshProcess();
        $this->refreshTarget();

        if ($compensationFlowNodeIdList === [] || $this->isCompensated()) {
            $this->setProcessed();
            $this->processAfterProcessed();

            return;
        }

        $this->getFlowNode()->set('status', BpmnFlowNode::STATUS_PENDING);
        $this->saveFlowNode();
    }

    protected function processAfterProcessed(): void
    {
        $this->endProcessFlow();
    }

    public function proceedPending(): void
    {
        if (!$this->isCompensated()) {
            return;
        }

        $this->setProcessed();
        $this->processAfterProcessed();
    }

    private function isCompensated(): bool
    {
        /** @var string[] $compensationFlowNodeIdList */
        $compensationFlowNodeIdList = $this->getFlowNode()->getDataItemValue('compensationFlowNodeIdList') ?? [];

        $flowNodes = $this->getEntityManager()
            ->getRDBRepositoryByClass(BpmnFlowNode::class)
            ->where(['id' => $compensationFlowNodeIdList])
            ->find();

        foreach ($flowNodes as $flowNode) {
            if ($flowNode->getStatus() === BpmnFlowNode::STATUS_PROCESSED) {
                continue;
            }

            return false;
        }

        return true;
    }
}
