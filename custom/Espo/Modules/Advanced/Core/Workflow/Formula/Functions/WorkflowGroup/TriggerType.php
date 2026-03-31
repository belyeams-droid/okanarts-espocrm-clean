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

namespace Espo\Modules\Advanced\Core\Workflow\Formula\Functions\WorkflowGroup;

use Espo\Core\Formula\ArgumentList;
use Espo\Core\Formula\Functions\BaseFunction;
use Espo\Modules\Advanced\Tools\Workflow\Service;
use Espo\Core\Exceptions\Error;
use Espo\Core\Di\InjectableFactoryAware;
use Espo\Core\Di\InjectableFactorySetter;
use Espo\Core\Di\EntityManagerAware;
use Espo\Core\Di\EntityManagerSetter;

class TriggerType extends BaseFunction implements

    InjectableFactoryAware,
    EntityManagerAware
{
    use InjectableFactorySetter;
    use EntityManagerSetter;

    public function process(ArgumentList $args)
    {
        $args = $this->evaluate($args);

        $entityType = $args[0] ?? null;
        $id = $args[1] ?? null;
        $workflowId = $args[2] ?? null;

        if (!$entityType) {
            throw new Error("No entity type.");
        }

        if (!$id) {
            throw new Error("No ID.");
        }

        if (!$workflowId) {
            throw new Error("No workflowId.");
        }

        $entity = $this->entityManager->getEntityById($entityType, $id);

        if (!$entity) {
            throw new Error("Entity $entityType $id not found.");
        }

        $this->getWorkflowService()->triggerWorkflow($entity, $workflowId, true);
    }

    private function getWorkflowService(): Service
    {
        return $this->injectableFactory->create(Service::class);
    }
}
