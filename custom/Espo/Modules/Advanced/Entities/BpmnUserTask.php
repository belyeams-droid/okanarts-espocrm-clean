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

use Espo\Core\Field\LinkMultiple;
use Espo\Core\Field\LinkParent;
use Espo\Core\ORM\Entity;

class BpmnUserTask extends Entity
{
    public const ENTITY_TYPE = 'BpmnUserTask';

    public const RESOLUTION_CANCELED = 'Canceled';

    public function getElementType(): string
    {
        return $this->get('elementType');
    }

    public function setName(?string $name): self
    {
        $this->set('name', $name);

        return $this;
    }

    public function setTeams(LinkMultiple $teams): self
    {
        $this->setValueObject('teams', $teams);

        return $this;
    }

    public function setTarget(?LinkParent $target): self
    {
        $this->setValueObject('target', $target);

        return $this;
    }

    public function setFlowNodeId(?string $flowNodeId): self
    {
        $this->set('flowNodeId', $flowNodeId);

        return $this;
    }

    public function setProcessId(?string $processId): self
    {
        $this->set('processId', $processId);

        return $this;
    }

    public function setActionType(?string $actionType): self
    {
        $this->set('actionType', $actionType);

        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->set('description', $description);

        return $this;
    }

    public function setInstructions(?string $instructions): self
    {
        $this->set('instructions', $instructions);

        return $this;
    }
}
