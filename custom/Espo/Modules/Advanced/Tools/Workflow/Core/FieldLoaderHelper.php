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

namespace Espo\Modules\Advanced\Tools\Workflow\Core;

use Espo\Core\FieldProcessing\SpecificFieldLoader;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\FieldUtil;
use Espo\ORM\Entity;

class FieldLoaderHelper
{
    /**
     * For bc the type is in the docblock.
     *
     * @var ?SpecificFieldLoader
     */
    private $specificFieldLoader = null;

    public function __construct(
        private InjectableFactory $injectableFactory,
        private FieldUtil $fieldUtil,
    ) {}

    public function load(Entity $entity, string $path): void
    {
        /** @phpstan-ignore-next-line function.alreadyNarrowedType */
        if (!method_exists($this->fieldUtil, 'getFieldOfAttribute')) {
            return;
        }

        $field = $this->fieldUtil->getFieldOfAttribute($entity->getEntityType(), $path);

        if (!$field) {
            return;
        }

        $loader = $this->getSpecificFieldLoader();

        if (!$loader) {
            return;
        }

        $loader->process($entity, $field);
    }

    private function getSpecificFieldLoader(): ?SpecificFieldLoader
    {
        if (!class_exists("Espo\\Core\\FieldProcessing\\SpecificFieldLoader")) {
            return null;
        }

        if (!$this->specificFieldLoader) {
            $this->specificFieldLoader = $this->injectableFactory->create(SpecificFieldLoader::class);
        }

        return $this->specificFieldLoader;
    }
}
