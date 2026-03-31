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

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Exceptions\Error;
use Espo\Modules\Advanced\Business\Workflow\AssignmentRules\LeastBusy;
use Espo\Modules\Advanced\Business\Workflow\AssignmentRules\RoundRobin;
use stdClass;

class ApplyAssignmentRule extends BaseEntity
{
    /**
     * @throws Error
     */
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        $entityManager = $this->entityManager;

        $target = null;

        if (!empty($actionData->target)) {
            $target = $actionData->target;
        }

        if ($target === 'process') {
            $entity = $this->bpmnProcess;
        } else if (str_starts_with($target, 'created:')) {
            $entity = $this->getCreatedEntity($target);
        }

        if (!$entity) {
            return false;
        }

        if (!$entity->hasAttribute('assignedUserId') || !$entity->hasRelation('assignedUser')) {
            return false;
        }

        $reloadedEntity = $entityManager->getEntityById($entity->getEntityType(), $entity->getId());

        if (!$reloadedEntity) {
            throw new Error("Entity does not already exist.");
        }

        if (empty($actionData->targetTeamId) || empty($actionData->assignmentRule)) {
            throw new Error('AssignmentRule: Not enough parameters.');
        }

        $targetTeamId = $actionData->targetTeamId;
        $assignmentRule = $actionData->assignmentRule;

        $targetUserPosition = null;

        if (!empty($actionData->targetUserPosition)) {
            $targetUserPosition = $actionData->targetUserPosition;
        }

        $listReportId = null;

        if (!empty($actionData->listReportId)) {
            $listReportId = $actionData->listReportId;
        }

        if (
            !in_array(
                $assignmentRule,
                $this->metadata->get('entityDefs.Workflow.assignmentRuleList', [])
            )
        ) {
            throw new Error('AssignmentRule: ' . $assignmentRule . ' is not supported.');
        }

        // @todo Use factory and interface.

        /** @var class-string<LeastBusy|RoundRobin> $className */
        $className = 'Espo\\Modules\\Advanced\\Business\\Workflow\\AssignmentRules\\' .
            str_replace('-', '', $assignmentRule);

        if (!class_exists($className)) {
            throw new Error('AssignmentRule: Class ' . $className . ' not found.');
        }

        $actionId = $this->getActionData()->id ?? null;

        if (!$actionId) {
            throw new Error("No action ID.");
        }

        $workflowId = $this->getWorkflowId();

        $flowchartId = null;

        if ($this->bpmnProcess) {
            $flowchartId = $this->bpmnProcess->getFlowchartId();

            $workflowId = null;
        }

        $rule = $this->injectableFactory->createWith($className, [
            'actionId' => $actionId,
            'workflowId' => $workflowId,
            'flowchartId' => $flowchartId,
            'entityType' => $entity->getEntityType(),
        ]);

        $attributes = $rule->getAssignmentAttributes($entity, $targetTeamId, $targetUserPosition, $listReportId);

        $entity->set($attributes);
        $reloadedEntity->set($attributes);

        $entityManager->saveEntity($reloadedEntity, [
            'skipWorkflow' => true,
            'modifiedById' => 'system',
            'skipCreatedBy' => true,
        ]);

        return true;
    }
}
