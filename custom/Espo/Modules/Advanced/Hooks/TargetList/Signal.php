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

namespace Espo\Modules\Advanced\Hooks\TargetList;

use Espo\Modules\Advanced\Core\SignalManager;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class Signal
{
    /** @var int */
    public static $order = 100;

    public function __construct(
        private SignalManager $signalManager,
        private EntityManager $entityManager
    ) {}

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $hookData
     */
    public function afterOptOut(Entity $entity, array $options, array $hookData): void
    {
        if (!empty($options['skipWorkflow'])) {
            return;
        }

        if (!empty($options['skipSignal'])) {
            return;
        }

        if (!empty($options['silent'])) {
            return;
        }

        $targetType = $hookData['targetType'];
        $targetId = $hookData['targetId'];

        $target = $this->entityManager->getEntityById($targetType, $targetId);

        if (!$target) {
            return;
        }

        $this->signalManager->trigger(implode('.', ['@optOut', $entity->getId()]), $target);
        $this->signalManager->trigger(
            implode('.', ['optOut', $target->getEntityType(), $target->getId(), $entity->getId()]));
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $hookData
     */
    public function afterCancelOptOut(Entity $entity, array $options, array $hookData): void
    {
        if (!empty($options['skipWorkflow'])) {
            return;
        }

        if (!empty($options['skipSignal'])) {
            return;
        }

        if (!empty($options['silent'])) {
            return;
        }

        $targetType = $hookData['targetType'];
        $targetId = $hookData['targetId'];

        $target = $this->entityManager->getEntityById($targetType, $targetId);

        if (!$target) {
            return;
        }

        $this->signalManager->trigger(implode('.', ['@cancelOptOut', $entity->getId()]), $target);
        $this->signalManager->trigger(
            implode('.', ['cancelOptOut', $target->getEntityType(), $target->getId(), $entity->getId()]));
    }
}
