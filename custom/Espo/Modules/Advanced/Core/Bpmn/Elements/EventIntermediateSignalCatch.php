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

use Espo\Modules\Advanced\Entities\BpmnFlowNode;

class EventIntermediateSignalCatch extends EventSignal
{
    public function process(): void
    {
        $signal = $this->getSignal();

        if (!$signal) {
            $this->fail();

            $this->getLog()->warning("BPM: No signal for EventIntermediateSignalCatch");

            return;
        }

        $flowNode = $this->getFlowNode();

        $flowNode->setStatus(BpmnFlowNode::STATUS_PENDING);

        $this->getEntityManager()->saveEntity($flowNode);

        $this->getSignalManager()->subscribe($signal, $flowNode->getId());
    }

    public function proceedPending(): void
    {
        $flowNode = $this->getFlowNode();

        $flowNode->setStatus(BpmnFlowNode::STATUS_IN_PROCESS);

        $this->getEntityManager()->saveEntity($flowNode);

        $this->rejectConcurrentPendingFlows();
        $this->processNextElement();
    }
}
