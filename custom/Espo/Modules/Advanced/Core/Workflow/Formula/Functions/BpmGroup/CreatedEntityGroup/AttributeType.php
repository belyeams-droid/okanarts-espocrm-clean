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

namespace Espo\Modules\Advanced\Core\Workflow\Formula\Functions\BpmGroup\CreatedEntityGroup;

use Espo\Core\Di\EntityManagerAware;
use Espo\Core\Di\EntityManagerSetter;
use Espo\Core\Di\InjectableFactoryAware;
use Espo\Core\Di\InjectableFactorySetter;
use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\ArgumentList;
use Espo\Core\Formula\AttributeFetcher;
use Espo\Core\Formula\Exceptions\ExecutionException;
use Espo\Core\Formula\Functions\BaseFunction;

use RuntimeException;

class AttributeType extends BaseFunction implements EntityManagerAware, InjectableFactoryAware
{
    use EntityManagerSetter;
    use InjectableFactorySetter;

    /**
     * @throws \Espo\Core\Formula\Exceptions\Error
     * @throws ExecutionException
     * @throws Error
     */
    public function process(ArgumentList $args)
    {
        $args = $this->evaluate($args);

        if (count($args) < 2) {
            throw new RuntimeException("Formula bpm\createdEntity\\attribute: Too few arguments.");
        }

        $aliasId = $args[0];
        $attribute = $args[1];

        if (!is_string($aliasId) || !is_string($attribute)) {
            throw new Error("Formula bpm\createdEntity\\attribute: Bad argument.");
        }

        $variables = $this->getVariables();

        if (!isset($variables->__createdEntitiesData)) {
            throw new Error("Formula bpm\createdEntity\\attribute: Can't be used out of BPM process.");
        }

        if (!isset($variables->__createdEntitiesData->$aliasId)) {
            throw new Error("Formula bpm\createdEntity\\attribute: Unknown aliasId.");
        }

        $entityType = $variables->__createdEntitiesData->$aliasId->entityType;
        $entityId = $variables->__createdEntitiesData->$aliasId->entityId;

        $entity = $this->entityManager->getEntityById($entityType, $entityId);

        $attributeFetched = $this->injectableFactory->create(AttributeFetcher::class);

        return $attributeFetched->fetch($entity, $attribute);
    }
}
