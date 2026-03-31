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

use Espo\Core\Acl;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Crm\Entities\TargetList;
use Espo\Modules\Crm\Tools\TargetList\RecordService;
use Espo\ORM\EntityManager;

class TargetListSyncService
{

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private Metadata $metadata,
        private ServiceContainer $serviceContainer,
        private Service $service,
        private InjectableFactory $injectableFactory
    ) {}

    /**
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function syncTargetListWithReportsById(string $targetListId): void
    {
        /** @var ?TargetList $targetList */
        $targetList = $this->entityManager->getEntity(TargetList::ENTITY_TYPE, $targetListId);

        if (!$targetList) {
            throw new NotFound();
        }

        if (!$targetList->get('syncWithReportsEnabled')) {
            throw new Error("Sync with reports not enabled for target list $targetListId.");
        }

        $this->syncTargetListWithReports($targetList);
    }

    /**
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function syncTargetListWithReports(TargetList $targetList): void
    {
        if (!$this->acl->checkEntityEdit($targetList)) {
            throw new Forbidden();
        }

        /** @var \Espo\Modules\Crm\Tools\TargetList\RecordService $targetListService */
        $targetListService = class_exists("Espo\\Modules\\Crm\\Tools\\TargetList\\RecordService") ?
            $this->injectableFactory->create(RecordService::class) :
            $this->serviceContainer->get(TargetList::ENTITY_TYPE);

        if ($targetList->get('syncWithReportsUnlink')) {
            $linkList = $this->metadata->get(['scopes', 'TargetList', 'targetLinkList']) ??
                ['contacts', 'leads', 'accounts', 'users'];

            foreach ($linkList as $link) {
                $targetListService->unlinkAll($targetList->getId(), $link);
            }
        }

        $reportList = $this->entityManager
            ->getRDBRepository(TargetList::ENTITY_TYPE)
            ->getRelation($targetList, 'syncWithReports')
            ->find();

        foreach ($reportList as $report) {
            $this->populateTargetList($report->getId(), $targetList->getId());
        }
    }

    /**
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function populateTargetList(string $id, string $targetListId): void
    {
        /** @var ?Report $report */
        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $id);

        if (!$report) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntityRead($report)) {
            throw new Forbidden();
        }

        $targetList = $this->entityManager->getEntity(TargetList::ENTITY_TYPE, $targetListId);

        if (!$targetList) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntityEdit($targetList)) {
            throw new Forbidden();
        }

        if ($report->getType() !== Report::TYPE_LIST) {
            throw new Error("Report is not of 'List' type.");
        }

        $entityType = $report->getTargetEntityType();

        $linkList = $this->metadata->get(['scopes', 'TargetList', 'targetLinkList']) ??
            ['contacts', 'leads', 'accounts', 'users'];

        $link = null;

        foreach ($linkList as $itemLink) {
            if (
                $this->metadata->get(['entityDefs', 'TargetList', 'links', $itemLink, 'entity']) === $entityType
            ) {
                $link = $itemLink;

                break;
            }
        }

        if (!$link) {
            throw new Error("Not supported entity type '$entityType' for target list sync.");
        }

        $query = $this->service
            ->prepareSelectBuilder($report)
            ->build();

        $this->entityManager
            ->getRDBRepository(TargetList::ENTITY_TYPE)
            ->getRelation($targetList, $link)
            ->massRelate($query);
    }
}
