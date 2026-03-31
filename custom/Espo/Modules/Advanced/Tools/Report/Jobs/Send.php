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

namespace Espo\Modules\Advanced\Tools\Report\Jobs;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Select\SearchParams;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Advanced\Business\Report\EmailBuilder;
use Espo\Modules\Advanced\Entities\Report as ReportEntity;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\Modules\Advanced\Tools\Report\SendingService;
use Espo\Modules\Advanced\Tools\Report\Service;
use Espo\ORM\EntityManager;
use LogicException;
use RuntimeException;

class Send implements Job
{
    private const LIST_REPORT_MAX_SIZE = 3000;

    private Config $config;
    private Service $service;
    private EntityManager $entityManager;
    private SendingService $sendingService;
    private EmailBuilder $emailBuilder;
    private Log $log;

    public function __construct(
        Config $config,
        Service $service,
        EntityManager $entityManager,
        SendingService $sendingService,
        EmailBuilder $emailBuilder,
        Log $log
    ) {
        $this->config = $config;
        $this->service = $service;
        $this->entityManager = $entityManager;
        $this->sendingService = $sendingService;
        $this->emailBuilder = $emailBuilder;
        $this->log = $log;
    }

    /**
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function run(Data $data): void
    {
        $data = $data->getRaw();

        $reportId = $data->reportId;
        $userId = $data->userId;

        /** @var ?ReportEntity $report */
        $report = $this->entityManager->getEntityById(ReportEntity::ENTITY_TYPE, $reportId);

        if (!$report) {
            throw new RuntimeException("Report Sending: No report $reportId.");
        }

        /** @var ?User $user */
        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

        if (!$user) {
            throw new RuntimeException("Report Sending: No user $userId.");
        }

        if ($report->getType() === ReportEntity::TYPE_LIST) {
            $searchParams = SearchParams::create()
                ->withMaxSize($this->getSendingListMaxCount());

            $orderByList = $report->getOrderByList();

            if ($orderByList) {
                $arr = explode(':', $orderByList);

                /**
                 * @var 'ASC'|'DESC' $orderDirection
                 * @noinspection PhpRedundantVariableDocTypeInspection
                 */
                $orderDirection = strtoupper($arr[0]);

                $searchParams = $searchParams
                    ->withOrderBy($arr[1])
                    ->withOrder($orderDirection);
            }

            $result = $this->service->runList($reportId, $searchParams, $user);
        }
        else {
            $result = $this->service->runGrid($reportId, null, $user);
        }

        $reportResult = $result;

        if ($result instanceof ListResult) {
            $reportResult = [];

            foreach ($result->getCollection() as $e) {
                $reportResult[] = get_object_vars($e->getValueMap());
            }

            if (
                count($reportResult) === 0 &&
                $report->get('emailSendingDoNotSendEmptyReport')
            ) {
                $this->log->debug('Report Sending: Report ' . $report->get('name') . ' is empty and was not sent.');

                return;
            }
        }

        if ($reportResult instanceof ListResult) {
            throw new LogicException();
        }

        $attachmentId = $this->sendingService->getExportAttachmentId($report, $result, null, $user);

        $this->emailBuilder->buildEmailData($data, $reportResult, $report);

        $this->emailBuilder->sendEmail(
            $data->userId,
            $data->emailSubject,
            $data->emailBody,
            $attachmentId
        );
    }

    private function getSendingListMaxCount(): int
    {
        return $this->config->get('reportSendingListMaxCount', self::LIST_REPORT_MAX_SIZE);
    }
}
