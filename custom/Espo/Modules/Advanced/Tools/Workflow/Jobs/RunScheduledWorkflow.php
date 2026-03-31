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

namespace Espo\Modules\Advanced\Tools\Workflow\Jobs;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\Modules\Advanced\Tools\Report\ListType\RunParams as ListRunParams;
use Espo\Modules\Advanced\Tools\Report\Service as ReportService;
use Espo\Modules\Advanced\Tools\Workflow\Service;
use Espo\ORM\EntityManager;

use Exception;
use RuntimeException;

class RunScheduledWorkflow implements Job
{
    public function __construct(
        private ReportService $reportService,
        private EntityManager $entityManager,
        private Service $service,
        private JobSchedulerFactory $jobSchedulerFactory,
    ) {}

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function run(Data $data): void
    {
        $workflowId = $data->getTargetId() ?? $data->get('workflowId');

        if (!$workflowId) {
            throw new RuntimeException();
        }

        $workflow = $this->entityManager->getRDBRepositoryByClass(Workflow::class)->getById($workflowId);

        if (!$workflow) {
            throw new RuntimeException("Workflow $workflowId not found.");
        }

        if (!$workflow->isActive()) {
            return;
        }

        $targetReport = $this->entityManager
            ->getRDBRepository(Workflow::ENTITY_TYPE)
            ->getRelation($workflow, 'targetReport')
            ->findOne();

        if (!$targetReport) {
            throw new RuntimeException("Workflow $workflowId: Target report not found.");
        }

        $result = $this->reportService->runList(
            id: $targetReport->getId(),
            runParams: ListRunParams::create()->withReturnSthCollection(),
        );

        foreach ($result->getCollection() as $entity) {
            try {
                $this->runScheduledWorkflowForEntity(
                    $workflow->getId(),
                    $entity->getEntityType(),
                    $entity->getId()
                );
            } catch (Exception) {
                // @todo Revise.

                $this->jobSchedulerFactory
                    ->create()
                    ->setClassName(RunScheduledWorkflowForEntity::class)
                    ->setGroup('scheduled-workflows')
                    ->setData([
                        'workflowId' => $workflow->getId(),
                        'entityType' => $entity->getEntityType(),
                        'entityId' => $entity->getId(),
                    ])
                    ->schedule();
            }
        }
    }

    /**
     * @throws FormulaError
     * @throws Error
     */
    private function runScheduledWorkflowForEntity(string $workflowId, string $entityType, string $id): void
    {
        // @todo Create jobs if a parameter is enabled.

        $entity = $this->entityManager->getEntityById($entityType, $id);

        if (!$entity) {
            throw new RuntimeException("Workflow $workflowId: Entity $entityType $id not found.");
        }

        $this->service->triggerWorkflow($entity, $workflowId);
    }
}
