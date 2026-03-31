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

use Espo\Core\Exceptions\Error;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\Modules\Advanced\Tools\Workflow\Jobs\TriggerWorkflow as TriggerWorkflowJob;
use Espo\Modules\Advanced\Tools\Workflow\Jobs\TriggerWorkflowMany;
use Espo\Modules\Advanced\Tools\Workflow\Service;
use Espo\ORM\Entity;
use DateTime;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class TriggerWorkflow extends Base
{
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        $workflowId = $actionData->workflowId ?? null;
        $target = $actionData->target ?? null;

        if (!$workflowId) {
            return false;
        }

        $targetEntity = null;
        $hasMany = false;

        foreach ($this->getTargetsFromTargetItem($entity, $target) as $i => $it) {
            if ($i > 0) {
                $hasMany = true;

                break;
            }

            $targetEntity = $it;
        }

        if (!$targetEntity) {
            return true;
        }

        if ($hasMany) {
            $this->scheduleAnotherWorkflowMany($entity, $actionData, $targetEntity, $target, $workflowId);

            return true;
        }

        $this->triggerAnotherWorkflow($targetEntity, $actionData, $entity);

        return true;
    }

    private function scheduleAnotherWorkflowMany(
        Entity $entity,
        stdClass $actionData,
        Entity $firstEntity,
        ?string $target,
        string $workflowId
    ): void {

        $this->checkNextWorkflow($workflowId, $firstEntity);

        $schedulerFactory = $this->injectableFactory->create(JobSchedulerFactory::class);

        $schedulerFactory
            ->create()
            ->setClassName(TriggerWorkflowMany::class)
            ->setData([
                'workflowId' => $this->getWorkflowId(),
                'entityId' => $entity->getId(),
                'entityType' => $entity->getEntityType(),
                'nextWorkflowId' => $workflowId,
                'target' => $target,
            ])
            ->setTime(new DateTime($this->getExecuteTime($actionData)))
            ->schedule();
    }

    private function triggerAnotherWorkflow(Entity $entity, stdClass $actionData, Entity $originalEntity): void
    {
        $workflowId = $actionData->workflowId;
        $this->checkNextWorkflow($workflowId, $entity);

        $now = true;

        if (
            property_exists($actionData, 'execution') &&
            property_exists($actionData->execution, 'type')
        ) {
            $executeType = $actionData->execution->type;
            $now = !$executeType || $executeType === 'immediately';
        }

        if ($now) {
            $service = $this->injectableFactory->create(Service::class);

            if (
                $originalEntity->getEntityType() && $entity->getEntityType() &&
                $originalEntity->getId() === $entity->getId()
            ) {
                // To preserve 'changed' condition.
                $entity = $originalEntity;
            }

            $service->triggerWorkflow($entity, $actionData->workflowId);

            return;
        }

        $schedulerFactory = $this->injectableFactory->create(JobSchedulerFactory::class);

        $schedulerFactory
            ->create()
            ->setClassName(TriggerWorkflowJob::class)
            ->setData([
                'workflowId' => $this->getWorkflowId(),
                'entityId' => $entity->getId(),
                'entityType' => $entity->getEntityType(),
                'nextWorkflowId' => $workflowId,
                'values' => $entity->getValueMap(),
            ])
            ->setTime(new DateTime($this->getExecuteTime($actionData)))
            ->schedule();
    }

    /**
     * @throws Error
     */
    private function checkNextWorkflow(string $workflowId, Entity $entity): void
    {
        /** @var ?Workflow $workflow */
        $workflow = $this->entityManager->getEntityById(Workflow::ENTITY_TYPE, $workflowId);

        if (!$workflow) {
            throw new Error("Workflow: Trigger another workflow: No workflow $workflowId.");
        }

        if ($entity->getEntityType() !== $workflow->getTargetEntityType()) {
            throw new Error(
                "Workflow: Trigger another workflow: Not matching target entity type in workflow $workflowId.");
        }
    }
}
