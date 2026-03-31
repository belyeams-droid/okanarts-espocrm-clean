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

namespace Espo\Modules\Advanced\Hooks\BpmnUserTask;

use Espo\Core\InjectableFactory;
use Espo\Modules\Advanced\Core\Bpmn\BpmnManager;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Modules\Advanced\Entities\BpmnUserTask;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class Resolve
{
    private EntityManager $entityManager;
    private InjectableFactory $injectableFactory;

    public function __construct(
        EntityManager $entityManager,
        InjectableFactory $injectableFactory
    ) {
        $this->entityManager = $entityManager;
        $this->injectableFactory = $injectableFactory;
    }

    /**
     * @param BpmnUserTask $entity
     */
    public function afterSave(Entity $entity): void
    {
        $flowNodeId = $entity->get('flowNodeId');

        if (!$flowNodeId) {
            return;
        }

        if (!$entity->getFetched('isResolved') && $entity->get('isResolved')) {
            /** @var ?BpmnFlowNode $flowNode */
            $flowNode = $this->entityManager->getEntityById(BpmnFlowNode::ENTITY_TYPE, $flowNodeId);

            if (!$flowNode) {
                return;
            }

            if ($flowNode->getStatus() !== BpmnFlowNode::STATUS_IN_PROCESS) {
                return;
            }

            $manager = $this->injectableFactory->create(BpmnManager::class);

            $manager->completeFlow($flowNode);
        }
    }
}
