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

use Espo\Core\Exceptions\Error;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\Modules\Advanced\Tools\Workflow\Core\TargetProvider;
use Espo\Modules\Advanced\Tools\Workflow\Service;
use Espo\ORM\EntityManager;
use Exception;
use RuntimeException;

class TriggerWorkflowMany implements Job
{
    public function __construct(
        private TargetProvider $targetProvider,
        private EntityManager $entityManager,
        private Service $service,
        private Log $log
    ) {}

    /**
     * @throws Error
     */
    public function run(Data $data): void
    {
        $workflowId = $data->get('nextWorkflowId');
        $entityId = $data->get('entityId');
        $entityType = $data->get('entityType');
        $target = $data->get('target');

        if (!is_string($target)) {
            throw new RuntimeException("No target.");
        }

        if (!is_string($workflowId)) {
            throw new RuntimeException("No nextWorkflowId.");
        }

        if (!is_string($entityId)) {
            throw new RuntimeException("No entityId.");
        }

        if (!is_string($entityType)) {
            throw new RuntimeException("No entityType.");
        }

        $entity = $this->entityManager->getEntityById($entityType, $entityId);

        if (!$entity) {
            return;
        }

        $workflow = $this->entityManager->getRDBRepositoryByClass(Workflow::class)->getById($workflowId);

        if (!$workflow) {
            throw new RuntimeException("No workflow $workflowId.");
        }

        $targetEntityList = $this->targetProvider->get($entity, $target);

        foreach ($targetEntityList as $targetEntity) {
            try {
                $this->service->triggerWorkflow($targetEntity, $workflowId);
            }
            catch (Exception $e) {
                $this->log->error("Trigger workflow $workflowId for entity $entityId: " . $e->getMessage());
            }
        }
    }
}
