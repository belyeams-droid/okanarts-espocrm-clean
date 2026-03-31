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

namespace Espo\Modules\Advanced\Hooks\Workflow;

use Espo\Entities\Job;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\Modules\Advanced\Tools\Workflow\Jobs\RunScheduledWorkflow as RunScheduledWorkflowJob;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class RemoveJobs
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @param Workflow $entity
     */
    public function afterSave(Entity $entity): void
    {
        $toRemove = $entity->getType() === Workflow::TYPE_SCHEDULED &&
            (
                $entity->isAttributeChanged('scheduling') ||
                $entity->isAttributeChanged('schedulingApplyTimezone')
            );

        if (!$toRemove) {
            return;
        }

        $pendingJobList = $this->entityManager
            ->getRDBRepository(Job::ENTITY_TYPE)
            ->where([
                'className' => RunScheduledWorkflowJob::class,
                'status' => 'Pending',
                'targetType' => Workflow::ENTITY_TYPE,
                'targetId' => $entity->getId(),
            ])
            ->find();

        foreach ($pendingJobList as $pendingJob) {
            $this->entityManager->removeEntity($pendingJob);
        }
    }
}
