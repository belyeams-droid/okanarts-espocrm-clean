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

namespace Espo\Modules\Advanced\Services;

use Espo\Core\Exceptions\Error;
use Espo\Modules\Advanced\Entities\ReportPanel as ReportPanelEntity;
use Espo\Modules\Advanced\Tools\ReportPanel\Service as PanelService;
use Espo\ORM\Entity;
use Espo\Services\Record;

/**
 * @extends Record<ReportPanelEntity>
 */
class ReportPanel extends Record
{
    /**
     * For bc.
     * @todo Remove.
     * @var bool
     */
    protected $forceSelectAllAttributes = true;

    /**
     * @throws Error
     */
    protected function afterCreateEntity(Entity $entity, $data)
    {
        $this->rebuild($entity->get('entityType'));
    }

    /**
     * @throws Error
     */
    protected function afterUpdateEntity(Entity $entity, $data)
    {
        $this->rebuild($entity->get('entityType'));
    }

    /**
     * @throws Error
     */
    protected function afterDeleteEntity(Entity $entity)
    {
        $this->rebuild($entity->get('entityType'));
    }

    /**
     * @throws Error
     */
    private function rebuild(?string $entityType = null): void
    {
        $this->injectableFactory
            ->create(PanelService::class)
            ->rebuild($entityType);
    }
}
