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

use Espo\Modules\Advanced\Core\Workflow\Actions\Base;
use Exception;
use stdClass;

class ActionManager extends BaseManager
{
    protected string $dirName = 'Actions';
    protected array $requiredOptions = ['type'];

    /**
     * @param stdClass[] $actions Actions.
     * @param array<string, mixed> $variables Formula variables to pass.
     * @param array<string, mixed> $options Save options.
     * @throws Error
     */
    public function runActions(array $actions, array $variables = [], array $options = []): void
    {
        $this->log->debug("Workflow {$this->getWorkflowId()}: Start actions.");

        $actualVariables = (object) [];

        foreach ($variables as $key => $value) {
            $actualVariables->$key = $value;
        }

        // Should be initialized before the loop.
        $processId = $this->getProcessId();

        foreach ($actions as $action) {
            $this->runAction(
                actionData: $action,
                processId: $processId,
                variables: $actualVariables,
                options: $options,
            );
        }

        $this->log->debug("Workflow {$this->getWorkflowId()}: End actions.");
    }

    /**
     * @param array<string, mixed> $options
     * @throws Error
     */
    private function runAction(
        stdClass $actionData,
        ?string $processId,
        stdClass $variables,
        array $options = [],
    ): void {

        $entity = $this->getEntity($processId);

        if (!$this->validate($actionData)) {
            $workflowId = $this->getWorkflowId($processId);

            $this->log->warning("Workflow $workflowId: Invalid action data.");

            return;
        }

        $actionImpl = $this->createConditionOrAction($actionData->type, $processId);

        if (!$actionImpl instanceof Base) {
            throw new Error("Not action class.");
        }

        try {
            $actionImpl->process(
                entity: $entity,
                actionData: $actionData,
                variables: $variables,
                options: $options,
            );

            $this->copyVariables($actionImpl->getVariablesBack(), $variables);
        } catch (Exception $e) {
            $workflowId = $this->getWorkflowId($processId);
            $type = $actionData->type;
            $cid = $actionData->cid;

            $this->log->error("Workflow $workflowId: Action failed, $type $cid, {$e->getMessage()}.");

            throw new Error("Workflow action failed.", 500, $e);
        }
    }

    private function copyVariables(object $source, object $destination): void
    {
        foreach (get_object_vars($destination) as $k => $v) {
            unset($destination->$k);
        }

        foreach (get_object_vars($source) as $k => $v) {
            $destination->$k = $v;
        }
    }
}
