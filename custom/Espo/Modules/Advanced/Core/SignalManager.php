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

namespace Espo\Modules\Advanced\Core;

use Espo\Core\Utils\DateTime;
use Espo\Modules\Advanced\Entities\BpmnSignalListener;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Core\Utils\Config;

class SignalManager
{
    private EntityManager $entityManager;
    private WorkflowManager $workflowManager;
    private Config $config;

    public function __construct(
        EntityManager $entityManager,
        WorkflowManager $workflowManager,
        Config $config
    ) {
        $this->entityManager = $entityManager;
        $this->workflowManager = $workflowManager;
        $this->config = $config;
    }

    /**
     * @param string[]|string $signal A signal.
     * @param array<string, mixed> $options Save options.
     * @param ?array<string, mixed> $params Signal params.
     */
    public function trigger($signal, ?Entity $entity = null, array $options = [], ?array $params = null): void
    {
        if (is_array($signal)) {
            $signal = implode('.', $signal);
        }

        if ($this->config->get('signalsDisabled')) {
            return;
        }

        if ($entity) {
            $this->workflowManager->processSignal($entity, $signal, $params, $options);

            return;
        }

        if ($this->config->get('signalsRegularDisabled')) {
            return;
        }

        $listenerList = $this->entityManager
            ->getRDBRepository(BpmnSignalListener::ENTITY_TYPE)
            ->select(['id'])
            ->order('number')
            ->where([
                'name' => $signal,
                'isTriggered' => false,
            ])
            ->find();

        foreach ($listenerList as $item) {
            $item->set('isTriggered', true);
            $item->set('triggeredAt', date(DateTime::SYSTEM_DATE_TIME_FORMAT));

            $this->entityManager->saveEntity($item);
        }
    }

    public function subscribe(string $signal, string $flowNodeId): ?string
    {
        if ($this->config->get('signalsDisabled')) {
            return null;
        }

        if ($this->config->get('signalsRegularDisabled')) {
            return null;
        }

        $item = $this->entityManager->createEntity(BpmnSignalListener::ENTITY_TYPE, [
            'name' => $signal,
            'flowNodeId' => $flowNodeId,
        ]);

        return $item->getId();
    }

    public function unsubscribe(string $id): void
    {
        $this->entityManager
            ->getRDBRepositoryByClass(BpmnSignalListener::class)
            ->deleteFromDb($id);
    }
}
