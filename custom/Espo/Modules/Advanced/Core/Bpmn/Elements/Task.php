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

namespace Espo\Modules\Advanced\Core\Bpmn\Elements;

use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Modules\Advanced\Core\Workflow\Actions\Base as BaseAction;

use Throwable;
use stdClass;

class Task extends Activity
{
    /** @var string[] */
    private array $localVariableList = [
        '_lastHttpResponseBody',
        '__lastCreatedEntityId',
    ];

    public function process(): void
    {
        $actionList = $this->getAttributeValue('actionList');

        if (!is_array($actionList)) {
            $actionList = [];
        }

        $originalVariables = $this->getVariablesForFormula();

        $variables = clone $originalVariables;

        try {
            foreach ($actionList as $item) {
                if (empty($item->type)) {
                    continue;
                }

                $this->addCreatedEntityDataToVariables($variables);

                $actionImpl = $this->getActionImplementation($item->type);

                /** @var stdClass $item */
                $item = clone $item;
                $item->elementId = $this->getFlowNode()->getElementId();

                $actionData = $item;

                $actionImpl->process(
                    entity: $this->getTarget(),
                    actionData: $actionData,
                    createdEntitiesData: $this->getCreatedEntitiesData(),
                    variables: $variables,
                    bpmnProcess: $this->getProcess(),
                );

                if ($actionImpl->isCreatedEntitiesDataChanged()) {
                    $this->getProcess()->setCreatedEntitiesData($actionImpl->getCreatedEntitiesData());

                    $this->getEntityManager()->saveEntity($this->getProcess(), ['silent' => true]);
                }
            }
        } catch (Throwable $e) {
            $message = "Process {$this->getProcess()->getId()}, element {$this->getFlowNode()->getId()}: " .
                "{$e->getMessage()}";

            $this->getLog()->error($message, ['exception' => $e]);

            $this->setFailedWithException($e);

            return;
        }

        $this->processStoreVariables($variables, $originalVariables);

        $this->processNextElement();
    }

    /**
     * @throws Error
     * @todo Use factory.
     */
    private function getActionImplementation(string $name): BaseAction
    {
        $name = ucfirst($name);
        $name = str_replace("\\", "", $name);

        $className = 'Espo\\Modules\\Advanced\\Core\\Workflow\\Actions\\' . $name;

        if (!class_exists($className)) {
            $className .= 'Type';

            if (!class_exists($className)) {
                throw new Error('Action class ' . $className . ' does not exist.');
            }
        }

        /** @var class-string<BaseAction> $className */

        $impl = $this->getContainer()
            ->getByClass(InjectableFactory::class)
            ->create($className);

        $workflowId = $this->getProcess()->get('workflowId');

        if ($workflowId) {
            $impl->setWorkflowId($workflowId);
        }

        return $impl;
    }

    private function processStoreVariables(stdClass $variables, stdClass $originalVariables): void
    {
        foreach ($this->localVariableList as $name) {
            unset($variables->$name);
        }

        // The same in TaskScript.
        if ($this->getAttributeValue('isolateVariables')) {
            $variableList = array_keys(get_object_vars($variables));
            $returnVariableList = $this->getReturnVariableList();

            foreach (array_diff($variableList, $returnVariableList) as $name) {
                unset($variables->$name);

                if (property_exists($originalVariables, $name)) {
                    $variables->$name = $originalVariables->$name;
                }
            }
        }

        $this->sanitizeVariables($originalVariables);
        $this->sanitizeVariables($variables);

        if (serialize($variables) !== serialize($originalVariables)) {
            $this->getProcess()->setVariables($variables);

            $this->getEntityManager()->saveEntity($this->getProcess(), ['silent' => true]);
        }
    }
}
