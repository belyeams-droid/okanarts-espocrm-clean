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

namespace Espo\Modules\Advanced\Services;

use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\Error;
use Espo\Modules\Advanced\Core\Bpmn\BpmnManager;
use Espo\Modules\Advanced\Core\Bpmn\Elements\Activity;
use Espo\Modules\Advanced\Entities\BpmnFlowNode as BpmnFlowNodeEntity;
use Espo\Modules\Advanced\Entities\BpmnProcess as BpmnProcessEntity;
use Espo\ORM\Repository\Option\SaveOption;
use Espo\Services\Record;
use LogicException;

/**
 * @extends Record<BpmnProcessEntity>
 */
class BpmnProcess extends Record
{
    private function createBpmnManager(): BpmnManager
    {
        return $this->injectableFactory->create(BpmnManager::class);
    }

    /**
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function reactivateProcess(string $id): void
    {
        /** @var ?BpmnProcessEntity $process */
        $process = $this->entityManager->getEntityById(BpmnProcessEntity::ENTITY_TYPE, $id);

        if (!$process) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntityEdit($process)) {
            throw new Forbidden();
        }

        if (
            !in_array($process->getStatus(), [
                BpmnProcessEntity::STATUS_ENDED,
                BpmnProcessEntity::STATUS_STOPPED,
                BpmnProcessEntity::STATUS_INTERRUPTED,
            ])
        ) {
            throw new Error("BPM: Can't reactivate not ended process.");
        }

        $targetType = $process->getTargetType();
        $targetId = $process->getTargetId();

        if (!$targetType || !$targetId) {
            throw new Error("BPM: Can't reactivate process, no target.");
        }

        $target = $this->entityManager->getEntityById($targetType, $targetId);

        if (!$target) {
            throw new Error("BPM: Can't reactivate process, target not found.");
        }

        if (!$process->isSubProcess()) {
            $anotherProcess = $this->entityManager
                ->getRDBRepository(BpmnProcessEntity::ENTITY_TYPE)
                ->where([
                    'targetId' => $target->getId(),
                    'targetType' => $target->getEntityType(),
                    'status' => [
                        BpmnProcessEntity::STATUS_STARTED,
                        BpmnProcessEntity::STATUS_PAUSED,
                    ],
                    'flowchartId' => $process->getFlowchartId(),
                ])
                ->findOne();

            if ($anotherProcess) {
                throw new Error(
                    "Process for flowchart " . $process->getFlowchartId() .
                    " can't be run because process is already running.");
            }
        }

        $manager = $this->createBpmnManager();

        if ($process->hasParentProcess()) {
            /** @var string $parentProcessId */
            $parentProcessId = $process->getParentProcessId();
            /** @var string $parentProcessFlowNodeId */
            $parentProcessFlowNodeId = $process->getParentProcessFlowNodeId();

            /** @var ?BpmnProcessEntity $parentProcess */
            $parentProcess = $this->entityManager
                ->getEntityById(BpmnProcessEntity::ENTITY_TYPE, $parentProcessId);

            /** @var ?BpmnFlowNodeEntity $parentProcessFlowNode */
            $parentProcessFlowNode = $this->entityManager
                ->getEntityById(BpmnFlowNodeEntity::ENTITY_TYPE, $parentProcessFlowNodeId);

            if (!$parentProcess) {
                throw new Error("BPM: Can't reactivate sub-process, parent process not found.");
            }

            if (!$parentProcessFlowNode) {
                throw new Error("BPM: Can't reactivate sub-process, parent process flow node not found.");
            }

            if ($parentProcess->getStatus() !== BpmnProcessEntity::STATUS_STARTED) {
                throw new Error(
                    "BPM: Can't reactivate sub-process, parent process is not started. Reactivate parent process.");
            }

            $parentTargetType = $parentProcess->getTargetType();
            $parentTargetId = $parentProcess->getTargetId();

            if (!$parentTargetType || !$parentTargetId) {
                throw new Error("BPM: Can't reactivate sub-process, no parent target.");
            }

            $parentTarget = $this->entityManager->getEntityById($parentTargetType, $parentTargetId);

            if (!$parentTarget) {
                throw new Error("BPM: Can't reactivate sub-process, parent target not found.");
            }

            $parentElement = $manager->getFlowNodeImplementation($parentTarget, $parentProcessFlowNode, $parentProcess);

            if (!$parentElement instanceof Activity) {
                throw new LogicException("BPM: Can't reactivate sub-process, bad element.");
            }

            $parentProcessFlowNode->set('status', BpmnFlowNodeEntity::STATUS_IN_PROCESS);

            $this->entityManager->saveEntity($parentProcessFlowNode);

            $parentElement->prepareBoundary();
        }

        $process->setStatus(BpmnProcessEntity::STATUS_STARTED);
        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $process->set('endedAt', null);

        $this->entityManager->saveEntity($process);

        $manager->prepareEventSubProcesses($target, $process);
    }

    /**
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function stopProcess(string $id): void
    {
        /** @var ?BpmnProcessEntity $process */
        $process = $this->entityManager->getEntityById(BpmnProcessEntity::ENTITY_TYPE, $id);

        if (!$process) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntityEdit($process)) {
            throw new Forbidden();
        }

        if (
            !in_array(
                $process->getStatus(),
                [
                    BpmnProcessEntity::STATUS_STARTED,
                    BpmnProcessEntity::STATUS_PAUSED,
                ])
        ) {
            throw new Error("BPM: Can't stop not started process.");
        }

        if ($process->isLocked()) {
            $process->setIsLocked(false);
        }

        $process->setStatus(BpmnProcessEntity::STATUS_STOPPED);

        $this->entityManager->saveEntity($process);
    }

    /**
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function startFlowFromElement(string $processId, string $elementId): void
    {
        $process = $this->entityManager->getRDBRepositoryByClass(BpmnProcessEntity::class)->getById($processId);

        if (!$process) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntityEdit($process)) {
            throw new Forbidden();
        }

        if ($process->getStatus() !== BpmnProcessEntity::STATUS_STARTED) {
            throw new Error("BPM: Can't start flow for not started process.");
        }

        $target = $this->entityManager->getEntityById($process->getTargetType(), $process->getTargetId());

        if (!$target) {
            throw new Error("BPM: No target for process to start flow node.");
        }

        $manager = $this->createBpmnManager();

        $manager->processFlow($target, $process, $elementId);
    }

    /**
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function rejectFlowNode(string $flowNodeId): void
    {
        /** @var ?BpmnFlowNodeEntity $flowNode */
        $flowNode = $this->entityManager->getEntityById(BpmnFlowNodeEntity::ENTITY_TYPE, $flowNodeId);

        if (!$flowNode) {
            throw new NotFound();
        }

        $processId = $flowNode->getProcessId();
        $process = $this->entityManager->getEntityById(BpmnProcessEntity::ENTITY_TYPE, $processId);

        if (!$process) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntityEdit($process)) {
            throw new Forbidden();
        }

        $status = $flowNode->getStatus();

        if (
            in_array($status, [
                BpmnFlowNodeEntity::STATUS_PROCESSED,
                BpmnFlowNodeEntity::STATUS_INTERRUPTED,
                BpmnFlowNodeEntity::STATUS_REJECTED,
                BpmnFlowNodeEntity::STATUS_FAILED,
            ])
        ) {
            throw new Forbidden();
        }

        $manager = $this->createBpmnManager();

        if ($flowNode->getStatus() === BpmnFlowNodeEntity::STATUS_IN_PROCESS) {
            $flowNode->setStatus(BpmnFlowNodeEntity::STATUS_INTERRUPTED);

            $this->entityManager->saveEntity($flowNode);

            if (
                in_array($flowNode->getElementType(),
                    [
                        'subProcess',
                        'eventSubProcess',
                        'callActivity'
                    ])
            ) {
                $subProcess = $this->entityManager
                    ->getRDBRepositoryByClass(BpmnProcessEntity::class)
                    ->where([
                        'parentProcessFlowNodeId' => $flowNode->getId(),
                    ])
                    ->findOne();

                if ($subProcess) {
                    $manager->interruptProcess($subProcess);
                }
            }
        }
        else {
            $flowNode->setStatus(BpmnFlowNodeEntity::STATUS_REJECTED);

            $this->entityManager->saveEntity($flowNode);
        }
    }

    public function cleanup(string $id): void
    {
        $flowNodeList = $this->entityManager
            ->getRDBRepository(BpmnFlowNodeEntity::ENTITY_TYPE)
            ->where(['processId' => $id])
            ->find();

        foreach ($flowNodeList as $flowNode) {
            $this->entityManager->removeEntity($flowNode);

            $this->entityManager
                ->getRDBRepository(BpmnFlowNodeEntity::ENTITY_TYPE)
                ->deleteFromDb($flowNode->getId());
        }
    }
}
