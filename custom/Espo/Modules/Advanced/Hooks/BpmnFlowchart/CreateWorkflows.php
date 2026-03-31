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

namespace Espo\Modules\Advanced\Hooks\BpmnFlowchart;

use Espo\Modules\Advanced\Entities\BpmnFlowchart;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class CreateWorkflows
{
    public function __construct(private EntityManager $entityManager)
    {}

    /**
     * @param BpmnFlowchart $entity
     */
    public function afterSave(Entity $entity): void
    {
        $workflowList = $this->entityManager
            ->getRDBRepositoryByClass(Workflow::class)
            ->where([
                'flowchartId' => $entity->getId(),
                'isInternal' => true,
            ])
            ->find();

        $workflowsToRecreate = false;

        if (
            !$entity->isNew() &&
            json_encode($entity->get('data')) !== json_encode($entity->getFetched('data'))
        ) {
            $workflowsToRecreate = true;
        }

        if ($entity->isNew() || $workflowsToRecreate) {
            $this->removeWorkflows($entity);

            $data = $entity->get('data');

            if (isset($data->list) && is_array($data->list)) {
                foreach ($data->list as $item) {
                    if (!is_object($item)) {
                        continue;
                    }

                    $itId = $item->id ?? null;
                    $itType = $item->type ?? null;
                    $itTriggerType = $item->triggerType ?? null;
                    $itSignal = $item->signal ?? null;

                    if (
                        $itType === 'eventStartConditional' &&
                        in_array($itTriggerType, [
                            'afterRecordCreated',
                            'afterRecordSaved',
                            'afterRecordUpdated',
                        ])
                    ) {
                        $workflow = $this->entityManager->getNewEntity(Workflow::ENTITY_TYPE);

                        $conditionsAll = [];

                        if (isset($item->conditionsAll)) {
                            $conditionsAll = $item->conditionsAll;
                        }

                        $conditionsAny = [];

                        if (isset($item->conditionsAny)) {
                            $conditionsAny = $item->conditionsAny;
                        }

                        $conditionsFormula = null;

                        if (isset($item->conditionsFormula)) {
                            $conditionsFormula = $item->conditionsFormula;
                        }

                        $workflow->set([
                            'type' => $itTriggerType,
                            'entityType' => $entity->get('targetType'),
                            'isInternal' => true,
                            'flowchartId' => $entity->getId(),
                            'isActive' => $entity->get('isActive'),
                            'conditionsAll' => $conditionsAll,
                            'conditionsAny' => $conditionsAny,
                            'conditionsFormula' => $conditionsFormula,
                            'actions' => [
                                (object) [
                                    'type' => 'startBpmnProcess',
                                    'flowchartId' => $entity->getId(),
                                    'elementId' => $itId,
                                    'cid' => 0,
                                ]
                            ],
                            'processOrder' => 100,
                        ]);

                        $this->entityManager->saveEntity($workflow);
                    }

                    if (
                        $itType === 'eventStartSignal' &&
                        $itSignal
                    ) {
                        $workflow = $this->entityManager->getNewEntity(Workflow::ENTITY_TYPE);

                        $workflow->set([
                            'type' => Workflow::TYPE_SIGNAL,
                            'signalName' => $itSignal,
                            'entityType' => $entity->get('targetType'),
                            'isInternal' => true,
                            'flowchartId' => $entity->get('id'),
                            'isActive' => $entity->get('isActive'),
                            'actions' => [
                                (object) [
                                    'type' => 'startBpmnProcess',
                                    'flowchartId' => $entity->getId(),
                                    'elementId' => $itId,
                                    'cid' => 0,
                                ]
                            ],
                            'processOrder' => 150,
                        ]);

                        $this->entityManager->saveEntity($workflow);
                    }

                    if (
                        $itType === 'eventStartTimer' &&
                        !empty($item->targetReportId) &&
                        !empty($item->scheduling)
                    ) {
                        $workflow = $this->entityManager->getNewEntity(Workflow::ENTITY_TYPE);

                        $workflow->set([
                            'type' => Workflow::TYPE_SCHEDULED,
                            'entityType' => $entity->get('targetType'),
                            'isInternal' => true,
                            'flowchartId' => $entity->getId(),
                            'isActive' => $entity->get('isActive'),
                            'scheduling' => $item->scheduling,
                            'schedulingApplyTimezone' => $item->schedulingApplyTimezone ?? false,
                            'targetReportId' => $item->targetReportId,
                            'targetReportName' => $item->targetReportId,
                            'actions' => [
                                (object) [
                                    'type' => 'startBpmnProcess',
                                    'flowchartId' => $entity->getId(),
                                    'elementId' => $itId,
                                    'cid' => 0,
                                ]
                            ],
                            'processOrder' => 100,
                        ]);

                        $this->entityManager->saveEntity($workflow);
                    }
                }
            }
        }

        if (
            $entity->isAttributeChanged('isActive') &&
            !$entity->isNew() &&
            !$workflowsToRecreate
        ) {
            foreach ($workflowList as $workflow) {
                if ($workflow->get('isActive') !== $entity->get('isActive')) {
                    $workflow->set('isActive', $entity->get('isActive'));

                    $this->entityManager->saveEntity($workflow);
                }
            }
        }
    }

    private function removeWorkflows(Entity $entity): void
    {
        $workflowList = $this->entityManager
            ->getRDBRepository(Workflow::ENTITY_TYPE)
            ->where([
                'flowchartId' => $entity->getId(),
                'isInternal' => true,
            ])
            ->find();

        foreach ($workflowList as $workflow) {
            $this->entityManager->removeEntity($workflow);

            $this->entityManager
                ->getRDBRepository(Workflow::ENTITY_TYPE)
                ->deleteFromDb($workflow->getId());
        }
    }
}
