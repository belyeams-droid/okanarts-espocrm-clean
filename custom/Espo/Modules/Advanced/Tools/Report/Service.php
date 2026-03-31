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

use Espo\Core\Acl\Table as AclTable;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\FieldProcessing\ListLoadProcessor;
use Espo\Core\FieldProcessing\Loader\Params as LoaderParams;
use Espo\Core\InjectableFactory;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Core\Select\SearchParams;
use Espo\Core\Utils\Acl\UserAclManagerProvider;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Core\ORM\SthCollection;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Reports\GridReport;
use Espo\Modules\Advanced\Reports\ListReport;
use Espo\Modules\Advanced\Tools\Report\GridType\ColumnData;
use Espo\Modules\Advanced\Tools\Report\GridType\GridBuilder;
use Espo\Modules\Advanced\Tools\Report\GridType\Helper as GridHelper;
use Espo\Modules\Advanced\Tools\Report\GridType\Data as GridData;
use Espo\Modules\Advanced\Tools\Report\GridType\QueryPreparator as GridQueryPreparator;
use Espo\Modules\Advanced\Tools\Report\GridType\Result as GridResult;
use Espo\Modules\Advanced\Tools\Report\GridType\ResultHelper;
use Espo\Modules\Advanced\Tools\Report\GridType\RunParams as GridRunParams;
use Espo\Modules\Advanced\Tools\Report\GridType\Util as GridUtil;
use Espo\Modules\Advanced\Tools\Report\ListType\Data as ListData;
use Espo\Modules\Advanced\Tools\Report\ListType\QueryPreparator as ListQueryPreparator;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\Modules\Advanced\Tools\Report\ListType\RunParams as ListRunParams;
use Espo\Modules\Advanced\Tools\Report\ListType\SubListQueryPreparator;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportQueryPreparator;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\ORM\Query\SelectBuilder;
use Exception;
use PDOException;
use PDO;
use stdClass;

