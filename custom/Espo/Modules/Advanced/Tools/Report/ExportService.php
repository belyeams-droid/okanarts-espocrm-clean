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

namespace Espo\Modules\Advanced\Tools\Report;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Select\SearchParams;
use Espo\Core\Utils\Config;
use Espo\Entities\Attachment;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Entities\Report as ReportEntity;
use Espo\ORM\EntityManager;

class ExportService
{
    private const LIST_REPORT_MAX_SIZE = 3000;

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Service $service,
        private SendingService $sendingService,
    ) {}

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function prepareExportAttachment(Report $report, ?User $user = null): Attachment
    {
        $result = $this->prepareResult($report, $user);

        $attachmentId = $this->sendingService->getExportAttachmentId($report, $result, null, $user);

        if (!$attachmentId) {
            throw new Error("Could not generate an export file for report {$report->getId()}.");
        }

        $attachment = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getById($attachmentId);

        if (!$attachment) {
            throw new Error("Could not fetch the export attachment.");
        }

        $this->prepareAttachmentFields($attachment);

        return $attachment;
    }

    private function getSendingListMaxCount(): int
    {
        return $this->config->get('reportSendingListMaxCount', self::LIST_REPORT_MAX_SIZE);
    }

    private function prepareListSearchParams(ReportEntity $report): SearchParams
    {
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

        return $searchParams;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    private function prepareResult(ReportEntity $report, ?User $user): ListType\Result|GridType\Result
    {
        if ($report->getType() === ReportEntity::TYPE_LIST) {
            $searchParams = $this->prepareListSearchParams($report);

            return $this->service->runList($report->getId(), $searchParams, $user);
        }

        return $this->service->runGrid($report->getId(), null, $user);
    }

    private function prepareAttachmentFields(Attachment $attachment): void
    {
        $attachment->setRole(Attachment::ROLE_EXPORT_FILE);
        $attachment->setParent(null);

        $this->entityManager->saveEntity($attachment);
    }
}
