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
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\FieldUtil;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Attachment;
use Espo\Entities\Email;
use Espo\Entities\Job;
use Espo\Entities\User;
use Espo\Modules\Advanced\Business\Report\EmailBuilder;
use Espo\Modules\Advanced\Entities\Report as ReportEntity;
use Espo\Modules\Advanced\Tools\Report\GridType\Result as GridResult;
use Espo\Modules\Advanced\Tools\Report\Jobs\Send;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\ORM\EntityManager;
use Espo\Tools\Export\Export;
use Espo\Tools\Export\Params as ExportToolParams;

use Exception;
use LogicException;
use RuntimeException;
use DateTime;
use DateTimeZone;
use stdClass;

class SendingService
{
    private const LIST_REPORT_MAX_SIZE = 3000;

    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Metadata $metadata,
        private Config $config,
        private FieldUtil $fieldUtil,
        private InjectableFactory $injectableFactory,
        private EmailBuilder $emailBuilder
    ) {}

    private function getSendingListMaxCount(): int
    {
        return $this->config->get('reportSendingListMaxCount', self::LIST_REPORT_MAX_SIZE);
    }

    /**
     * @return array<string, mixed>
     * @throws Error
     * @throws NotFound
     * @throws Forbidden
     * @throws BadRequest
     */
    public function getEmailAttributes(string $id, ?WhereItem $where = null, ?User $user = null): array
    {
        /** @var ?ReportEntity $report */
        $report = $this->entityManager->getEntityById(ReportEntity::ENTITY_TYPE, $id);

        if (!$report) {
            throw new NotFound();
        }

        $service = $this->injectableFactory->create(Service::class);

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

            if ($where) {
                $searchParams = $searchParams->withWhere($where);
            }

            $result = $service->runList($id, $searchParams, $user);
        } else {
            $result = $service->runGrid($id, $where, $user);
        }

        $reportResult = $result;

        if ($result instanceof ListResult) {
            $reportResult = [];

            foreach ($result->getCollection() as $e) {
                $reportResult[] = get_object_vars($e->getValueMap());
            }
        }

        $data = (object) [
            'userId' => $user ? $user->getId() : $this->user->getId(),
        ];

        if ($reportResult instanceof ListResult) {
            // For static analysis.
            throw new LogicException();
        }

        $this->emailBuilder->buildEmailData($data, $reportResult, $report);

        $attachmentId = $this->getExportAttachmentId($report, $result, $where, $user);

        if ($attachmentId) {
            $data->attachmentId = $attachmentId;

            $attachment = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

            if ($attachment) {
                $attachment->set([
                    'role' => 'Attachment',
                    'parentType' => Email::ENTITY_TYPE,
                    'relatedId' => $id,
                    'relatedType' => ReportEntity::ENTITY_TYPE,
                ]);

                $this->entityManager->saveEntity($attachment);
            }
        }

        $userIdList = $report->getLinkMultipleIdList('emailSendingUsers');

        $nameHash = (object) [];

        $toArr = [];

        if ($report->get('emailSendingInterval') && count($userIdList)) {
            $userList = $this
                ->entityManager
                ->getRDBRepositoryByClass(User::class)
                ->where(['id' => $userIdList])
                ->find();

            foreach ($userList as $user) {
                $emailAddress = $user->getEmailAddress();

                if ($emailAddress) {
                    $toArr[] = $emailAddress;
                    $nameHash->$emailAddress = $user->getName();
                }
            }
        }

        $attributes = [
            'isHtml' => true,
            'body' => $data->emailBody,
            'name' => $data->emailSubject,
            'nameHash' => $nameHash,
            'to' => implode(';', $toArr),
        ];

        if ($attachmentId) {
            $attributes['attachmentsIds'] = [$attachmentId];

            $attachment = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

            if ($attachment) {
                $attributes['attachmentsNames'] = [
                    $attachmentId => $attachment->get('name')
                ];
            }
        }

        return $attributes;
    }

    /**
     * @param GridResult|ListResult $result
     */
    public function getExportAttachmentId(
        ReportEntity $report,
        $result,
        ?WhereItem $where = null,
        ?User $user = null
    ): ?string {

        $entityType = $report->getTargetEntityType();

        if ($report->getType() === ReportEntity::TYPE_LIST) {
            if (!$result instanceof ListResult) {
                throw new RuntimeException("Bad result.");
            }

            $fieldList = $report->getColumns();

            foreach ($fieldList as $key => $field) {
                if (strpos($field, '.')) {
                    $fieldList[$key] = str_replace('.', '_', $field);
                }
            }

            $attributeList = [];

            foreach ($fieldList as $field) {
                $fieldAttributeList = $this->fieldUtil->getAttributeList($report->getTargetEntityType(), $field);

                if (count($fieldAttributeList) > 0) {
                    $attributeList = array_merge($attributeList, $fieldAttributeList);
                } else {
                    $attributeList[] = $field;
                }
            }

            $exportParams = ExportToolParams::create($entityType)
                ->withFieldList($fieldList)
                ->withAttributeList($attributeList)
                ->withFormat('xlsx')
                ->withName($report->getName())
                ->withFileName($report->getName() . ' ' . date('Y-m-d'));

            $export = $this->injectableFactory->create(Export::class);

            try {
                return $export
                    ->setParams($exportParams)
                    ->setCollection($result->getCollection())
                    ->run()
                    ->getAttachmentId();
            } catch (Exception $e) {
                $GLOBALS['log']->error("Report export fail, {$report->getId()}: {$e->getMessage()}");

                return null;
            }
        }

        $name = preg_replace("/([^\w\s\d\-_~,;:\[\]().])/u", '_', $report->getName()) . ' ' . date('Y-m-d');

        $mimeType = $this->metadata->get(['app', 'export', 'formatDefs', 'xlsx', 'mimeType']);
        $fileExtension = $this->metadata->get(['app', 'export', 'formatDefs', 'xlsx', 'fileExtension']);

        $fileName = "$name.$fileExtension";

        try {
            $service = $this->injectableFactory->create(GridExportService::class);

            $contents = $service->buildXlsxContents($report->getId(), $where, $user);

            $attachment = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getNew();

            $attachment
                ->setName($fileName)
                ->setType($mimeType)
                ->setContents($contents)
                ->setRole(Attachment::ROLE_ATTACHMENT);

            $attachment->set('parentType', Email::ENTITY_TYPE);

            $this->entityManager->saveEntity($attachment);

            return $attachment->getId();
        } catch (Exception $e) {
            $GLOBALS['log']->error("Report export fail, {$report->getId()}: {$e->getMessage()}");

            return null;
        }
    }

    public function scheduleEmailSending(): void
    {
        $reports = $this->entityManager
            ->getRDBRepositoryByClass(ReportEntity::class)
            ->where([[
                'AND' => [
                    ['emailSendingInterval!=' => ''],
                    ['emailSendingInterval!=' => NULL],
                ]]
            ])
            ->find();

        $utcTZ = new DateTimeZone('UTC');
        $now = new DateTime("now", $utcTZ);

        $defaultTz = $this->config->get('timeZone');

        $espoTimeZone = new DateTimeZone($defaultTz);

        foreach ($reports as $report) {
            $scheduleSending = false;
            $check = false;

            $nowCopy = clone $now;
            $nowCopy->setTimezone($espoTimeZone);

            switch ($report->get('emailSendingInterval')) {
                case 'Daily':
                    $check = true;

                    break;

                case 'Weekly':
                    $check = (strpos($report->get('emailSendingSettingWeekdays'), $nowCopy->format('w')) !== false);

                    break;

                case 'Monthly':
                    $check =
                        $nowCopy->format('j') == $report->get('emailSendingSettingDay') ||
                        $nowCopy->format('j') == $nowCopy->format('t') &&
                        $nowCopy->format('t') < $report->get('emailSendingSettingDay');

                    break;

                case 'Yearly':
                    $check =
                        (
                            $nowCopy->format('j') == $report->get('emailSendingSettingDay') ||
                            $nowCopy->format('j') == $nowCopy->format('t') &&
                            $nowCopy->format('t') < $report->get('emailSendingSettingDay')
                        ) &&
                        $nowCopy->format('n') == $report->get('emailSendingSettingMonth');

                    break;
            }

            if ($check) {
                if ($report->get('emailSendingLastDateSent')) {
                    $lastSent = new DateTime($report->get('emailSendingLastDateSent'), $utcTZ);
                    $lastSent->setTimezone($espoTimeZone);

                    $nowCopy->setTime(0, 0);
                    $lastSent->setTime(0, 0);
                    $diff = $lastSent->diff($nowCopy);

                    if (!empty($diff)) {
                        $dayDiff = (int) ((($diff->invert) ? '-' : '') . $diff->days);

                        if ($dayDiff > 0) {
                            $scheduleSending = true;
                        }
                    }
                } else {
                    $scheduleSending = true;
                }
            }

            if (!$scheduleSending) {
                continue;
            }

            $report->loadLinkMultipleField('emailSendingUsers');
            $users = $report->get('emailSendingUsersIds');

            if (empty($users)) {
                continue;
            }

            $executeTime = clone $now;

            if ($report->get('emailSendingTime')) {
                $time = explode(':', $report->get('emailSendingTime'));

                if (empty($time[0]) || $time[0] < 0 || $time[0] > 23) {
                    $time[0] = 0;
                }

                if (empty($time[1]) || $time[1] < 0 || $time[1] > 59) {
                    $time[1] = 0;
                }

                $executeTime->setTimezone($espoTimeZone);
                $executeTime->setTime(intval($time[0]), intval($time[1]));
                $executeTime->setTimezone($utcTZ);
            }

            $report->set('emailSendingLastDateSent', $executeTime->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT));

            $this->entityManager->saveEntity($report);

            foreach ($users as $userId) {
                $jobEntity = $this->entityManager->getEntity(Job::ENTITY_TYPE);

                $data = (object) [
                    'userId' => $userId,
                    'reportId' => $report->getId(),
                ];

                $jobEntity->set([
                    'name' => Send::class,
                    'className' => Send::class,
                    'executeTime' => $executeTime->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
                    'data' => $data,
                ]);

                $this->entityManager->saveEntity($jobEntity);
            }
        }
    }

    /**
     * @param stdClass $data
     * @param GridResult|array<int, mixed> $result
     * @throws Error
     */
    public function buildData($data, $result, ReportEntity $report): void
    {
        $this->emailBuilder->buildEmailData($data, $result, $report, true);
    }
}
