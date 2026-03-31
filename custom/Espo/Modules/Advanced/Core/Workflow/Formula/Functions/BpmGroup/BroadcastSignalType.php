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

namespace Espo\Modules\Advanced\Core\Workflow\Formula\Functions\BpmGroup;

use Espo\Core\Di\EntityManagerAware;
use Espo\Core\Di\EntityManagerSetter;
use Espo\Core\Di\InjectableFactoryAware;
use Espo\Core\Di\InjectableFactorySetter;
use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\ArgumentList;
use Espo\Core\Formula\Functions\BaseFunction;
use Espo\Modules\Advanced\Core\SignalManager;

class BroadcastSignalType extends BaseFunction implements InjectableFactoryAware, EntityManagerAware
{
    use InjectableFactorySetter;
    use EntityManagerSetter;

    public function process(ArgumentList $args)
    {
        $args = $this->evaluate($args);

        $signal = $args[0] ?? null;
        $entityType = $args[1] ?? null;
        $id = $args[2] ?? null;

        if (!$signal) {
            throw new Error("Formula: bpm\\broadcastSignal: No signal name.");
        }

        if (!is_string($signal)) {
            throw new Error("Formula: bpm\\broadcastSignal: Bad signal name.");
        }

        $entity = null;

        if ($entityType && $id) {
            $entity = $this->entityManager->getEntityById($entityType, $id);

            if (!$entity) {
                throw new Error("Formula: bpm\\broadcastSignal: The entity does not exist.");
            }
        }

        $this->getSignalManager()->trigger($signal, $entity);
    }

    private function getSignalManager(): SignalManager
    {
        return $this->injectableFactory->create(SignalManager::class);
    }
}