class Service
{
    private const GRID_SUB_LIST_LIMIT = 500;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Config $config,
        private User $user,
        private InjectableFactory $injectableFactory,
        private UserAclManagerProvider $userAclManagerProvider,
        private RecordServiceContainer $recordServiceContainer,
        private ResultHelper $gridResultHelper,
        private GridHelper $gridHelper,
        private GridBuilder $gridBuilder,
        private GridUtil $gridUtil,
        private ReportHelper $reportHelper,
        private ListQueryPreparator $listQueryPreparator,
        private SubReportQueryPreparator $subReportQueryPreparator,
        private ListLoadProcessor $listLoadProcessor,
        private Log $log,
        private GridQueryPreparator $gridQueryPreparator,
        private SubListQueryPreparator $subListQueryPreparator
    ) {}

    /**
     * Fetch a report. Access control is applied if a user is passed.
     *
     * @throws Forbidden
     * @throws NotFound
     */
    private function fetchReportForRun(string $id, ?User $user = null): Report
    {
        /** @var ?Report $report */
        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $id);

        if (!$report) {
            throw new NotFound("Report $id not found.");
        }

        $this->reportHelper->checkReportCanBeRun($report);

        if (!$user) {
            return $report;
        }

        $aclManager = $this->userAclManagerProvider->get($user);

        if (!$aclManager->checkEntity($user, $report)) {
            throw new Forbidden("No access to report $id for user {$user->getId()}.");
        }

        $entityType = $report->getTargetEntityType();

        if (
            !$aclManager->checkScope($user, $entityType, AclTable::ACTION_READ) &&
            !$user->isPortal() // @todo Revise.
        ) {
            throw new Forbidden("No 'read' access to $entityType.");
        }

        return $report;
    }

    /**
     * Run a list report. Access control is applied if a user is passed.
     *
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     * @throws BadRequest
     */
    public function runList(
        string $id,
        ?SearchParams $searchParams = null,
        ?User $user = null,
        ?ListRunParams $runParams = null,
    ): ListResult {

        $runParams = $runParams ?? ListRunParams::create();
        $report = $this->fetchReportForRun($id, $user);

        return $this->reportRunList(
            report: $report,
            searchParams: $searchParams,
            user: $user,
            runParams: $runParams,
        );
    }

    /**
     * Run a sub-report list. Access control is applied if a user is passed.
     *
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     * @throws BadRequest
     */
    public function runSubReportList(
        string $id,
        SearchParams $searchParams,
        SubReportParams $subReportParams,
        ?User $user = null,
        ?ListRunParams $runParams = null,
    ): ListResult {

        $report = $this->fetchReportForRun($id, $user);

        if ($report->isInternal()) {
            $impl = $this->reportHelper->createInternalReport($report);

            if (!$impl instanceof GridReport) {
                throw new Error("Bad report class.");
            }

            return $impl->runSubReport($searchParams, $subReportParams, $user);
        }

        if (!in_array($report->getType(), [Report::TYPE_GRID, Report::TYPE_JOINT_GRID])) {
            throw new Error("Can't run sub-report for non-Grid report.");
        }

        if (!$report->getTargetEntityType()) {
            throw new Error("No entity type in report $id.");
        }

        if (
            $searchParams->getWhere() &&
            (!$runParams || !$runParams->skipRuntimeFiltersCheck())
        ) {
            $this->reportHelper->checkRuntimeFilters($searchParams->getWhere(), $report);
        }

        return $this->executeSubReportList(
            data: $this->reportHelper->fetchGridDataFromReport($report),
            searchParams: $searchParams,
            subReportParams: $subReportParams,
            runParams: $runParams,
            user: $user,
        );
    }

    /**
     * Run a grid or joint-grid report. Access control is applied if a user is passed.
     *
     * @param ?array<string, ?WhereItem> $idWhereMap
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     * @throws BadRequest
     */
    public function runGrid(
        string $id,
        ?WhereItem $whereItem = null,
        ?User $user = null,
        ?GridRunParams $runParams = null,
        ?array $idWhereMap = null,
    ): GridResult {

        return $this->runGridOrJoint(
            id: $id,
            whereItem: $whereItem,
            user: $user,
            runParams: $runParams,
            idWhereMap: $idWhereMap,
        );
    }

    /**
     * Access control is applied if a user is passed.
     *
     * @param ?array<string, ?WhereItem> $idWhereMap
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     * @throws BadRequest
     */
    private function runGridOrJoint(
        string $id,
        ?WhereItem $whereItem = null,
        ?User $user = null,
        ?GridRunParams $runParams = null,
        ?array $idWhereMap = null,
    ): GridResult {

        $report = $this->fetchReportForRun($id, $user);

        return $this->reportRunGridOrJoint(
            report: $report,
            whereItem: $whereItem,
            user: $user,
            runParams: $runParams,
            idWhereMap: $idWhereMap,
        );
    }

    private function getForeignFieldType(string $entityType, string $link, string $field): ?string
    {
        $defs = $this->entityManager->getMetadata()->get($entityType);

        if (!empty($defs['relations']) && !empty($defs['relations'][$link])) {
            $foreignScope = $defs['relations'][$link]['entity'];

            return $this->metadata->get(['entityDefs', $foreignScope, 'fields', $field, 'type']);
        }

        return null;
    }

    private function getForeignAttributeType(string $entityType, string $link, string $attribute): ?string
    {
        $metadata = $this->entityManager->getMetadata();

        $defs = $metadata->get($entityType);

        if (empty($defs['relations']) || empty($defs['relations'][$link])) {
            return null;
        }

        $foreignEntityType = $defs['relations'][$link]['entity'] ?? null;

        if (!$foreignEntityType) {
            return null;
        }

        return $metadata->get($foreignEntityType, ['attributes', $attribute, 'type']) ??
            $metadata->get($foreignEntityType, ['fields', $attribute, 'type']);
    }

    /**
     * @throws Forbidden
     * @throws Error
     * @throws BadRequest
     */
    public function prepareSelectBuilder(Report $report, ?User $user = null): SelectBuilder
    {
        $data = $this->reportHelper->fetchListDataFromReport($report);

        return $this->listQueryPreparator->prepare($data, null, $user);
    }

    /**
     * @throws Forbidden
     * @throws Error
     * @throws BadRequest
     */
    private function executeListReport(
        ListData $data,
        ?SearchParams $searchParams = null,
        ?ListRunParams $runParams = null,
        ?User $user = null
    ): ListResult {

        $entityType = $data->getEntityType();

        $searchParams = $searchParams ?? SearchParams::create();
        $runParams = $runParams ?? ListRunParams::create();

        if ($runParams->getCustomColumnList()) {
            $initialColumnList = $data->getColumns();

            $newColumnList = [];

            foreach ($runParams->getCustomColumnList() as $item) {
                if (str_contains($item, '.')) {
                    if (!in_array($item, $initialColumnList)) {
                        break;
                    }
                }

                $newColumnList[] = $item;
            }

            $data = $data->withColumns($newColumnList);
        }

        if (!$searchParams->getOrderBy()) {
            if ($data->getOrderBy()) {
                [$order, $orderBy] = explode(':', $data->getOrderBy());
            } else {
                $orderBy = $this->metadata->get(['entityDefs', $entityType, 'collection', 'orderBy']);
                $order = $this->metadata->get(['entityDefs', $entityType, 'collection', 'order']);
            }

            if ($order) {
                $order = strtoupper($order);
            }

            /** @var 'ASC'|'DESC'|null $order */

            if ($orderBy) {
                $searchParams = $searchParams
                    ->withOrderBy($orderBy)
                    ->withOrder($order);
            }
        }

        $queryBuilder = $this->listQueryPreparator->prepare($data, $searchParams, $user);

        if ($runParams->isFullSelect()) {
            $queryBuilder->select(['*']);
        }

        $additionalAttributeDefs = [];
        $linkMultipleFieldList = [];
        $foreignLinkFieldDataList = [];

        foreach ($data->getColumns() as $column) {
            if (!str_contains($column, '.')) {
                $fieldType = $this->metadata->get(['entityDefs', $entityType, 'fields', $column, 'type']);

                if (in_array($fieldType, ['linkMultiple', 'attachmentMultiple'])) {
                    $linkMultipleFieldList[] = $column;
                }

                continue;
            }

            $arr = explode('.', $column);
            $link = $arr[0];
            $attribute = $arr[1];

            $foreignAttributeType = $this->getForeignAttributeType($entityType, $link, $attribute);
            $foreignAttribute = $link . '_' . $attribute;
            $foreignType = $this->getForeignFieldType($entityType, $link, $attribute);

            if (in_array($foreignType, ['image', 'file', 'link'])) {
                $additionalAttributeDefs[$foreignAttribute . 'Id'] = [
                    'type' => 'foreign',
                ];

                if ($foreignType === 'link') {
                    $additionalAttributeDefs[$foreignAttribute . 'Name'] = [
                        'type' => 'varchar',
                    ];

                    $foreignEntityType = $this->getForeignLinkForeignEntityType($entityType, $link, $attribute);

                    if ($foreignEntityType) {
                        $foreignLinkFieldDataList[] = (object) [
                            'name' => $foreignAttribute,
                            'entityType' => $foreignEntityType,
                        ];
                    }
                }
            } else {
                $additionalAttributeDefs[$foreignAttribute] = [
                    'type' => $foreignAttributeType,
                    'relation' => $link,
                    'foreign' => $attribute,
                ];
            }
        }

        $query = $queryBuilder->build();

        try {
            $sth = $this->entityManager->getQueryExecutor()->execute($query);
        } catch (PDOException $e) {
            $this->handlePDOException($e);
        } catch (Exception $e) {
            $this->handleExecuteQueryException($e);
        }

        $count = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($query)
            ->count();

        $collection = $this->injectableFactory->createWith(SthCollection::class, [
            'sth' => $sth,
            'entityType' => $entityType,
            'attributeDefs' => $additionalAttributeDefs,
            'linkMultipleFieldList' => $linkMultipleFieldList,
            'foreignLinkFieldDataList' => $foreignLinkFieldDataList,
        ]);

        if (!$runParams->returnSthCollection()) {
            $newCollection = $this->entityManager->getCollectionFactory()->create($entityType);

            foreach ($collection as $entity) {
                $newCollection[] = $entity;
            }

            $collection = $newCollection;
        }

        return new ListResult(
            collection: $collection,
            total: $count,
            columns: $data->getColumns(),
            columnsData: $data->getColumnsData(),
        );
    }

    private function getForeignLinkForeignEntityType(string $entityType, string $link, string $field): ?string
    {
        $foreignEntityType1 = $this->metadata->get(['entityDefs', $entityType, 'links', $link, 'entity']);

        return $this->metadata->get(['entityDefs', $foreignEntityType1, 'links', $field, 'entity']);
    }

    /**
     * @throws Forbidden
     * @throws BadRequest
     */
    private function executeSubReportList(
        GridData $data,
        SearchParams $searchParams,
        SubReportParams $subReportParams,
        ?ListRunParams $runParams = null,
        ?User $user = null
    ): ListResult {

        $entityType = $data->getEntityType();

        $queryBuilder = $this->subReportQueryPreparator->prepare(
            $data,
            $searchParams,
            $subReportParams,
            $user
        );

        if ($runParams && $runParams->isFullSelect()) {
            $queryBuilder->select(['*']);
        }

        $collection = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($queryBuilder->build())
            ->find();

        $count = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($queryBuilder->build())
            ->count();

        $service = $this->recordServiceContainer->get($entityType);

        $loaderParams = LoaderParams::create()->withSelect($searchParams->getSelect());

        foreach ($collection as $entity) {
            $this->listLoadProcessor->process($entity, $loaderParams);

            $service->prepareEntityForOutput($entity);
        }

        return new ListResult($collection, $count);
    }

    /**
     * @throws Error
     * @throws Forbidden
     * @throws BadRequest
     */
    public function executeGridReport(
        GridData $data,
        ?WhereItem $where,
        ?User $user = null,
    ): GridResult {

        $groupValueMap = [];
        $numericColumnList = [];
        $subListColumnList = [];
        $summaryColumnList = [];

        foreach ($data->getColumns() as $item) {
            if ($this->gridHelper->isColumnNumeric($item, $data)) {
                $numericColumnList[] = $item;
            }
        }

        foreach ($data->getColumns() as $item) {
            if ($this->gridHelper->isColumnSummary($item, $data)) {
                $summaryColumnList[] = $item;

                continue;
            }

            if ($this->gridHelper->isColumnEligibleForSubList($item, $data)) {
                $subListColumnList[] = $item;
            }
        }

        if (count($data->getGroupBy()) === 2) {
            $subListColumnList = [];
        }

        $columnToBuildList = count($data->getGroupBy()) === 2 ?
            $summaryColumnList :
            $data->getColumns();

        $columnToBuildList = array_values(array_filter(
            $columnToBuildList,
            fn (string $item) => !in_array($item, $subListColumnList)
        ));

        $aggregatedColumnList = array_values(array_filter(
            $data->getColumns(),
            fn (string $item) => !in_array($item, $subListColumnList)
        ));

        $data = $data->withAggregatedColumns($aggregatedColumnList);

        if ($aggregatedColumnList === [] && $data->getGroupBy() === []) {
            $data = $data->withAggregatedColumns(['COUNT:(id)']);
        }

        if (count($subListColumnList)) {
            foreach ($columnToBuildList as $column) {
                if ($this->gridHelper->isColumnSubListAggregated($column)) {
                    $subListColumnList[] = $column;
                }
            }
        }

        $this->gridHelper->checkColumnsAvailability($data->getEntityType(), $data->getGroupBy());
        $this->gridHelper->checkColumnsAvailability($data->getEntityType(), $aggregatedColumnList);

        $query = $this->gridQueryPreparator->prepare($data, $where, $user);

        if ($query->getHaving() && !$query->getGroup()) {
            $this->throwError('badParams', 'havingFilterWithoutGroupByError');
        }

        try {
            $sth = $this->entityManager
                ->getQueryExecutor()
                ->execute($query);
        } catch (PDOException $e) {
            $this->handlePDOException($e);
        } catch (Exception $e) {
            $this->handleExecuteQueryException($e);
        }

        $rows = $sth->fetchAll(PDO::FETCH_ASSOC);

        $linkColumnList = array_merge(
            $this->gridHelper->obtainLinkColumnList($data),
            $this->gridHelper->obtainLinkColumnListFromColumns($data, $aggregatedColumnList),
        );

        $grouping = [];
        $sums = [];
        $cellValueMaps = (object) [];
        $nonSummaryColumnGroupMap = (object) [];
        $columnTypeMap = [];
        $columnDecimalPlacesMap = [];
        $columnNameMap = [];
        $nonSummaryColumnList = array_values(array_diff($data->getColumns(), $summaryColumnList));
        $emptyStringGroupExcluded = false;

        $groupList = array_map(
            fn (Expression $expr): string => $expr->getValue(),
            $query->getGroup()
        );

        $this->gridResultHelper->fixRows($rows, $groupList, $emptyStringGroupExcluded);
        $this->gridResultHelper->populateGroupValueMap($data, $groupList, $rows, $groupValueMap);
        $this->gridResultHelper->populateGrouping($data, $groupList, $rows, $where, $grouping);
        $this->gridResultHelper->populateRows($data, $groupList, $grouping, $rows, $nonSummaryColumnList);
        $this->gridResultHelper->populateGroupValueMapByLinkColumns($data, $linkColumnList, $rows, $groupValueMap);
        $this->gridResultHelper->populateGroupValueMapForDateFunctions($data, $grouping, $groupValueMap);
        $this->gridResultHelper->populateColumnInfo($data, $columnTypeMap, $columnDecimalPlacesMap, $columnNameMap);
        $this->gridResultHelper->sortGrouping($data, $grouping, $groupValueMap);

        $reportData = $this->gridBuilder->build(
            data: $data,
            rows: $rows,
            groupList: $groupList,
            columns: $columnToBuildList,
            sums: $sums,
            cellValueMaps: $cellValueMaps,
        );

        $nonSummaryData = $this->gridBuilder->buildNonSummary(
            columnList: $data->getColumns(),
            summaryColumnList: $summaryColumnList,
            data: $data,
            rows: $rows,
            groupList: $groupList,
            cellValueMaps: $cellValueMaps,
            nonSummaryColumnGroupMap: $nonSummaryColumnGroupMap,
        );

        $subListData = $this->executeGridReportSubList(
            groupValueList: $grouping[0],
            columnList: $subListColumnList,
            data: $data,
            where: $where,
            user: $user,
        );

        $resultObject = new GridResult(
            entityType: $data->getEntityType(),
            groupByList: $data->getGroupBy(),
            columnList: $data->getColumns(),
            numericColumnList: $numericColumnList,
            summaryColumnList: $summaryColumnList,
            nonSummaryColumnList: $nonSummaryColumnList,
            subListColumnList: $subListColumnList,
            aggregatedColumnList: $aggregatedColumnList,
            nonSummaryColumnGroupMap: $nonSummaryColumnGroupMap, // stdClass
            subListData: $subListData, // object<stdClass[]>
            sums: (object) $sums, // object<int|float>
            groupValueMap: $groupValueMap, // array<string, array<string, mixed>>
            columnNameMap: $columnNameMap, // array<string, string>
            columnTypeMap: $columnTypeMap, // array<string, string>
            cellValueMaps: $cellValueMaps, // object<object> (when grouping by link)
            grouping: $grouping, // array{string[]}|array{string[], string[]}
            reportData: $reportData, // object<object>|object<object<object>>
            nonSummaryData: $nonSummaryData, // object<object<object>>
            chartType: $data->getChartType(),
            chartDataList: $data->getChartDataList(), // stdClass[]
            columnDecimalPlacesMap: (object) $columnDecimalPlacesMap, // object<?int>,
            emptyStringGroupExcluded: $emptyStringGroupExcluded,
        );

        $resultObject->setSuccess($data->getSuccess());

        if ($data->getChartColors()) {
            $resultObject->setChartColors((object) $data->getChartColors());
        }

        if ($data->getChartColor() && $data->getChartType()) {
            $resultObject->setChartColor($data->getChartColor());
        }

        $this->gridResultHelper->calculateSums($data, $resultObject);

        return $resultObject;
    }

    /**
     * @return never-return
     * @throws Error
     */
    private function throwError(string $reason, string $message): void
    {
        // As of v7.1.
        if (class_exists("Espo\\Core\\Exceptions\\Error\\Body")) {
            throw Error::createWithBody(
                $reason,
                Error\Body::create()
                    ->withMessageTranslation($message, 'Report')
                    ->encode()

            );
        }

        throw new Error($message);
    }

    /**
     * @param string[] $groupValueList
     * @param string[] $columnList
     * @return stdClass // object<stdClass[]>
     *
     * @throws Forbidden
     * @throws BadRequest
     */
    private function executeGridReportSubList(
        array $groupValueList,
        array $columnList,
        GridData $data,
        ?WhereItem $where,
        ?User $user = null,
    ): object {

        if ($columnList === []) {
            return (object) [];
        }

        $result = (object) [];

        foreach ($groupValueList as $groupValue) {
            $result->$groupValue = $this->executeGridReportSubListItem(
                groupValue: $groupValue,
                columnList: $columnList,
                data: $data,
                where: $where,
                user: $user,
            );
        }

        return $result;
    }

    /**
     * @param ?scalar $groupValue
     * @param string[] $columnList
     * @return stdClass[]
     *
     * @throws Forbidden
     * @throws BadRequest
     *
     * @todo Add complex expression support. E.g. `LOWER:(name)`.
     */
    private function executeGridReportSubListItem(
        $groupValue,
        array $columnList,
        GridData $data,
        ?WhereItem $where,
        ?User $user = null,
    ): array {

        if ($groupValue === '') {
            $groupValue = null;
        }

        $realColumnList = array_map(
            function (string $column): string {
                return !str_contains($column, ':') ?
                    $column :
                    explode(':', $column)[1];
            },
            $columnList
        );

        $realColumnList = array_filter($realColumnList, function ($it) {
            if (str_starts_with($it, '(')) {
                return false;
            }

            return true;
        });

        $realColumnList = array_values($realColumnList);

        $query = $this->subListQueryPreparator->prepare(
            data: $data,
            groupValue: $groupValue,
            columnList: $columnList,
            realColumnList: $realColumnList,
            where: $where,
            user: $user,
        );

        $linkColumnList = $this->gridHelper->obtainLinkColumnListFromColumns($data, $realColumnList);

        $columnAttributeMap = [];

        foreach ($columnList as $column) {
            if (in_array($column, $linkColumnList)) {
                $columnAttributeMap[$column] = $column . 'Name';

                continue;
            }

            if (str_contains($column, ':')) {
                $columnAttributeMap[$column] = explode(':', $column)[1];

                continue;
            }

            $columnAttributeMap[$column] = $column;
        }

        $limit = $this->config->get('reportGridSubListLimit') ?? self::GRID_SUB_LIST_LIMIT;

        $collection = $this->entityManager
            ->getRDBRepository($data->getEntityType())
            ->clone($query)
            ->limit(0, $limit)
            ->find();

        $itemList = [];

        foreach ($collection as $entity) {
            $item = (object) ['id' => $entity->getId()];

            foreach ($columnList as $column) {
                $attribute = $columnAttributeMap[$column];
                $columnData = $this->gridHelper->getDataFromColumnName($data->getEntityType(), $column);

                $item->$column = $this->getCellDisplayValueFromEntity($entity, $attribute, $columnData);
            }

            $itemList[] = $item;
        }

        return $itemList;
    }

    /**
     * @return scalar|string[]
     */
    private function getCellDisplayValueFromEntity(Entity $entity, string $attribute, ColumnData $columnData)
    {
        if ($columnData->fieldType === 'datetimeOptional' && $entity->get($attribute . 'Date')) {
            $attribute = $attribute . 'Date';

            $columnData->fieldType = 'date';
        }

        return $this->gridUtil->getCellDisplayValue($entity->get($attribute), $columnData);
    }

    /**
     * @return array<string, array<int, mixed>>
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     * @throws BadRequest
     */
    public function getReportResultsTableData(
        string $id,
        ?WhereItem $where = null,
        ?string $column = null,
        ?User $user = null
    ): array {

        $report = $this->entityManager->getRDBRepositoryByClass(Report::class)->getById($id);

        if (!$report) {
            throw new NotFound();
        }

        if ($report->getType() === Report::TYPE_LIST) {
            $searchParams = SearchParams::create();

            if ($where) {
                $searchParams = $searchParams->withWhere($where);
            }

            $result = $this->runList($id, $searchParams, $user);
        } else {
            $result = $this->runGrid($id, $where, $user);
        }

        if ($result instanceof ListResult) {
            /** @var array<string, mixed> $resultData */
            $resultData = [];

            foreach ($result->getCollection() as $e) {
                $resultData[] = get_object_vars($e->getValueMap());
            }
        } else {
            $resultData = $result;
        }

        $data = (object) [
            'userId' => $user ? $user->getId() : $this->user->getId(),
            'specificColumn' => $column,
        ];

        $service = $this->injectableFactory->create(SendingService::class);

        /** @var GridResult|array<int, mixed> $resultData */

        $service->buildData($data, $resultData, $report);

        return $data->tableData ?? [];
    }

    /**
     * @return never-return
     * @throws Error
     */
    private function handlePDOException(PDOException $e): void
    {
        if ((int)$e->getCode() === 42000) {
            $message = str_contains($e->getMessage(), ': 1055') ?
                'onlyFullGroupByError' :
                'sqlSyntaxError';

            $this->log->error($e->getMessage());
            $this->throwError('sqlSyntaxError', $message);
        }

        if ($e->getCode() === '42S22') {
            $this->log->error($e->getMessage());
            $this->throwError('invalidColumnError', 'invalidColumnError');
        }

        $this->log->error($e->getMessage());
        $this->throwError('executionError', 'executionError');
    }

    /**
     * @return never-return
     * @throws Error
     */
    private function handleExecuteQueryException(Exception $e): void
    {
        $msg = $e->getMessage() . "; file: {$e->getFile()}; line: {$e->getLine()}";

        $this->log->error($msg);
        $this->throwError('executionError', 'executionError');
    }

    /**
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function reportRunList(
        Report $report,
        ?SearchParams $searchParams,
        ?User $user,
        ?ListRunParams $runParams = null,
    ): ListResult {

        $runParams ??= ListRunParams::create();

        if ($report->isInternal()) {
            $impl = $this->reportHelper->createInternalReport($report);

            if (!$impl instanceof ListReport) {
                throw new Error("Bad report class.");
            }

            return $impl->run($searchParams, $user);
        }

        if ($report->getType() !== Report::TYPE_LIST) {
            throw new Error("Can't run non-List report as List.");
        }

        if (!$report->getTargetEntityType()) {
            $id = $report->getId();

            throw new Error("No entity type in report $id.");
        }

        if (
            $searchParams &&
            $searchParams->getWhere() &&
            !$runParams->skipRuntimeFiltersCheck()
        ) {
            $this->reportHelper->checkRuntimeFilters($searchParams->getWhere(), $report);
        }

        return $this->executeListReport(
            data: $this->reportHelper->fetchListDataFromReport($report),
            searchParams: $searchParams,
            runParams: $runParams,
            user: $user,
        );
    }

    /**
     * @param ?array<string, ?WhereItem> $idWhereMap
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     */
    public function reportRunGridOrJoint(
        Report $report,
        ?WhereItem $whereItem,
        ?User $user,
        ?GridRunParams $runParams = null,
        ?array $idWhereMap = null,
    ): GridResult {

        if ($report->isInternal()) {
            $impl = $this->reportHelper->createInternalReport($report);

            if (!$impl instanceof GridReport) {
                throw new Error("Bad report class.");
            }

            return $impl->run($whereItem, $user);
        }

        if (
            $whereItem &&
            (!$runParams || !$runParams->skipRuntimeFiltersCheck())
        ) {
            $this->reportHelper->checkRuntimeFilters($whereItem, $report);
        }

        switch ($report->getType()) {
            case Report::TYPE_GRID:
                return $this->executeGridReport(
                    $this->reportHelper->fetchGridDataFromReport($report),
                    $whereItem,
                    $user
                );

            case Report::TYPE_JOINT_GRID:
                return $this->injectableFactory
                    ->createWith(JointGridExecutor::class, ['service' => $this])
                    ->execute(
                        $this->reportHelper->fetchJointDataFromReport($report),
                        $user,
                        $idWhereMap
                    );
        }

        throw new Error("Unknown type.");
    }
}
