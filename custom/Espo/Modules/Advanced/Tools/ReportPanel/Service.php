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

namespace Espo\Modules\Advanced\Tools\ReportPanel;

use Espo\Core\Acl;
use Espo\Core\DataManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Entities\ReportPanel;
use Espo\Modules\Advanced\Tools\Report\GridType\Result as GridResult;
use Espo\Modules\Advanced\Tools\Report\GridType\RunParams as GridRunParams;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\Modules\Advanced\Tools\Report\ListType\RunParams as ListRunParams;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\Modules\Advanced\Tools\Report\Service as ReportService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

use LogicException;
use stdClass;

class Service
{
    private const TYPE_LIST = 'List';
    private const TYPE_GRID = 'Grid';
    private const TYPE_SUB_REPORT_LIST = 'SubReportList';

    public function __construct(
        private Metadata $metadata,
        private Acl $acl,
        private User $user,
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory,
        private DataManager $dataManager,
        private Config $config,
    ) {}

    /**
     * @throws Error
     */
    public function rebuild(?string $specificEntityType = null): void
    {
        $scopeData = $this->metadata->get(['scopes'], []);
        $entityTypeList = [];

        $isAnythingChanged = false;

        if ($specificEntityType) {
            $entityTypeList[] = $specificEntityType;
        }
        else {
            foreach ($scopeData as $scope => $item) {
                if (empty($item['entity'])) {
                    continue;
                }

                if (empty($item['object'])) {
                    continue;
                }

                if (!empty($item['disabled'])) {
                    continue;
                }

                $entityTypeList[] = $scope;
            }
        }

        $typeList = ['bottom', 'side'];

        foreach ($entityTypeList as $entityType) {
            $clientDefs = $this->metadata->getCustom('clientDefs', $entityType, (object) []);

            $panelListData = [];

            $dynamicLogicToRemoveHash = [];
            $dynamicLogicHash = [];

            foreach ($typeList as $type) {
                $isChanged = false;

                $toAppend = true;

                $panelListData[$type] = [];
                $key = $type . 'Panels';

                if (isset($clientDefs->$key->detail)) {
                    $toAppend = false;

                    $panelListData[$type] = $clientDefs->$key->detail;
                }

                foreach ($panelListData[$type] as $i => $item) {
                    if (is_string($item)) {
                        if ($item === '__APPEND__') {
                            unset($panelListData[$type][$i]);

                            $toAppend = true;
                        }

                        continue;
                    }

                    if (!empty($item->isReportPanel)) {
                        if (isset($item->name)) {
                            $dynamicLogicToRemoveHash[$item->name] = true;
                        }

                        unset($panelListData[$type][$i]);

                        $isChanged = true;
                    }
                }

                $panelListData[$type] = array_values($panelListData[$type]);

                $reportPanels = $this->entityManager
                    ->getRDBRepositoryByClass(ReportPanel::class)
                    ->where([
                        'isActive' => true,
                        'entityType' => $entityType,
                        'type' => $type
                    ])
                    ->order('name')
                    ->find();

                foreach ($reportPanels as $reportPanel) {
                    $reportId = $reportPanel->get('reportId');

                    if (!$reportId) {
                        continue;
                    }

                    $report = $this->entityManager
                        ->getRDBRepositoryByClass(Report::class)
                        ->getById($reportId);

                    if (!$report) {
                        continue;
                    }

                    $isChanged = true;

                    $name = 'reportPanel' . $reportPanel->get('id');

                    $o = (object) [
                        'isReportPanel' => true,
                        'name' => $name,
                        'label' => $reportPanel->get('name'),
                        'view' => 'advanced:views/report-panel/record/panels/report-panel-' . $type,
                        'reportPanelId' => $reportPanel->getId(),
                        'reportType' => $report->getType(),
                        'reportEntityType' => $report->getTargetEntityType(),
                        'displayType' => $reportPanel->get('displayType'),
                        'displayTotal'  => $reportPanel->get('displayTotal'),
                        'displayOnlyTotal' => $reportPanel->get('displayOnlyTotal'),
                        'useSiMultiplier' => $reportPanel->get('useSiMultiplier'),
                        'accessDataList' => [
                            (object) ['scope' => $report->getTargetEntityType()]
                        ],
                    ];

                    if ($type === 'bottom') {
                        $o->order = $reportPanel->get('order');

                        if ($o->order <= 2) {
                            $o->sticked = true;
                        }
                    }

                    if ($reportPanel->get('dynamicLogicVisible')) {
                        $dynamicLogicHash[$name] = (object) [
                            'visible' => $reportPanel->get('dynamicLogicVisible')
                        ];

                        unset($dynamicLogicToRemoveHash[$name]);
                    }

                    if ($report->get('type') === 'Grid') {
                        $o->column = $reportPanel->get('column');
                    }

                    if (count($reportPanel->getLinkMultipleIdList('teams'))) {
                        $o->accessDataList[] = (object) ['teamIdList' => $reportPanel->getLinkMultipleIdList('teams')];
                    }

                    $panelListData[$type][] = $o;
                }

                if ($isChanged) {
                    $isAnythingChanged = true;

                    $clientDefs = $this->metadata->getCustom('clientDefs', $entityType, (object) []);

                    if ($this->hasLogicDefs()) {
                        $logicDefs = $this->metadata->getCustom('logicDefs', $entityType, (object) []);
                    } else {
                        $clientDefs->dynamicLogic ??= (object) [];

                        $logicDefs = $clientDefs->dynamicLogic;
                    }

                    foreach (array_keys($dynamicLogicToRemoveHash) as $name) {
                        if (isset($logicDefs->panels)) {
                            unset($logicDefs->panels->$name);
                        }
                    }

                    if ($dynamicLogicHash) {
                        $logicDefs->panels ??= (object) [];

                        foreach ($dynamicLogicHash as $name => $item) {
                            $logicDefs->panels->$name = $item;
                        }
                    }

                    if (!empty($panelListData[$type])) {
                        if ($toAppend) {
                            array_unshift($panelListData[$type], '__APPEND__');
                        }

                        if (!isset($clientDefs->$key)) {
                            $clientDefs->$key = (object) [];
                        }

                        $clientDefs->$key->detail = $panelListData[$type];
                    } else {
                        if (isset($clientDefs->$key)) {
                            unset($clientDefs->$key->detail);
                        }
                    }

                    $this->metadata->saveCustom('clientDefs', $entityType, $clientDefs);

                    if ($this->hasLogicDefs()) {
                        $this->metadata->saveCustom('logicDefs', $entityType, $logicDefs);
                    }
                }
            }
        }

        if ($isAnythingChanged) {
            $this->dataManager->clearCache();
        }
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function runList(
        string $id,
        ?string $parentType,
        ?string $parentId,
        SearchParams $searchParams
    ): ListResult {

        $result = $this->run(self::TYPE_LIST, $id, $parentType, $parentId, $searchParams);

        if (!$result instanceof ListResult) {
            throw new Error("Bad report result.");
        }

        return $result;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function runSubReportList(
        string $id,
        ?string $parentType,
        ?string $parentId,
        SearchParams $searchParams,
        SubReportParams $subReportParams,
        ?string $subReportId = null
    ): ListResult {

        $result = $this->run(
            self::TYPE_SUB_REPORT_LIST,
            $id,
            $parentType,
            $parentId,
            $searchParams,
            $subReportParams,
            $subReportId
        );

        if (!$result instanceof ListResult) {
            throw new Error("Bad report result.");
        }

        return $result;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function runGrid(string $id, ?string $parentType, ?string $parentId): GridResult
    {
        $result = $this->run(self::TYPE_GRID, $id, $parentType, $parentId);

        if (!$result instanceof GridResult) {
            throw new LogicException("Bad report result.");
        }

        return $result;
    }

    /**
     * @return ListResult|GridResult
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function run(
        string $type,
        string $id,
        ?string $parentType,
        ?string $parentId,
        ?SearchParams $searchParams = null,
        ?SubReportParams $subReportParams = null,
        ?string $subReportId = null
    ) {
        $reportPanel = $this->entityManager
            ->getRDBRepositoryByClass(ReportPanel::class)
            ->getById($id);

        if (!$reportPanel) {
            throw new NotFound('Report Panel not found.');
        }

        if (!$this->acl->checkScope($reportPanel->get('reportEntityType'))) {
            throw new Forbidden();
        }

        if (!$parentId || !$parentType) {
            throw new BadRequest();
        }

        $parent = $this->entityManager->getEntity($parentType, $parentId);

        if (!$parent) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntityRead($parent)) {
            throw new Forbidden();
        }

        if (!$reportPanel->getReportId()) {
            throw new Error('Bad Report Panel.');
        }

        if ($reportPanel->getTargetEntityType() !== $parentType) {
            throw new Forbidden();
        }

        $teamIdList = $reportPanel->getLinkMultipleIdList('teams');

        if (count($teamIdList) && !$this->user->isAdmin()) {
            $isInTeam = false;

            $userTeamIdList = $this->user->getLinkMultipleIdList('teams');

            foreach ($userTeamIdList as $teamId) {
                if (in_array($teamId, $teamIdList)) {
                    $isInTeam = true;

                    break;
                }
            }

            if (!$isInTeam) {
                throw new Forbidden("Access denied to Report Panel.");
            }
        }

        $report = $this->entityManager
            ->getRDBRepositoryByClass(Report::class)
            ->getById($reportPanel->getReportId());

        if (!$report) {
            throw new NotFound("Report not found.");
        }

        if (
            $type === self::TYPE_SUB_REPORT_LIST &&
            $report->getType() === Report::TYPE_JOINT_GRID
        ) {
            if (!$subReportId) {
                throw new BadRequest("No 'subReportId'.");
            }

            $joinedReportDataList = $report->get('joinedReportDataList');

            if (empty($joinedReportDataList)) {
                throw new Error("No joinedReportDataList.");
            }

            $subReport = null;

            foreach ($joinedReportDataList as $subReportItem) {
                if ($subReportId === $subReportItem->id) {
                    $subReport = $this->entityManager
                        ->getRDBRepositoryByClass(Report::class)
                        ->getById($subReportItem->id);

                    break;
                }
            }

            if (!$subReport) {
                throw new Error("No report found.");
            }

            $report = $subReport;
        }

        $where = null;
        $idWhereMap = null;

        if ($report->getType() === Report::TYPE_JOINT_GRID) {
            $idWhereMap = [];

            /** @var stdClass[] $joinedReportDataList */
            $joinedReportDataList = $report->get('joinedReportDataList') ?? [];

            foreach ($joinedReportDataList as $subReportItem) {
                /** @var ?string $subReportId */
                $subReportId = $subReportItem->id ?? null;

                if (!is_string($subReportId)) {
                    continue;
                }

                $subReport = $this->entityManager->getRDBRepositoryByClass(Report::class)->getById($subReportId);

                if (!$subReport) {
                    throw new Error('Sub report not found.');
                }

                $idWhereMap[$subReportId] = $this->getWhere($parent, $subReport);
            }
        } else {
            $where = $this->getWhere($parent, $report);
        }

        $service = $this->injectableFactory->create(ReportService::class);

        if ($type === self::TYPE_GRID) {
            return $service->runGrid(
                $report->getId(),
                $where,
                $this->user,
                GridRunParams::create()->withSkipRuntimeFiltersCheck(),
                $idWhereMap
            );
        }

        $searchParams = $searchParams->withWhereAdded($where);

        if ($type === self::TYPE_LIST) {
            return $service->runList(
                $report->getId(),
                $searchParams,
                $this->user,
                ListRunParams::create()->withSkipRuntimeFiltersCheck()
            );
        }

        if ($type === self::TYPE_SUB_REPORT_LIST) {
            if (!$subReportParams) {
                throw new LogicException();
            }

            return $service->runSubReportList(
                $report->getId(),
                $searchParams,
                $subReportParams,
                $this->user,
                ListRunParams::create()->withSkipRuntimeFiltersCheck()
            );
        }

        throw new Error("Not supported panel type.");
    }

    private function getWhere(Entity $parent, Report $report): WhereItem
    {
        $where = null;

        foreach ($report->getRuntimeFilters() as $item) {
            $field = $item;

            $entityType = $report->getTargetEntityType();

            if (strpos($item, '.')) {
                [$link, $field] = explode('.', $item);

                $entityType = $this->metadata->get(['entityDefs', $entityType, 'links', $link, 'entity']);

                if (!$entityType) {
                    continue;
                }
            }

            $linkType = $this->metadata->get(['entityDefs', $entityType, 'links', $field, 'type']);

            if ($linkType === Entity::BELONGS_TO || $linkType === Entity::HAS_MANY) {
                $foreignEntityType = $this->metadata
                    ->get(['entityDefs', $entityType, 'links', $field, 'entity']);

                if ($foreignEntityType !== $parent->getEntityType()) {
                    continue;
                }

                if ($linkType === Entity::BELONGS_TO) {
                    $where = WhereItem::createBuilder()
                        ->setAttribute($item . 'Id')
                        ->setType('equals')
                        ->setValue($parent->getId())
                        ->build();
                }
                else {
                    $where = WhereItem::createBuilder()
                        ->setAttribute($item)
                        ->setType('linkedWith')
                        ->setValue([$parent->getId()])
                        ->build();
                }
            }
            else if ($linkType === Entity::BELONGS_TO_PARENT) {
                $entityTypeList = $this->metadata
                    ->get(['entityDefs', $entityType, 'fields', $field, 'entityList'], []);

                if (!in_array($parent->getEntityType(), $entityTypeList)) {
                    continue;
                }

                $where = WhereItem::createBuilder()
                    ->setType('and')
                    ->setItemList([
                        WhereItem::createBuilder()
                            ->setAttribute($item . 'Id')
                            ->setType('equals')
                            ->setValue($parent->getId())
                            ->build(),
                        WhereItem::createBuilder()
                            ->setAttribute($item . 'Type')
                            ->setType('equals')
                            ->setValue($parent->getEntityType())
                            ->build(),
                    ])
                    ->build();
            }
        }

        if ($where) {
            return $where;
        }

        $entityType = $report->getTargetEntityType();

        /** @var string[] $linkList */
        $linkList = array_keys($this->metadata->get(['entityDefs', $entityType, 'links'], []));

        $foundBelongsToList = [];
        $foundHasManyList = [];
        $foundBelongsToParentList = [];
        $foundBelongsToParentEmptyList = [];

        foreach ($linkList as $link) {
            $linkType = $this->metadata->get(['entityDefs', $entityType, 'links', $link, 'type']);

            if ($linkType === Entity::BELONGS_TO || $linkType === Entity::HAS_MANY) {
                $foreignEntityType = $this->metadata->get(['entityDefs', $entityType, 'links', $link, 'entity']);

                if ($foreignEntityType !== $parent->getEntityType()) {
                    continue;
                }

                if ($linkType === Entity::BELONGS_TO) {
                    $foundBelongsToList[] = $link;
                } else {
                    $foundHasManyList[] = $link;
                }

                continue;
            }

            if ($linkType === Entity::BELONGS_TO_PARENT) {
                $entityTypeList = $this->metadata->get(['entityDefs', $entityType, 'fields', $link, 'entityList'], []);

                if (!in_array($parent->getEntityType(), $entityTypeList)) {
                    if (empty($entityTypeList)) {
                        $foundBelongsToParentEmptyList[] = $link;
                    }

                    continue;
                }

                $foundBelongsToParentList[] = $link;
            }
        }

        if (count($foundBelongsToList)) {
            $link = $foundBelongsToList[0];

            return WhereItem::createBuilder()
                ->setAttribute($link . 'Id')
                ->setType('equals')
                ->setValue($parent->getId())
                ->build();
        }

        if (count($foundBelongsToParentList)) {
            $link = $foundBelongsToParentList[0];

            return WhereItem::createBuilder()
                ->setType('and')
                ->setItemList([
                    WhereItem::createBuilder()
                        ->setAttribute($link . 'Id')
                        ->setType('equals')
                        ->setValue($parent->getId())
                        ->build(),
                    WhereItem::createBuilder()
                        ->setAttribute($link . 'Type')
                        ->setType('equals')
                        ->setValue($parent->getEntityType())
                        ->build(),
                ])
                ->build();
        }

        if (count($foundHasManyList)) {
            $link = $foundHasManyList[0];

            return WhereItem::createBuilder()
                ->setAttribute($link)
                ->setType('linkedWith')
                ->setValue([$parent->getId()])
                ->build();
        }

        if (count($foundBelongsToParentEmptyList)) {
            $link = $foundBelongsToParentEmptyList[0];

            return WhereItem::createBuilder()
                ->setType('and')
                ->setItemList([
                    WhereItem::createBuilder()
                        ->setAttribute($link . 'Id')
                        ->setType('equals')
                        ->setValue($parent->getId())
                        ->build(),
                    WhereItem::createBuilder()
                        ->setAttribute($link . 'Type')
                        ->setType('equals')
                        ->setValue($parent->getEntityType())
                        ->build(),
                ])
                ->build();
        }

        return WhereItem::createBuilder()
            ->setAttribute('id')
            ->setType('equals')
            ->setValue(null)
            ->build();
    }

    private function hasLogicDefs(): bool
    {
        $version = $this->config->get('version');

        if ($version === '@@version') {
            return true;
        }

        return version_compare($version, '9.1.0') >= 0;
    }
}
