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
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Field\LinkMultiple;
use Espo\Core\Field\LinkParent;
use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Language;
use Espo\Modules\Advanced\Business\Workflow\AssignmentRules\LeastBusy;
use Espo\Modules\Advanced\Business\Workflow\AssignmentRules\RoundRobin;
use Espo\Modules\Advanced\Core\Bpmn\Utils\Helper;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Modules\Advanced\Entities\BpmnUserTask;
use Espo\ORM\Entity;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class TaskUser extends Activity
{
    private const ASSIGNMENT_SPECIFIED_USER = 'specifiedUser';
    private const ASSIGNMENT_PROCESS_ASSIGNED_USER = 'processAssignedUser';

    /**
     * @throws FormulaError
     * @throws Error
     */
    public function process(): void
    {
        $this->getFlowNode()->setStatus(BpmnFlowNode::STATUS_IN_PROCESS);
        $this->saveFlowNode();

        $target = $this->getSpecificTarget($this->getAttributeValue('target'));

        if (!$target) {
            $this->getLog()->info("BPM TaskUser: Could not get target.");

            $this->fail();

            return;
        }

        $userTask = $this->createUserTask($target);

        $this->getFlowNode()->setDataItemValue('userTaskId', $userTask->getId());
        $this->saveFlowNode();

        $createdEntitiesData = $this->getPreparedCreatedEntitiesData($userTask);

        $this->getProcess()->setCreatedEntitiesData($createdEntitiesData);
        $this->saveProcess();
    }

    /**
     * @return LeastBusy|RoundRobin
     * @throws Error
     */
    private function getAssignmentRuleImplementation(string $assignmentRule)
    {
        /** @var class-string<LeastBusy|RoundRobin> $className */
        $className = 'Espo\\Modules\\Advanced\\Business\\Workflow\\AssignmentRules\\' .
            str_replace('-', '', $assignmentRule);

        if (!class_exists($className)) {
            throw new Error('Process TaskUser, Class ' . $className . ' not found.');
        }

        $injectableFactory = $this->getContainer()->getByClass(InjectableFactory::class);

        return $injectableFactory->createWith($className, [
            'entityType' => BpmnUserTask::ENTITY_TYPE,
            'actionId' => $this->getElementId(),
            'flowchartId' => $this->getFlowNode()->getFlowchartId(),
        ]);
    }

    public function complete(): void
    {
        if (!$this->isInNormalFlow()) {
            $this->setProcessed();

            return;
        }

        $this->processNextElement();
    }

    protected function getLanguage(): Language
    {
        /** @var Language */
        return $this->getContainer()->get('defaultLanguage');
    }

    protected function setInterrupted(): void
    {
        $this->cancelUserTask();

        parent::setInterrupted();
    }

    public function cleanupInterrupted(): void
    {
        parent::cleanupInterrupted();

        $this->cancelUserTask();
    }

    private function cancelUserTask(): void
    {
        $userTaskId = $this->getFlowNode()->getDataItemValue('userTaskId');

        if ($userTaskId) {
            /** @var ?BpmnUserTask $userTask */
            $userTask = $this->getEntityManager()->getEntityById(BpmnUserTask::ENTITY_TYPE, $userTaskId);

            if ($userTask && !$userTask->get('isResolved')) {
                $userTask->set(['isCanceled' => true]);

                $this->getEntityManager()->saveEntity($userTask);
            }
        }
    }

    private function getTaskName(): ?string
    {
        $name = $this->getAttributeValue('name');

        if (!is_string($name)) {
            return null;
        }

        if (!$name) {
            return null;
        }

        return Helper::applyPlaceholders($name, $this->getTarget(), $this->getVariables());
    }

    private function getInstructionsText(): ?string
    {
        $text = $this->getAttributeValue('instructions');

        if (!is_string($text)) {
            return null;
        }

        if (!$text) {
            return null;
        }

        return Helper::applyPlaceholders($text, $this->getTarget(), $this->getVariables());
    }

    /**
     * @return array<string, mixed>
     * @throws Error
     * @throws Forbidden
     */
    private function getAssignmentAttributes(BpmnUserTask $userTask): array
    {
        $assignmentType = $this->getAttributeValue('assignmentType');
        $targetTeamId = $this->getAttributeValue('targetTeamId');
        $targetUserPosition = $this->getAttributeValue('targetUserPosition') ?: null;

        $assignmentAttributes = [];

        if (str_starts_with($assignmentType, 'rule:')) {
            $assignmentRule = substr($assignmentType, 5);
            $ruleImpl = $this->getAssignmentRuleImplementation($assignmentRule);

            $whereClause = null;

            if ($assignmentRule === 'Least-Busy') {
                $whereClause = ['isResolved' => false];
            }

            $assignmentAttributes = $ruleImpl->getAssignmentAttributes(
                $userTask,
                $targetTeamId,
                $targetUserPosition,
                null,
                $whereClause
            );
        } else if (str_starts_with($assignmentType, 'link:')) {
            $link = substr($assignmentType, 5);
            $e = $this->getTarget();

            if (str_contains($link, '.')) {
                [$firstLink, $link] = explode('.', $link);

                $target = $this->getTarget();

                $e = $this->getEntityManager()
                    ->getRDBRepository($target->getEntityType())
                    ->getRelation($target, $firstLink)
                    ->findOne();
            }

            if ($e instanceof Entity) {
                $field = $link . 'Id';
                $userId = $e->get($field);

                if ($userId) {
                    $assignmentAttributes['assignedUserId'] = $userId;
                }
            }
        } else if ($assignmentType === self::ASSIGNMENT_PROCESS_ASSIGNED_USER) {
            $userId = $this->getProcess()->getAssignedUser()?->getId();

            if ($userId) {
                $assignmentAttributes['assignedUserId'] = $userId;
            }
        } else if ($assignmentType === self::ASSIGNMENT_SPECIFIED_USER) {
            $userId = $this->getAttributeValue('targetUserId');

            if ($userId) {
                $assignmentAttributes['assignedUserId'] = $userId;
            }
        }

        return $assignmentAttributes;
    }

    private function getTaskNameFinal(): string
    {
        $name = $this->getTaskName();

        $actionType = $this->getAttributeValue('actionType');

        if (!$name) {
            $name = $this->getLanguage()->translateOption($actionType, 'actionType', BpmnUserTask::ENTITY_TYPE);
        }

        return $name;
    }

    private function getPreparedCreatedEntitiesData(BpmnUserTask $userTask): stdClass
    {
        $createdEntitiesData = $this->getCreatedEntitiesData();

        $alias = $this->getFlowNode()->getElementId();

        if ($alias) {
            $createdEntitiesData->$alias = (object)[
                'entityId' => $userTask->getId(),
                'entityType' => $userTask->getEntityType(),
            ];
        }

        return $createdEntitiesData;
    }

    private function createUserTask(CoreEntity $target): BpmnUserTask
    {
        $userTask = $this->getEntityManager()->getRDBRepositoryByClass(BpmnUserTask::class)->getNew();

        $userTask
            ->setProcessId($this->getProcess()->getId())
            ->setActionType($this->getAttributeValue('actionType'))
            ->setFlowNodeId($this->getFlowNode()->getId())
            ->setTarget(LinkParent::create($target->getEntityType(), $target->getId()))
            ->setDescription($this->getAttributeValue('description'))
            ->setInstructions($this->getInstructionsText());

        $userTask->set($this->getAssignmentAttributes($userTask));

        $userTask
            ->setName($this->getTaskNameFinal())
            ->setTeams(LinkMultiple::create()->withAddedIdList($this->getTeamIdList()));

        $this->getEntityManager()->saveEntity($userTask, ['createdById' => 'system']);

        return $userTask;
    }

    /**
     * @return string[]
     */
    private function getTeamIdList(): array
    {
        $teamIdList = $this->getProcess()->getTeams()->getIdList();
        $targetTeamId = $this->getAttributeValue('targetTeamId');

        if ($targetTeamId && !in_array($targetTeamId, $teamIdList)) {
            $teamIdList[] = $targetTeamId;
        }

        return $teamIdList;
    }
}
