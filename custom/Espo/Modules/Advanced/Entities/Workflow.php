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

namespace Espo\Modules\Advanced\Entities;

use Espo\Core\Field\DateTime;
use Espo\Core\ORM\Entity;
use stdClass;

class Workflow extends Entity
{
    public const ENTITY_TYPE = 'Workflow';

    public const TYPE_MANUAL = 'manual';
    public const TYPE_SCHEDULED = 'scheduled';
    public const TYPE_SEQUENTIAL = 'sequential';
    public const TYPE_SIGNAL = 'signal';
    public const TYPE_AFTER_RECORD_SAVED = 'afterRecordSaved';
    public const TYPE_AFTER_RECORD_CREATED = 'afterRecordCreated';
    public const TYPE_AFTER_RECORD_UPDATED = 'afterRecordUpdated';

    public const MANUAL_ACCESS_ADMIN = 'admin';
    public const MANUAL_ACCESS_READ = 'read';

    public function getName(): ?string
    {
        return $this->get('name');
    }

    public function getManualLabel(): ?string
    {
        return $this->get('manualLabel');
    }

    public function getType(): string
    {
        return $this->get('type');
    }

    public function getTargetEntityType(): string
    {
        return $this->get('entityType');
    }

    public function isActive(): bool
    {
        return $this->get('isActive');
    }

    public function getScheduling(): ?string
    {
        return $this->get('scheduling');
    }

    public function getSignalName(): ?string
    {
        return $this->get('signalName');
    }

    public function getManualAccessRequired(): ?string
    {
        return $this->get('manualAccessRequired');
    }

    /**
     * @return ?stdClass[]
     */
    public function getManualDynamicLogicConditionGroup(): ?array
    {
        $value = $this->get('manualDynamicLogic');

        if (!$value instanceof stdClass) {
            return null;
        }

        return $value->conditionGroup ?? [];
    }

    public function getSchedulingApplyTimezone(): bool
    {
        return (bool) $this->get('schedulingApplyTimezone');
    }

    public function getLastRun(): ?DateTime
    {
        /** @var ?DateTime */
        return $this->getValueObject('lastRun');
    }

    public function setLastRun(?DateTime $lastRun): self
    {
        $this->setValueObject('lastRun', $lastRun);

        return $this;
    }
}
