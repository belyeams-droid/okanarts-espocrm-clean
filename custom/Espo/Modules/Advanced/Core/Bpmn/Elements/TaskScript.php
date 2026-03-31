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

use stdClass;
use Throwable;

/**
 * @noinspection PhpUnused
 */
class TaskScript extends Activity
{
    public function process(): void
    {
        $formula = $this->getAttributeValue('formula');

        if (!$formula) {
            $this->processNextElement();

            return;
        }

        if (!is_string($formula)) {
            $message = "Process {$this->getProcess()->getId()}, formula should be string.";

            $this->getLog()->error($message);

            $this->setFailed();

            return;
        }

        $originalVariables = $this->getVariablesForFormula();

        $variables = clone $originalVariables;

        try {
            $this->getFormulaManager()->run($formula, $this->getTarget(), $variables);

            $this->getEntityManager()->saveEntity($this->getTarget(), [
                'skipWorkflow' => true,
                'skipModifiedBy' => true,
            ]);
        } catch (Throwable $e) {
            $message = "Process {$this->getProcess()->getId()} formula error: {$e->getMessage()}";

            $this->getLog()->error($message, ['exception' => $e]);

            $this->setFailedWithException($e);

            return;
        }

        $this->processStoreVariables($variables, $originalVariables);

        $this->processNextElement();
    }

    private function processStoreVariables(stdClass $variables, stdClass $originalVariables): void
    {
        // The same in Task.
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

        $this->sanitizeVariables($variables);

        $this->getProcess()->setVariables($variables);

        $this->getEntityManager()->saveEntity($this->getProcess(), ['silent' => true]);
    }
}
