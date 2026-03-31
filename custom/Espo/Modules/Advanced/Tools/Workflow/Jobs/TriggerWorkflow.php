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
use Espo\Modules\Advanced\Tools\Workflow\Service;
use Espo\ORM\EntityManager;

class TriggerWorkflow implements Job
{
    public function __construct(
        private Service $service,
        private EntityManager $entityManager
    ) {}

    public function run(Data $data): void
    {
        $data = $data->getRaw();

        if (
            empty($data->entityId) ||
            empty($data->entityType) ||
            empty($data->nextWorkflowId)
        ) {
            throw new Error("Workflow[$data->workflowId][triggerWorkflow]: Not sufficient job data.");
        }

        $entityId = $data->entityId;
        $entityType = $data->entityType;

        $entity = $this->entityManager->getEntityById($entityType, $entityId);

        if (!$entity) {
            throw new Error("Workflow[$data->workflowId][triggerWorkflow]: Entity not found.");
        }

        $values = $data->values ?? null;

        if (is_object($values)) {
            $values = get_object_vars($values);

            foreach ($values as $attribute => $value) {
                $entity->setFetched($attribute, $value);
            }
        }

        $this->service->triggerWorkflow($entity, $data->nextWorkflowId, true);
    }
}
