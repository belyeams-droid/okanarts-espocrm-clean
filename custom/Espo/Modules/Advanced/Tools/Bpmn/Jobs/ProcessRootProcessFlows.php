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

namespace Espo\Modules\Advanced\Tools\Bpmn\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\Advanced\Core\Bpmn\BpmnManager;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOption;
use RuntimeException;

/**
 * @noinspection PhpUnused
 */
class ProcessRootProcessFlows implements Job
{
    public function __construct(
        private EntityManager $entityManager,
        private BpmnManager $bpmnManager,
    ) {}

    public function run(Data $data): void
    {
        $processId = $data->getTargetId() ?? throw new RuntimeException();

        $process = $this->getProcess($processId);

        if (!$process) {
            return;
        }

        $this->bpmnManager->processPendingFlows($processId);

        $this->updateProcess($processId);
    }

    private function updateProcess(string $processId): void
    {
        $process = $this->entityManager->getRDBRepositoryByClass(BpmnProcess::class)->getById($processId);

        if (!$process) {
            return;
        }

        // If the job was running for long, this will ensure the process won't be processed
        // too soon the next time.
        $process->setVisitTimestampNow();
        $process->setIsLocked(false);

        $this->entityManager->saveEntity($process, [SaveOption::SKIP_ALL => true]);
    }

    private function getProcess(string $processId): ?BpmnProcess
    {
        $this->entityManager->getTransactionManager()->start();

        $process = $this->entityManager
            ->getRDBRepositoryByClass(BpmnProcess::class)
            ->forUpdate()
            ->where(['id' => $processId])
            ->findOne();

        if (!$process) {
            $this->entityManager->getTransactionManager()->commit();

            return null;
        }

        if (!$process->isLocked()) {
            // Can happen if jobs were not running for long and the process got unlocked.
            throw new RuntimeException("BPM: Process $processId is not locked.");
        }

        // If the job ran late, this will prevent the process from unlocking while it's being processed.
        $process->setVisitTimestampNow();

        $this->entityManager->saveEntity($process, [SaveOption::SKIP_ALL => true]);

        $this->entityManager->getTransactionManager()->commit();

        return $process;
    }
}
