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

namespace Espo\Modules\Advanced\Jobs;

use Cron\CronExpression;

use Espo\Core\Field\DateTime;
use Espo\Core\Job\Job\Data;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Log;
use Espo\Entities\Job;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\Modules\Advanced\Tools\Workflow\Jobs\RunScheduledWorkflow as RunScheduledWorkflowJob;
use Espo\ORM\EntityManager;

use Exception;
use DateTimeZone;

/**
 * @noinspection PhpUnused
 */
class RunScheduledWorkflows implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log,
        private JobSchedulerFactory $jobSchedulerFactory,
    ) {}

    public function run(): void
    {
        $defaultTimeZone = $this->config->get('timeZone');

        foreach ($this->getWorkflows() as $workflow) {
            $timeZone = $workflow->getSchedulingApplyTimezone() ? $defaultTimeZone : null;
            $scheduling = $workflow->getScheduling();

            try {
                $cronExpression = new CronExpression($scheduling);

                $dateTime = $cronExpression->getNextRunDate('now', 0, true, $timeZone)
                    ->setTimezone(new DateTimeZone('UTC'));

                $time = DateTime::fromDateTime($dateTime);
            } catch (Exception $e) {
                $this->log->error("Bad scheduling in workflow {id}.", [
                    'exception' => $e,
                    'id' => $workflow->getId(),
                ]);

                continue;
            }

            if ($workflow->getLastRun() && $workflow->getLastRun()->isEqualTo($time)) {
                continue;
            }

            if ($this->jobExists($time, $workflow->getId())) {
                return;
            }

            $this->scheduleJob($time, $workflow->getId());

            $workflow->setLastRun($time);

            $this->entityManager->saveEntity($workflow, ['silent' => true]);
        }
    }

    private function scheduleJob(DateTime $time, string $workflowId): void
    {
        $this->jobSchedulerFactory
            ->create()
            ->setClassName(RunScheduledWorkflowJob::class)
            ->setTime($time->toDateTime())
            ->setData(
                Data::create()
                    ->withTargetId($workflowId)
                    ->withTargetType(Workflow::ENTITY_TYPE)
            )
            ->schedule();
    }

    private function jobExists(DateTime $time, string $workflowId): bool
    {
        $from = $time->toDateTime();

        $seconds = (int) $from->format('s');

        $from = $from->modify("- $seconds seconds");
        $to = $from->modify('+ 1 minute');

        $found = $this->entityManager
            ->getRDBRepositoryByClass(Job::class)
            ->select(['id'])
            ->where([
                'className' => RunScheduledWorkflowJob::class,
                ['executeTime>=' => $from->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT)],
                ['executeTime<' => $to->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT)],
                'targetId' => $workflowId,
                'targetType' => Workflow::ENTITY_TYPE,
            ])
            ->findOne();

        return (bool) $found;
    }

    /**
     * @return iterable<Workflow>
     */
    private function getWorkflows(): iterable
    {
        return $this->entityManager
            ->getRDBRepositoryByClass(Workflow::class)
            ->where([
                'type' => Workflow::TYPE_SCHEDULED,
                'isActive' => true,
            ])
            ->order('processOrder')
            ->order('id')
            ->find();
    }
}
