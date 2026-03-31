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

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\Advanced\Tools\Workflow\Service;
use Espo\ORM\EntityManager;

use RuntimeException;

class RunScheduledWorkflowForEntity implements Job
{
    private EntityManager $entityManager;
    private Service $service;

    public function __construct(
        EntityManager $entityManager,
        Service $service
    ) {
        $this->entityManager = $entityManager;
        $this->service = $service;
    }

    public function run(Data $data): void
    {
        $data = $data->getRaw();

        $entityType = $data->entityType;
        $id = $data->entityId;
        $workflowId = $data->workflowId;

        $entity = $this->entityManager->getEntityById($entityType, $id);

        if (!$entity) {
            throw new RuntimeException("Workflow $workflowId: Entity $entityType $id  not found.");
        }

        $this->service->triggerWorkflow($entity, $workflowId);
    }
}
