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

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\Advanced\Tools\Workflow\Action\RunAction\ServiceAction;
use Espo\ORM\Entity;
use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Json;
use JsonException;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class RunService extends Base
{
    /**
     * @throws Error
     * @throws JsonException
     */
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        if (empty($actionData->methodName)) {
            throw new Error("No service action name.");
        }

        $name = $actionData->methodName;

        if (!is_string($name)) {
            throw new Error("Bad service action name.");
        }

        $target = $actionData->target ?? 'targetEntity';

        $targetEntity = $this->getFirstTargetFromTargetItem($entity, $target);

        if (!$targetEntity) {
            $this->log->notice("Workflow Run Service Action: No target {target} found.", ['target' => $target]);

            return false;
        }

        $data = null;

        if (!empty($actionData->additionalParameters)) {
            $data = Json::decode($actionData->additionalParameters);
        }

        $variables = null;

        if ($this->hasVariables()) {
            $variables = $this->getVariables();
        }

        $output = null;

        $targetEntityType = $targetEntity->getEntityType();

        $className = $this->getClassName($targetEntityType, $name);

        if ($className) {
            $serviceAction = $this->injectableFactory->create($className);

            $output = $serviceAction->run($targetEntity, $data);
        }

        // Legacy.
        if (!$className) {
            $this->runLegacy($targetEntityType, $name, $targetEntity, $data, $variables);
        }

        if (!$this->hasVariables()) {
            return true;
        }

        $variables->__lastServiceActionOutput = $output;

        $this->updateVariables($variables);

        return true;
    }

    /**
     * @param mixed $data
     * @throws Error
     */
    private function runLegacy(
        string $targetEntityType,
        string $name,
        Entity $targetEntity,
        $data,
        ?stdClass $variables
    ): void {

        $serviceName = $this->metadata
            ->get(['entityDefs', 'Workflow', 'serviceActions', $targetEntityType, $name, 'serviceName']);

        $methodName = $this->metadata
            ->get(['entityDefs', 'Workflow', 'serviceActions', $targetEntityType, $name, 'methodName']);

        if (!$serviceName || !$methodName) {
            $methodName = $name;
            $serviceName = $targetEntity->getEntityType();
        }

        $serviceFactory = $this->serviceFactory;

        if (!$serviceFactory->checkExists($serviceName)) {
            throw new Error("No service $serviceName.");
        }

        $service = $serviceFactory->create($serviceName);

        if (!method_exists($service, $methodName)) {
            throw new Error("No method $methodName.");
        }

        $service->$methodName(
            $this->getWorkflowId(),
            $targetEntity,
            $data,
            $this->bpmnProcess,
            $variables ?? (object)[]
        );
    }

    /**
     * @return ?class-string<ServiceAction<CoreEntity>>
     */
    private function getClassName(string $targetEntityType, string $name): ?string
    {
        /** @var ?class-string<ServiceAction<CoreEntity>> $className */
        $className =
            $this->metadata->get("app.workflow.serviceActions.$targetEntityType.$name.className") ??
            $this->metadata->get("app.workflow.serviceActions.Global.$name.className");

        return $className;
    }
}
