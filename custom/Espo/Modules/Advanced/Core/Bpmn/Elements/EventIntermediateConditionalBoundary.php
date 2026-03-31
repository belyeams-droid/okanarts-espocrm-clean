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

/**
 * @noinspection PhpUnused
 */
class EventIntermediateConditionalBoundary extends EventIntermediateConditionalCatch
{
    public function process(): void
    {
        $result = $this->getConditionManager()->check(
            $this->getTarget(),
            $this->getAttributeValue('conditionsAll'),
            $this->getAttributeValue('conditionsAny'),
            $this->getAttributeValue('conditionsFormula'),
            $this->getVariablesForFormula()
        );

        if ($result) {
            $cancel = $this->getAttributeValue('cancelActivity');

            if (!$cancel) {
                $this->createOppositeNode();
            }

            $this->processNextElement();

            if ($cancel) {
                $this->getManager()->cancelActivityByBoundaryEvent($this->getFlowNode());
            }

            return;
        }

        $flowNode = $this->getFlowNode();

        $flowNode->setStatus(BpmnFlowNode::STATUS_PENDING);

        $this->getEntityManager()->saveEntity($flowNode);
    }

    public function proceedPending(): void
    {
        $result = $this->getConditionManager()->check(
            $this->getTarget(),
            $this->getAttributeValue('conditionsAll'),
            $this->getAttributeValue('conditionsAny'),
            $this->getAttributeValue('conditionsFormula'),
            $this->getVariablesForFormula()
        );

        if ($this->getFlowNode()->getDataItemValue('isOpposite')) {
            if (!$result) {
                $this->setProcessed();
                $this->createOppositeNode(true);
            }

            return;
        }

        if ($result) {
            $cancel = $this->getAttributeValue('cancelActivity');

            if (!$cancel) {
                $this->createOppositeNode();
            }

            $this->processNextElement();

            if ($cancel) {
                $this->getManager()->cancelActivityByBoundaryEvent($this->getFlowNode());
            }
        }
    }

    protected function createOppositeNode(bool $isNegative = false): void
    {
        /** @var BpmnFlowNode $flowNode */
        $flowNode = $this->getEntityManager()->getNewEntity(BpmnFlowNode::ENTITY_TYPE);

        $flowNode->setStatus(BpmnFlowNode::STATUS_PENDING);

        $flowNode->set([
            'elementId' => $this->getFlowNode()->getElementId(),
            'elementType' => $this->getFlowNode()->getElementType(),
            'elementData' => $this->getFlowNode()->getElementData(),
            'data' => [
                'isOpposite' => !$isNegative,
            ],
            'flowchartId' => $this->getProcess()->getFlowchartId(),
            'processId' => $this->getProcess()->getId(),
            'previousFlowNodeElementType' => $this->getFlowNode()->getPreviousFlowNodeElementType(),
            'previousFlowNodeId' => $this->getFlowNode()->getPreviousFlowNodeId(),
            'divergentFlowNodeId' => $this->getFlowNode()->getDivergentFlowNodeId(),
            'targetType' => $this->getFlowNode()->getTargetType(),
            'targetId' => $this->getFlowNode()->getTargetId(),
        ]);

        $this->getEntityManager()->saveEntity($flowNode);
    }
}
