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

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\ServiceFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Workflow\Core\EntityHelper;
use Espo\Modules\Advanced\Tools\Workflow\Core\FieldValueHelper;
use Espo\Modules\Advanced\Core\Workflow\Helper;
use Espo\Modules\Advanced\Core\Workflow\Utils;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\Modules\Advanced\Tools\Workflow\Core\RecipientIds;
use Espo\Modules\Advanced\Tools\Workflow\Core\RecipientProvider;
use Espo\Modules\Advanced\Tools\Workflow\Core\TargetProvider;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

use RuntimeException;
use stdClass;

abstract class Base
{
    private ?string $workflowId = null;
    protected ?CoreEntity $entity = null;
    protected ?stdClass $action = null;
    protected ?stdClass $createdEntitiesData = null;
    protected bool $createdEntitiesDataIsChanged = false;
    protected ?stdClass $variables = null;
    protected ?stdClass $preparedVariables = null;
    protected ?BpmnProcess $bpmnProcess = null;

    public function __construct(
        protected EntityManager $entityManager,
        protected InjectableFactory $injectableFactory,
        protected ServiceFactory $serviceFactory,
        protected Metadata $metadata,
        protected Config $config,
        protected FormulaManager $formulaManager,
        protected User $user,
        protected Helper $workflowHelper,
        protected EntityHelper $entityHelper,
        protected FieldValueHelper $fieldValueHelper,
        protected Log $log,
    ) {}

    /**
     * @param array<string, mixed> $options Save options.
     */
    abstract protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool;

    /**
     * @param array<string, mixed> $options
     */
    public function process(
        Entity $entity,
        stdClass $actionData,
        ?stdClass $createdEntitiesData = null,
        ?stdClass $variables = null,
        ?BpmnProcess $bpmnProcess = null,
        array $options = [],
    ): void {

        if (!$entity instanceof CoreEntity) {
            throw new RuntimeException();
        }

        $this->entity = $entity;
        $this->action = $actionData;
        $this->createdEntitiesData = $createdEntitiesData;
        $this->variables = $variables;
        $this->bpmnProcess = $bpmnProcess;

        if (!property_exists($actionData, 'cid')) {
            $actionData->cid = 0;
        }

        $cid = $actionData->cid ?? 0;
        $actionType = $actionData->type;

        $this->debugLog('Start', $actionType, $cid, $entity);

        $result = $this->run($entity, $actionData, $options);

        $this->debugLog('End', $actionType, $cid, $entity);

        if (!$result) {
            $this->debugLog('Failed', $actionType, $cid, $entity);
        }
    }

    private function debugLog(string $type, string $actionType, int $cid, Entity $entity): void
    {
        $id = $entity->hasId() ? $entity->getId() : '(new)';

        $message = "Workflow {$this->getWorkflowId()}, $actionType, $type, cid $cid, {$entity->getEntityType()} $id";

        $GLOBALS['log']->debug($message);
    }

    public function isCreatedEntitiesDataChanged(): bool
    {
        return $this->createdEntitiesDataIsChanged;
    }

    public function getCreatedEntitiesData(): stdClass
    {
        return $this->createdEntitiesData;
    }

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

    protected function getActionData(): stdClass
    {
        return $this->action;
    }

    protected function clearSystemVariables(stdClass $variables): void
    {
        unset($variables->__targetEntity);
        unset($variables->__processEntity);
        unset($variables->__createdEntitiesData);
    }

    /**
     * Return variables. Can be changed after action is processed.
     */
    public function getVariablesBack(): stdClass
    {
        $variables = clone $this->variables;

        $this->clearSystemVariables($variables);

        return $variables;
    }

    /**
     * Get variables for usage within an action.
     */
    public function getVariables(): stdClass
    {
        $variables = clone $this->getFormulaVariables();

        $this->clearSystemVariables($variables);

        return $variables;
    }

    protected function hasVariables(): bool
    {
        return !!$this->variables;
    }

    protected function updateVariables(stdClass $variables): void
    {
        if (!$this->hasVariables()) {
            return;
        }

        $variables = clone $variables;

        $this->clearSystemVariables($variables);

        foreach (get_object_vars($variables) as $k => $v) {
            $this->variables->$k = $v;
        }
    }

    protected function getFormulaVariables(): stdClass
    {
        if (!$this->preparedVariables) {
            $o = (object) [];

            $o->__targetEntity = $this->getEntity();

            if ($this->bpmnProcess) {
                $o->__processEntity = $this->bpmnProcess;
            }

            if ($this->createdEntitiesData) {
                $o->__createdEntitiesData = $this->createdEntitiesData;
            }

            if ($this->variables) {
                foreach (get_object_vars($this->variables) as $k => $v) {
                    $o->$k = $v;
                }
            }

            $this->preparedVariables = $o;
        }

        return $this->preparedVariables;
    }

    /**
     * Get execute time defined in workflow
     *
     * @param stdClass $data
     * @throws Error
     */
    protected function getExecuteTime($data): string
    {
        $executeTime = date(DateTime::SYSTEM_DATE_TIME_FORMAT);

        if (!property_exists($data, 'execution')) {
            return $executeTime;
        }

        $execution = $data->execution;

        switch ($execution->type) {
            case 'immediately':
                return $executeTime;

            case 'later':
                $field = $execution->field ?? null;

                if ($field) {
                    $executeTime = $this->fieldValueHelper->getValue($this->getEntity(), $field);

                    $attributeType = Utils::getAttributeType($this->getEntity(), $field);
                    $timezone = $this->config->get('timeZone') ?? 'UTC';

                    if ($attributeType === 'date') {
                        $executeTime = (new DateTimeImmutable($executeTime))
                            ->setTimezone(new DateTimeZone($timezone))
                            ->setTime(0, 0)
                            ->setTimezone(new DateTimeZone('UTC'))
                            ->format(DateTime::SYSTEM_DATE_TIME_FORMAT);
                    }
                }

                $execution->shiftDays = $execution->shiftDays ?? 0;
                $shiftUnit = $execution->shiftUnit ?? 'days';

                $executeTime = Utils::shiftDays(
                    $execution->shiftDays,
                    $executeTime,
                    'datetime',
                    $shiftUnit
                );

                break;

            default:
                throw new Error("Workflow[{$this->getWorkflowId()}]: Unknown execution type [$execution->type]");
        }

        return $executeTime;
    }

    protected function getCreatedEntity(string $target): ?Entity
    {
        $provider = $this->injectableFactory->create(TargetProvider::class);

        return $provider->getCreated($target, $this->createdEntitiesData);
    }

    /**
     * @throws Error
     */
    protected function getFirstTargetFromTargetItem(Entity $entity, ?string $target): ?Entity
    {
        foreach ($this->getTargetsFromTargetItem($entity, $target) as $it) {
            return $it;
        }

        return null;
    }

    /**
     * @return iterable<Entity>
     * @throws Error
     */
    protected function getTargetsFromTargetItem(Entity $entity, ?string $target): iterable
    {
        $provider = $this->injectableFactory->create(TargetProvider::class);

        return $provider->get($entity, $target, $this->createdEntitiesData);
    }

    protected function getRecipients(Entity $entity, string $target): RecipientIds
    {
        $provider = $this->injectableFactory->create(RecipientProvider::class);

        return $provider->get($entity, $target);
    }
}
