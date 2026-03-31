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

namespace Espo\Modules\Advanced\Core\Workflow;

use Espo\Core\Exceptions\Error;

use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Log;
use Espo\Modules\Advanced\Core\Bpmn\Utils\ConditionManager as BpmnConditionManager;

abstract class BaseManager
{
    protected string $dirName = 'Dummy';
    private ?string $processId = null;
    /** @var ?array<string, CoreEntity> */
    private ?array $entityMap = null;
    /** @var ?array<string, string> */
    private ?array $workflowIdList = null;
    /** @var array<string, class-string<Actions\Base|Conditions\Base>> */
    private array $actionClassNameMap = [];
    /** @var string[] */
    protected array $requiredOptions = [];

    public function __construct(
        protected Log $log,
        private InjectableFactory $injectableFactory,
        protected FormulaManager $formulaManager,
        protected BpmnConditionManager $conditionManager,
    ) {}

    public function setInitData(string $workflowId, CoreEntity $entity): void
    {
        $this->processId = $workflowId . '-'. $entity->getId();

        $this->workflowIdList[$this->processId] = $workflowId;
        $this->entityMap[$this->processId] = $entity;
    }

    /**
     * @throws Error
     */
    protected function getProcessId(): ?string
    {
        if (empty($this->processId)) {
            throw new Error('Workflow['.__CLASS__.'], getProcessId(): Empty processId.');
        }

        return $this->processId;
    }

    /**
     * @throws Error
     */
    protected function getWorkflowId(?string $processId = null): string
    {
        if (!isset($processId)) {
            $processId = $this->getProcessId();
        }

        if (empty($this->workflowIdList[$processId])) {
            throw new Error('Workflow['.__CLASS__.'], getWorkflowId(): Empty workflowId.');
        }

        return $this->workflowIdList[$processId];
    }

    /**
     * @throws Error
     */
    protected function getEntity(?string $processId = null): CoreEntity
    {
        if (!isset($processId)) {
            $processId = $this->getProcessId();
        }

        if (empty($this->entityMap[$processId])) {
            throw new Error('Workflow[' . __CLASS__ . '], getEntity(): Empty Entity object.');
        }

        return $this->entityMap[$processId];
    }

    /**
     * @return class-string<Actions\Base|Conditions\Base>
     * @throws Error
     */
    private function getClassName(string $name): string
    {
        if (!isset($this->actionClassNameMap[$name])) {
            $className = 'Espo\Custom\Modules\Advanced\Core\Workflow\\' . ucfirst($this->dirName) . '\\' . $name;

            if (!class_exists($className)) {
                $className .=  'Type';

                if (!class_exists($className)) {
                    $className = 'Espo\Modules\Advanced\Core\Workflow\\' . ucfirst($this->dirName) . '\\' . $name;

                    if (!class_exists($className)) {
                        $className .=  'Type';

                        if (!class_exists($className)) {
                            throw new Error('Class ['.$className.'] does not exist.');
                        }
                    }
                }
            }

            /** @var class-string<Actions\Base|Conditions\Base> $className */

            $this->actionClassNameMap[$name] = $className;
        }

        return $this->actionClassNameMap[$name];
    }

    /**
     * @return Actions\Base|Conditions\Base
     * @throws Error
     */
    protected function createConditionOrAction(string $name, ?string $processId = null): object
    {
        $name = ucfirst($name);

        $name = str_replace("\\", "", $name);

        if (!isset($processId)) {
            $processId = $this->getProcessId();
        }

        $workflowId = $this->getWorkflowId($processId);

        $className = $this->getClassName($name);

        /** @var Actions\Base|Conditions\Base $obj */
        $obj = $this->injectableFactory->create($className);

        $obj->setWorkflowId($workflowId);

        return $obj;
    }

    protected function validate(object $options): bool
    {
        foreach ($this->requiredOptions as $optionName) {
            if (!property_exists($options, $optionName)) {
                return false;
            }
        }

        return true;
    }
}
