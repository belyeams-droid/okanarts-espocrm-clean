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

use Espo\Modules\Advanced\Core\Bpmn\Utils\Helper;
use Espo\Modules\Advanced\Entities\BpmnFlowchart;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Modules\Advanced\Entities\BpmnProcess;

use Throwable;
use stdClass;

class SubProcess extends CallActivity
{
    public function process(): void
    {
        if ($this->isMultiInstance()) {
            $this->processMultiInstance();

            return;
        }

        $target = $this->getNewTargetEntity();

        if (!$target) {
            $this->getLog()->info("BPM Sub-Process: Could not get target for sub-process.");

            $this->fail();

            return;
        }

        $flowNode = $this->getFlowNode();
        $variables = $this->getPrepareVariables();

        $this->refreshProcess();

        $parentFlowchartData = $this->getProcess()->get('flowchartData') ?? (object) [];

        $createdEntitiesData = clone $this->getCreatedEntitiesData();

        $eData = Helper::getElementsDataFromFlowchartData((object) [
            'list' => $this->getAttributeValue('dataList') ?? [],
        ]);

        /** @var BpmnFlowchart $flowchart */
        $flowchart = $this->getEntityManager()->getNewEntity(BpmnFlowchart::ENTITY_TYPE);

        $flowchart->set([
            'targetType' => $target->getEntityType(),
            'data' => (object) [
                'createdEntitiesData' => $parentFlowchartData->createdEntitiesData ?? (object) [],
                'list' => $this->getAttributeValue('dataList') ?? [],
            ],
            'elementsDataHash' => $eData['elementsDataHash'],
            'hasNoneStartEvent' => count($eData['eventStartIdList']) > 0,
            'eventStartIdList'=> $eData['eventStartIdList'],
            'teamsIds' => $this->getProcess()->getTeams()->getIdList(),
            'assignedUserId' => $this->getProcess()->getAssignedUser()?->getId(),
            'name' => $this->getAttributeValue('title') ?? 'Sub-Process',
        ]);

        /** @var BpmnProcess $subProcess */
        $subProcess = $this->getEntityManager()->createEntity(BpmnProcess::ENTITY_TYPE, [
            'status' => BpmnFlowNode::STATUS_CREATED,
            'targetId' => $target->getId(),
            'targetType' => $target->getEntityType(),
            'parentProcessId' => $this->getProcess()->getId(),
            'parentProcessFlowNodeId' => $flowNode->getId(),
            'rootProcessId' => $this->getProcess()->getRootProcessId(),
            'assignedUserId' => $this->getProcess()->getAssignedUser()?->getId(),
            'teamsIds' => $this->getProcess()->getTeams()->getIdList(),
            'variables' => $variables,
            'createdEntitiesData' => $createdEntitiesData,
            'startElementId' => $this->getSubProcessStartElementId(),
        ], [
            'skipCreatedBy' => true,
            'skipModifiedBy' => true,
            'skipStartProcessFlow' => true,
        ]);

        $flowNode->setStatus(BpmnFlowNode::STATUS_IN_PROCESS);

        $flowNode->setDataItemValue('subProcessId', $subProcess->getId());

        $this->getEntityManager()->saveEntity($flowNode);

        try {
            $this->getManager()->startCreatedProcess($subProcess, $flowchart);
        } catch (Throwable $e) {
            $message = "BPM Sub-Process: Starting sub-process failure, {$subProcess->getId()}. {$e->getMessage()}";

            $this->getLog()->error($message, ['exception' => $e]);

            $this->fail();

            return;
        }
    }

    protected function getSubProcessStartElementId(): ?string
    {
        return null;
    }

    protected function generateSubProcessMultiInstance(int $loopCounter, int $x, int $y): stdClass
    {
        return (object) [
            'type' => $this->getAttributeValue('type'),
            'id' => self::generateElementId(),
            'center' => (object) [
                'x' => $x + 125,
                'y' => $y,
            ],
            'dataList' => $this->getAttributeValue('dataList'),
            'returnVariableList' => $this->getAttributeValue('returnVariableList'),
            'isExpanded' => false,
            'target' => $this->getAttributeValue('target'),
            'targetType' => $this->getAttributeValue('targetType'),
            'targetIdExpression' => $this->getAttributeValue('targetIdExpression'),
            'isMultiInstance' => false,
            'triggeredByEvent' => false,
            'isSequential' => false,
            'loopCollectionExpression' => null,
            'text' => (string) $loopCounter,
        ];
    }
}
