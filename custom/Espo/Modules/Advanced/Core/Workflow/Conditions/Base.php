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

namespace Espo\Modules\Advanced\Core\Workflow\Conditions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Config;
use Espo\Modules\Advanced\Tools\Workflow\Core\FieldValueHelper;
use Espo\Modules\Advanced\Core\Workflow\Utils;
use Espo\ORM\EntityManager;

use stdClass;

abstract class Base
{
    private ?string $workflowId = null;
    protected ?CoreEntity $entity = null;
    protected ?stdClass $condition = null;
    protected ?stdClass $createdEntitiesData = null;

    public function __construct(
        protected EntityManager $entityManager,
        protected Config $config,
        protected FieldValueHelper $fieldValueHelper,
    ) {}

    protected function compareComplex(CoreEntity $entity, stdClass $condition): bool
    {
        return false;
    }

    /**
     * @param mixed $fieldValue
     */
    abstract protected function compare($fieldValue): bool;

    public function setWorkflowId(?string $workflowId): void
    {
        $this->workflowId = $workflowId;
    }

    protected function getWorkflowId(): ?string
    {
        return $this->workflowId;
    }

    protected function getEntity(): CoreEntity
    {
        return $this->entity;
    }

    protected function getCondition(): stdClass
    {
        return $this->condition;
    }

    public function process(CoreEntity $entity, stdClass $condition, ?stdClass $createdEntitiesData = null): bool
    {
        $this->entity = $entity;
        $this->condition = $condition;
        $this->createdEntitiesData = $createdEntitiesData;

        if (!empty($condition->fieldValueMap)) {
            return $this->compareComplex($entity, $condition);
        } else {
            $fieldName = $this->getFieldName();

            if (isset($fieldName)) {
                return $this->compare($this->getFieldValue());
            }
        }

        return false;
    }

    /**
     * Get field name based on fieldToCompare value.
     *
     * @return ?string
     */
    protected function getFieldName()
    {
        $condition = $this->getCondition();

        if (isset($condition->fieldToCompare)) {
            $entity = $this->getEntity();
            $field = $condition->fieldToCompare;

            $normalizeFieldName = Utils::normalizeFieldName($entity, $field);

            if (is_array($normalizeFieldName)) { //if field is parent
                return reset($normalizeFieldName) ?: null;
            }

            return $normalizeFieldName;
        }

        return null;
    }

    /**
     * @return ?string
     */
    protected function getAttributeName()
    {
        return $this->getFieldName();
    }

    /**
     * @return mixed
     */
    protected function getAttributeValue()
    {
        return $this->getFieldValue();
    }

    /**
     * Get value of fieldToCompare field.
     *
     * @todo Use loader for not set fields.
     *   Only for BPM.
     *
     * @return mixed
     */
    protected function getFieldValue()
    {
        $entity = $this->getEntity();
        $condition = $this->getCondition();

        return $this->fieldValueHelper->getValue(
            entity: $entity,
            path: $condition->fieldToCompare,
            createdEntitiesData: $this->createdEntitiesData,
        );
    }

    /**
     * Get value of subject field.
     *
     * @throws Error
     */
    protected function getSubjectValue(): mixed
    {
        $entity = $this->getEntity();
        $condition = $this->getCondition();

        $subjectType = $condition->subjectType ?? null;

        if ($subjectType === null) {
            return null;
        }

        if ($subjectType === 'value') {
            return $condition->value ?? null;
        }

        if ($subjectType === 'field') {
            $subjectValue = $this->fieldValueHelper->getValue($entity, $condition->field);

            if (
                isset($condition->shiftDays) ||
                isset($condition->shiftUnits)
            ) {
                return Utils::shiftDays(
                    shiftDays: $condition->shiftDays ?? 0,
                    input: $subjectValue,
                    type: 'date',
                    unit: $condition->shiftUnits ?? 'days',
                    timezone: $this->config->get('timeZone'),
                );
            }

            return $subjectValue;
        }

        if ($subjectType === 'today') {
            return Utils::shiftDays(
                shiftDays: $condition->shiftDays ?? 0,
                type: 'date',
                unit: $condition->shiftUnits ?? 'days',
                timezone: $this->config->get('timeZone'),
            );
        }

        throw new Error("Workflow[{$this->getWorkflowId()}]: Non supported type '$subjectType'.");
    }
}
