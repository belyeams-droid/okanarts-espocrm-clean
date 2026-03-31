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
use Espo\Core\Formula\Exceptions\Error as FormulaException;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\ObjectUtil;
use Espo\Core\Utils\Util;
use Espo\Modules\Advanced\Entities\BpmnFlowchart;
use Espo\Modules\Advanced\Core\Bpmn\Utils\Helper;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\ORM\Entity;

use Throwable;
use stdClass;
use Traversable;

class CallActivity extends Activity
{
    protected const MAX_INSTANCE_COUNT = 20;
    protected const CALLABLE_TYPE_PROCESS = 'Process';

    /**
     * @throws FormulaException
     * @throws Error
     */
    public function process(): void
    {
        if ($this->isMultiInstance()) {
            $this->processMultiInstance();

            return;
        }

        $callableType = $this->getAttributeValue('callableType');

        if (!$callableType) {
            $this->fail();

            return;
        }

        if ($callableType === self::CALLABLE_TYPE_PROCESS) {
            $this->processProcess();

            return;
        }

        $this->fail();
    }

    /**
     * @throws FormulaException
     * @throws Error
     */
    protected function processProcess(): void
    {
        $target = $this->getNewTargetEntity();

        if (!$target) {
            $this->getLog()->notice("BPM Call Activity: Could not get target for sub-process.");

            $this->fail();

            return;
        }

        $flowchartId = $this->getAttributeValue('flowchartId');

        $flowNode = $this->getFlowNode();

        $variables = $this->getPrepareVariables();

        /** @var BpmnProcess $subProcess */
        $subProcess = $this->getEntityManager()->createEntity(BpmnProcess::ENTITY_TYPE, [
            'status' => BpmnProcess::STATUS_CREATED,
            'flowchartId' => $flowchartId,
            'targetId' => $target->getId(),
            'targetType' => $target->getEntityType(),
            'parentProcessId' => $this->getProcess()->getId(),
            'parentProcessFlowNodeId' => $flowNode->getId(),
            'rootProcessId' => $this->getProcess()->getRootProcessId(),
            'assignedUserId' => $this->getProcess()->getAssignedUser()?->getId(),
            'teamsIds' => $this->getProcess()->getTeams()->getIdList(),
            'variables' => $variables,
        ], [
            'skipCreatedBy' => true,
            'skipModifiedBy' => true,
            'skipStartProcessFlow' => true,
        ]);

        $flowNode->setStatus(BpmnFlowNode::STATUS_IN_PROCESS);
        $flowNode->setDataItemValue('subProcessId', $subProcess->getId());

        $this->getEntityManager()->saveEntity($flowNode);

        try {
            $this->getManager()->startCreatedProcess($subProcess);
        } catch (Throwable $e) {
            $message = "BPM Call Activity: Starting sub-process failure, {$subProcess->getId()}. {$e->getMessage()}";

            $this->getLog()->error($message, ['exception' => $e]);

            $this->fail();

            return;
        }
    }

    public function complete(): void
    {
        if (!$this->isInNormalFlow()) {
            $this->setProcessed();

            return;
        }

        $subProcessId = $this->getFlowNode()->getDataItemValue('subProcessId');

        if (!$subProcessId) {
            $this->processNextElement();

            return;
        }

        $subProcess = $this->getEntityManager()->getEntityById(BpmnProcess::ENTITY_TYPE, $subProcessId);

        if (!$subProcess) {
            $this->processNextElement();

            return;
        }

        $spCreatedEntitiesData = $subProcess->get('createdEntitiesData') ?? (object) [];
        $createdEntitiesData = $this->getCreatedEntitiesData();
        $spVariables = $subProcess->get('variables') ?? (object) [];
        $variables = $this->getVariables();

        $isUpdated = false;

        foreach (get_object_vars($spCreatedEntitiesData) as $key => $value) {
            if (!isset($createdEntitiesData->$key)) {
                $createdEntitiesData->$key = $value;

                $isUpdated = true;
            }
        }

        $variableList = $this->getReturnVariableList();

        if ($this->isMultiInstance()) {
            $variableList = [];

            $returnCollectionVariable = $this->getReturnCollectionVariable();

            if ($returnCollectionVariable !== null) {
                $variables->$returnCollectionVariable = $spVariables->outputCollection;
            }
        }

        foreach ($variableList as $variable) {
            $variables->$variable = $spVariables->$variable ?? null;
        }

        if (
            $isUpdated ||
            count($variableList) ||
            $this->isMultiInstance()
        ) {
            $this->refreshProcess();

            $this->getProcess()->set('createdEntitiesData', $createdEntitiesData);
            $this->getProcess()->set('variables', $variables);

            $this->getEntityManager()->saveEntity($this->getProcess());
        }

        $this->processNextElement();
    }

