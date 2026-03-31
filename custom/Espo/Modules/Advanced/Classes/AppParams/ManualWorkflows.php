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

namespace Espo\Modules\Advanced\Classes\AppParams;

use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class ManualWorkflows
{
    private EntityManager $entityManager;
    private User $user;

    public function __construct(
        EntityManager $entityManager,
        User $user
    ) {
        $this->entityManager = $entityManager;
        $this->user = $user;
    }

    /**
     * @return stdClass
     */
    public function get()
    {
        $data = (object) [];

        $builder = $this->entityManager
            ->getRDBRepositoryByClass(Workflow::class)
            ->where([
                'type' => Workflow::TYPE_MANUAL,
                'isActive' => true,
            ]);

        if (!$this->user->isAdmin()) {
            $builder
                ->distinct()
                ->join('manualTeams')
                ->where(['manualTeams.id' => $this->user->getTeamIdList()]);

            $builder->where(['manualAccessRequired!=' => 'admin']);
        }

        /** @var Workflow[] $workflows */
        $workflows = iterator_to_array($builder->find());

        usort($workflows, function (Workflow $a, Workflow $b) {
            return strcmp($a->getManualLabel() ?? '', $b->getManualLabel() ?? '');
        });

        foreach ($workflows as $workflow) {
            $entityType = $workflow->getTargetEntityType();

            if (!property_exists($data, $entityType)) {
                $data->$entityType = [];
            }

            $item = (object) [
                'id' => $workflow->getId(),
                'label' => $workflow->get('manualLabel'),
                'accessRequired' => $workflow->get('manualAccessRequired'),
                'elementType' => $workflow->get('manualElementType'),
                'dynamicLogic' => $workflow->get('manualDynamicLogic'),
                'confirmation' => $workflow->get('manualConfirmation'),
                'confirmationText' => $workflow->get('manualConfirmationText'),
                'style' => $workflow->get('manualStyle'),
            ];

            $data->$entityType[] = $item;
        }

        return $data;
    }
}
