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

namespace Espo\Modules\Advanced\Core\ORM;

use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;

/**
 * Creates entities with custom defs. Need for supporting foreign fields like `linkName.attribute`.
 *
 * Not used as of Espo v9.1.7.
 */
class CustomEntityFactory
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private EntityManager $entityManager,
    ) {}

    /**
     * @param array<string, array<string, mixed>> $attributeDefs
     */
    public function create(string $entityType, array $attributeDefs): Entity
    {
        return $this->createImplementation($entityType, $attributeDefs);
    }

    /**
     * @param array<string, array<string, mixed>> $attributeDefs
     */
    private function createImplementation(string $entityType, array $attributeDefs): Entity
    {
        $seed = $this->entityManager->getEntityFactory()->create($entityType);

        $className = get_class($seed);

        $defs = $this->entityManager->getMetadata()->get($entityType);

        $defs['attributes'] = array_merge($defs['attributes'], $attributeDefs);

        $entity = $this->injectableFactory->createWith($className, [
            'entityType' => $entityType,
            'defs' => $defs,
            'valueAccessorFactory' => null,
        ]);

        if (!$entity instanceof Entity) {
            throw new RuntimeException();
        }

        return $entity;
    }
}