    protected function getReturnCollectionVariable(): ?string
    {
        $variable = $this->getAttributeValue('returnCollectionVariable');

        if (!$variable) {
            return null;
        }

        if ($variable[0] === '$') {
            $variable = substr($variable, 1);
        }

        return $variable;
    }

    /**
     * @throws FormulaException
     * @throws Error
     */
    protected function getNewTargetEntity(): ?Entity
    {
        $target = $this->getAttributeValue('target');

        return $this->getSpecificTarget($target);
    }

    protected function isMultiInstance(): bool
    {
        return (bool) $this->getAttributeValue('isMultiInstance');
    }

    protected function isSequential(): bool
    {
        return (bool) $this->getAttributeValue('isSequential');
    }

    protected function getLoopCollectionExpression(): ?string
    {
        $expression = $this->getAttributeValue('loopCollectionExpression');

        if (!$expression) {
            return null;
        }

        $expression = trim($expression, " \t\n\r");

        if (str_ends_with($expression, ';')) {
            $expression = substr($expression, 0, -1);
        }

        return $expression;
    }

    protected function getConfig(): Config
    {
        return $this->getContainer()->getByClass(Config::class);
    }

    protected function getMaxInstanceCount(): int
    {
        return $this->getConfig()->get('bpmnSubProcessInstanceMaxCount', self::MAX_INSTANCE_COUNT);
    }

