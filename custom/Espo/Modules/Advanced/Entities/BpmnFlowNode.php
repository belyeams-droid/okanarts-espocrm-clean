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

use Espo\Core\ORM\Entity;
use stdClass;

class BpmnFlowNode extends Entity
{
    public const ENTITY_TYPE = 'BpmnFlowNode';

    public const STATUS_CREATED = 'Created';
    public const STATUS_IN_PROCESS = 'In Process';
    public const STATUS_PENDING = 'Pending';
    public const STATUS_STANDBY = 'Standby';
    public const STATUS_FAILED = 'Failed';
    public const STATUS_PROCESSED = 'Processed';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_INTERRUPTED = 'Interrupted';

    public function getStatus(): ?string
    {
        return $this->get('status');
    }

    public function getProcessId(): ?string
    {
        return $this->get('processId');
    }

    public function getTargetId(): ?string
    {
        return $this->get('targetId');
    }

    public function getTargetType(): ?string
    {
        return $this->get('targetType');
    }

    public function getElementType(): ?string
    {
        return $this->get('elementType');
    }

    public function getElementId(): ?string
    {
        return $this->get('elementId');
    }

    public function getFlowchartId(): ?string
    {
        return $this->get('flowchartId');
    }

    public function getElementData(): stdClass
    {
        return $this->get('elementData') ?? (object) [];
    }

    public function getDivergentFlowNodeId(): ?string
    {
        return $this->get('divergentFlowNodeId');
    }

    public function getPreviousFlowNodeId(): ?string
    {
        return $this->get('previousFlowNodeId');
    }

    public function getPreviousFlowNodeElementType(): ?string
    {
        return $this->get('previousFlowNodeElementType');
    }

    /**
     * @return mixed
     */
    public function getElementDataItemValue(string $name)
    {
        $data = $this->get('elementData');

        if (!$data) {
            $data = (object) [];
        }

        if (!property_exists($data, $name)) {
            return null;
        }

        return $data->$name;
    }

    /**
     * @return mixed
     */
    public function getDataItemValue(string $name)
    {
        $data = $this->get('data');

        if (!$data) {
            $data = (object) [];
        }

        if (!property_exists($data, $name)) {
            return null;
        }

        return $data->$name;
    }

    /**
     * @param mixed $value
     */
    public function setDataItemValue(string $name, $value): void
    {
        $data = $this->get('data');

        if (!$data) {
            $data = (object) [];
        }

        $data->$name = $value;

        $this->set('data', $data);
    }

    public function setStatus(string $status): self
    {
        $this->set('status', $status);

        return $this;
    }

    public function getData(): stdClass
    {
        return $this->get('data') ?? (object) [];
    }
}
