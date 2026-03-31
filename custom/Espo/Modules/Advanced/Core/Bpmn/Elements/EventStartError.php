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

class EventStartError extends Base
{
    public function process(): void
    {
        $this->writeErrorData();
        $this->storeErrorVariables();
        $this->processNextElement();
    }

    private function writeErrorData(): void
    {
        $flowNode = $this->getFlowNode();

        $parentFlowNodeId = $this->getProcess()->getParentProcessFlowNodeId();

        if (!$parentFlowNodeId) {
            return;
        }

        /** @var ?BpmnFlowNode $parentFlowNode */
        $parentFlowNode = $this->getEntityManager()->getEntityById(BpmnFlowNode::ENTITY_TYPE, $parentFlowNodeId);

        if (!$parentFlowNode) {
            return;
        }

        $code = $parentFlowNode->getDataItemValue('caughtErrorCode');
        $message = $parentFlowNode->getDataItemValue('caughtErrorMessage');

        $flowNode->setDataItemValue('code', $code);
        $flowNode->setDataItemValue('message', $message);

        $this->getEntityManager()->saveEntity($flowNode);
    }

    private function storeErrorVariables(): void
    {
        $variables = $this->getVariables();

        $variables->__caughtErrorCode = $this->getFlowNode()->getDataItemValue('code');
        $variables->__caughtErrorMessage = $this->getFlowNode()->getDataItemValue('message');

        $this->getProcess()->setVariables($variables);
        $this->saveProcess();
    }
}