    /**
     * @throws FormulaException
     * @throws Error
     */
    protected function processMultiInstance(): void
    {
        $loopCollectionExpression = $this->getLoopCollectionExpression();

        if (!$loopCollectionExpression) {
            throw new Error("BPM Sub-Process: No loop-collection-expression.");
        }

        $loopCollection = $this->getFormulaManager()->run(
            $loopCollectionExpression,
            $this->getTarget(),
            $this->getVariablesForFormula()
        );

        if (!is_iterable($loopCollection)) {
            throw new Error("BPM Sub-Process: Loop-collection-expression evaluated to a non-iterable value.");
        }

        if ($loopCollection instanceof Traversable) {
            $loopCollection = iterator_to_array($loopCollection);
        }

        $maxCount = $this->getMaxInstanceCount();

        $returnVariableList = $this->getReturnVariableList();

        $outputCollection = [];

        for ($i = 0; $i < count($loopCollection); $i++) {
            $outputItem = (object) [];

            foreach ($returnVariableList as $variable) {
                $outputItem->$variable = null;
            }

            $outputCollection[] = $outputItem;
        }

        if ($maxCount < count($loopCollection)) {
            $loopCollection = array_slice($loopCollection, 0, $maxCount);
        }

        $count = count($loopCollection);

        $flowchart = $this->createMultiInstanceFlowchart($count);

        $flowNode = $this->getFlowNode();
        $variables = $this->getClonedVariables();

        $this->refreshProcess();

        $variables->inputCollection = $loopCollection;
        $variables->outputCollection = $outputCollection;

        /** @var BpmnProcess $subProcess */
        $subProcess = $this->getEntityManager()->createEntity(BpmnProcess::ENTITY_TYPE, [
            'status' => BpmnFlowNode::STATUS_CREATED,
            'targetId' => $this->getTarget()->getId(),
            'targetType' => $this->getTarget()->getEntityType(),
            'parentProcessId' => $this->getProcess()->getId(),
            'parentProcessFlowNodeId' => $flowNode->getId(),
            'rootProcessId' => $this->getProcess()->getRootProcessId(),
            'assignedUserId' => $this->getProcess()->getAssignedUser()?->getId(),
            'teamsIds' => $this->getProcess()->getTeams()->getIdList(),
            'variables' => $variables,
            'createdEntitiesData' => clone $this->getCreatedEntitiesData(),
        ],
        [
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

    protected function createMultiInstanceFlowchart(int $count): BpmnFlowchart
    {
        /** @var BpmnFlowchart $flowchart */
        $flowchart = $this->getEntityManager()->getNewEntity(BpmnFlowchart::ENTITY_TYPE);

        $dataList = $this->isSequential() ?
            $this->generateSequentialMultiInstanceDataList($count) :
            $this->generateParallelMultiInstanceDataList($count);

        $eData = Helper::getElementsDataFromFlowchartData((object) [
            'list' => $dataList,
        ]);

        $name = $this->isSequential() ?
            'Sequential Multi-Instance' :
            'Parallel Multi-Instance';

        $flowchart->set([
            'targetType' => $this->getTarget()->getEntityType(),
            'data' => (object) [
                'createdEntitiesData' => clone $this->getCreatedEntitiesData(),
                'list' => $dataList,
            ],
            'elementsDataHash' => $eData['elementsDataHash'],
            'teamsIds' => $this->getProcess()->getLinkMultipleIdList('teams'),
            'assignedUserId' => $this->getProcess()->get('assignedUserId'),
            'name' => $name,
        ]);

        return $flowchart;
    }

    /**
     * @return stdClass[]
     */
    protected function generateParallelMultiInstanceDataList(int $count): array
    {
        $dataList = [];

        for ($i = 0; $i < $count; $i++) {
            $dataList = array_merge($dataList, $this->generateMultiInstanceIteration($i));
        }

        return array_merge($dataList, $this->generateMultiInstanceCompensation($count, $dataList));
    }

    /**
     * @return stdClass[]
     */
    protected function generateSequentialMultiInstanceDataList(int $count): array
    {
        $dataList = [];
        $groupList = [];

        for ($i = 0; $i < $count; $i++) {
            $groupList[] = $this->generateMultiInstanceIteration($i);
        }

        foreach ($groupList as $i => $itemList) {
            $dataList = array_merge($dataList, $itemList);

            if ($i == 0) {
                continue;
            }

            $previousItemList = $groupList[$i - 1];

            $dataList[] = (object) [
                'type' => 'flow',
                'id' => self::generateElementId(),
                'startId' => $previousItemList[2]->id,
                'endId' => $itemList[0]->id,
                'startDirection' => 'r',
            ];
        }

        return array_merge($dataList, $this->generateMultiInstanceCompensation(0, $dataList));
    }

    /**
     * @return stdClass[]
     */
    protected function generateMultiInstanceIteration(int $loopCounter): array
    {
        $dataList = [];

        $x = 100;
        $y = ($loopCounter + 1) * 130;

        if ($this->isSequential()) {
            $x = $x + ($loopCounter * 400);
            $y = 50;
        }

        $initElement = (object) [
            'type' => 'taskScript',
            'id' => self::generateElementId(),
            'formula' =>
                "\$loopCounter = $loopCounter;\n" .
                "\$inputItem = array\\at(\$inputCollection, $loopCounter);\n",
            'center' => (object) [
                'x' => $x,
                'y' => $y,
            ],
            'text' => $loopCounter . ' init',
        ];

        $subProcessElement = $this->generateSubProcessMultiInstance($loopCounter, $x, $y);

        $endScript = "\$outputItem = array\\at(\$outputCollection, $loopCounter);\n";

        foreach ($this->getReturnVariableList() as $variable) {
            $endScript .= "object\set(\$outputItem, '$variable', \$$variable);\n";
        }

        $endElement = (object) [
            'type' => 'taskScript',
            'id' => self::generateElementId(),
            'formula' => $endScript,
            'center' => (object) [
                'x' => $x + 250,
                'y' => $y,
            ],
            'text' => $loopCounter . ' out',
        ];

        $dataList[] = $initElement;
        $dataList[] = $subProcessElement;
        $dataList[] = $endElement;

        $dataList[] = (object) [
            'type' => 'flow',
            'id' => self::generateElementId(),
            'startId' => $initElement->id,
            'endId' => $subProcessElement->id,
            'startDirection' => 'r',
        ];

        $dataList[] = (object) [
            'type' => 'flow',
            'id' => self::generateElementId(),
            'startId' => $subProcessElement->id,
            'endId' => $endElement->id,
            'startDirection' => 'r',
        ];

        foreach ($this->generateBoundaryMultiInstance($subProcessElement) as $item) {
            $dataList[] = $item;
        }

        return $dataList;
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
            'callableType' => $this->getAttributeValue('callableType'),
            'flowchartId' => $this->getAttributeValue('flowchartId'),
            'flowchartName' => $this->getAttributeValue('flowchartName'),
            'returnVariableList' => $this->getAttributeValue('returnVariableList'),
            'target' => $this->getAttributeValue('target'),
            'targetType' => $this->getAttributeValue('targetType'),
            'targetIdExpression' => $this->getAttributeValue('targetIdExpression'),
            'isMultiInstance' => false,
            'isSequential' => false,
            'loopCollectionExpression' => null,
            'text' => (string) $loopCounter,
        ];
    }

    /**
     * @return stdClass[]
     */
    protected function generateBoundaryMultiInstance(stdClass $element): array
    {
        $dataList = [];

        $attachedElementIdList = array_filter(
            $this->getProcess()->getAttachedToFlowNodeElementIdList($this->getFlowNode()),
            function (string $id): bool {
                $data = $this->getProcess()->getElementDataById($id);

                return in_array(
                    $data->type,
                    [
                        'eventIntermediateErrorBoundary',
                        'eventIntermediateEscalationBoundary',
                        'eventIntermediateCompensationBoundary',
                    ]
                );
            }
        );

        $compensationId = array_values(array_filter(
            $this->getProcess()->getElementIdList(),
            function (string $id): bool {
                $data = $this->getProcess()->getElementDataById($id);

                return ($data->isForCompensation ?? null) === true;
            }
        ))[0] ?? null;

        foreach ($attachedElementIdList as $i => $id) {
            $boundaryElementId = self::generateElementId();
            $throwElementId = self::generateElementId();

            $originalData = $this->getProcess()->getElementDataById($id);

            $o1 = (object) [
                'type' => $originalData->type,
                'id' => $boundaryElementId,
                'attachedToId' => $element->id,
                'cancelActivity' => $originalData->cancelActivity ?? false,
                'center' => (object) [
                    'x' => $element->center->x - 20 + $i * 25,
                    'y' => $element->center->y - 35,
                ],
                'attachPosition' => $originalData->attachPosition,
            ];

            if ($originalData->type === 'eventIntermediateCompensationBoundary') {
                if (!$compensationId) {
                    continue;
                }

                $dataList[] = $o1;

                continue;
            }

            $o2 = (object) [
                'type' => 'eventEndError',
                'id' => $throwElementId,
                'errorCode' => $originalData->errorCode ?? null,
                'center' => (object) [
                    'x' => $element->center->x - 20 + $i * 25 + 80,
                    'y' => $element->center->y - 35 - 25,
                ],
            ];

            if ($originalData->type === 'eventIntermediateErrorBoundary') {
                $o2->type = 'eventEndError';
                $o1->errorCode = $originalData->errorCode ?? null;
                $o2->errorCode = $originalData->errorCode ?? null;
                $o1->cancelActivity = true;
            }
            else if ($originalData->type === 'eventIntermediateEscalationBoundary') {
                $o2->type = 'eventEndEscalation';
                $o1->escalationCode = $originalData->escalationCode ?? null;
                $o2->escalationCode = $originalData->escalationCode ?? null;
            }

            $dataList[] = $o1;
            $dataList[] = $o2;

            $dataList[] = (object) [
                'type' => 'flow',
                'id' => self::generateElementId(),
                'startId' => $boundaryElementId,
                'endId' => $throwElementId,
                'startDirection' => 'r',
            ];
        }

        return $dataList;
    }

    /**
     * @param stdClass[] $currentDataList
     * @return stdClass[]
     */
    private function generateMultiInstanceCompensation(int $loopCounter, array $currentDataList): array
    {
        $x = 150;
        $y = ($loopCounter + 1) * 100 + 100;

        $internalDataList = $this->generateMultiInstanceCompensationSubProcessDataList();

        $dataList = [];

        $dataList[] = (object) [
            'type' => 'eventSubProcess',
            'id' => self::generateElementId(),
            'center' => (object) [
                'x' => $x,
                'y' => $y,
            ],
            'height' => 100,
            'width' => 205,
            'target' => null,
            'isExpanded' => true,
            'dataList' => $internalDataList,
            'eventStartData' => clone $internalDataList[0],
        ];

        $activity = $this->generateMultiInstanceBoundaryCompensationActivity();

        if ($activity) {
            $activity->center = (object) [
                'x' => 350,
                'y' => $y,
            ];

            $dataList[] = $activity;

            foreach ($currentDataList as $item) {
                if ($item->type === 'eventIntermediateCompensationBoundary') {
                    $dataList[] = (object) [
                        'type' => 'flow',
                        'id' => self::generateElementId(),
                        'startId' => $item->id,
                        'endId' => $activity->id,
                        'startDirection' => 'd',
                        'isAssociation' => true,
                    ];
                }
            }
        }

        return $dataList;
    }

    /**
     * @return stdClass[]
     */
    private function generateMultiInstanceCompensationSubProcessDataList(): array
    {
        $x = 50;
        $y = 35;

        $dataList = [];

        $dataList[] = (object) [
            'type' => 'eventStartCompensation',
            'id' => self::generateElementId(),
            'center' => (object) [
                'x' => $x,
                'y' => $y,
            ],
        ];

        $dataList[] = (object) [
            'type' => 'eventEndCompensation',
            'id' => self::generateElementId(),
            'center' => (object) [
                'x' => $x + 100,
                'y' => $y,
            ],
            'activityId' => null,
        ];

        $dataList[] = (object) [
            'type' => 'flow',
            'id' => self::generateElementId(),
            'startId' => $dataList[0]->id,
            'endId' => $dataList[1]->id,
            'startDirection' => 'r',
        ];

        return $dataList;
    }

    private function generateMultiInstanceBoundaryCompensationActivity(): ?stdClass
    {
        /** @var string[] $attachedElementIdList */
        $attachedElementIdList = array_values(array_filter(
            $this->getProcess()->getAttachedToFlowNodeElementIdList($this->getFlowNode()),
            function (string $id): bool {
                $data = $this->getProcess()->getElementDataById($id);

                return $data->type === 'eventIntermediateCompensationBoundary';
            }
        ));

        if ($attachedElementIdList === []) {
            return null;
        }

        $boundaryId = $attachedElementIdList[0];

        $compensationId = $this->getProcess()->getElementNextIdList($boundaryId)[0] ?? null;

        if (!$compensationId) {
            return null;
        }

        $item = $this->getProcess()->getElementDataById($compensationId);

        if (!$item) {
            return null;
        }

        $item = ObjectUtil::clone($item);
        $item->id = $this->generateElementId();

        if ($item->type === 'subProcess') {
            $item->isExpanded = false;
        }

        return $item;
    }

    protected static function generateElementId(): string
    {
        return Util::generateId();
    }

    protected function getPrepareVariables(): stdClass
    {
        $variables = $this->getClonedVariables();

        unset($variables->__caughtErrorCode);
        unset($variables->__caughtErrorMessage);

        return $variables;
    }
}
