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

use Espo\Core\AclManager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Attachment;
use Espo\Entities\Template;
use Espo\Entities\User;
use Espo\Modules\Advanced\Core\Report\ExportXlsx;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Tools\Report\GridType\Helper as GridHelper;
use Espo\Modules\Advanced\Tools\Report\GridType\Result as GridResult;
use Espo\Modules\Advanced\Tools\Report\GridType\Util as GridUtil;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\ORM\EntityManager;
use Espo\Tools\Pdf\Data;
use Espo\Tools\Pdf\Service as PdfService;
use PhpOffice\PhpSpreadsheet\Writer\Exception;
use RuntimeException;

class GridExportService
{
    private const STUB_KEY = '__STUB__';

    public function __construct(
        private EntityManager $entityManager,
        private AclManager $aclManager,
        private Metadata $metadata,
        private Config $config,
        private Language $language,
        private Service $service,
        private GridHelper $gridHelper,
        private GridUtil $gridUtil,
        private InjectableFactory $injectableFactory
    ) {}

    /**
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function exportXlsx(string $id, ?WhereItem $where, ?User $user = null): string
    {
        /** @var ?Report $report */
        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $id);

        if (!$report) {
            throw new NotFound();
        }

        if ($user && !$this->aclManager->checkEntityRead($user, $report)) {
            throw new Forbidden();
        }

        $contents = $this->buildXlsxContents($id, $where, $user);

        $name = preg_replace("/([^\w\s\d\-_~,;:\[\]().])/u", '_', $report->getName()) . ' ' . date('Y-m-d');

        $mimeType = $this->metadata->get(['app', 'export', 'formatDefs', 'xlsx', 'mimeType']);
        $fileExtension = $this->metadata->get(['app', 'export', 'formatDefs', 'xlsx', 'fileExtension']);

        $fileName = $name . '.' . $fileExtension;

        $attachment = $this->entityManager->getNewEntity(Attachment::ENTITY_TYPE);

        $attachment->set('name', $fileName);
        $attachment->set('role', 'Export File');
        $attachment->set('type', $mimeType);
        $attachment->set('contents', $contents);
        $attachment->set([
            'relatedType' => Report::ENTITY_TYPE,
            'relatedId' => $id,
        ]);

        $this->entityManager->saveEntity($attachment);

        return $attachment->getId();
    }

    /**
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     * @throws Exception
     * @throws BadRequest
     */
    public function buildXlsxContents(string $id, ?WhereItem $where, ?User $user = null): string
    {
        /** @var ?Report $report */
        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $id);

        if (!$report) {
            throw new NotFound();
        }

        $entityType = $report->getTargetEntityType();

        $groupCount = count($report->getGroupBy());

        $columnList = $report->getColumns();
        $groupByList = $report->getGroupBy();

        $reportResult = null;

        if (
            $report->getType() === Report::TYPE_JOINT_GRID ||
            !$report->getGroupBy()
        ) {
            $reportResult = $this->service->runGrid($id, $where, $user);

            $columnList = $reportResult->getColumnList();
            $groupByList = $reportResult->getGroupByList();
            $groupCount = count($groupByList);
        }

        if (!$reportResult) {
            $reportResult = $this->service->runGrid($id, $where, $user);
        }

        $result = [];

        if ($groupCount === 2) {
            foreach ($reportResult->getSummaryColumnList() as $column) {
                $result[] = $this->getGridReportResultForExport($id, $where, $column, $user, $reportResult);
            }
        } else {
            $result[] = $this->getGridReportResultForExport($id, $where, null, $user, $reportResult);
        }

        $columnTypes = [];

        foreach ($columnList as $item) {
            $columnData = $this->gridHelper->getDataFromColumnName($entityType, $item, $reportResult);

            $type = $this->metadata
                ->get(['entityDefs', $columnData->entityType, 'fields', $columnData->field, 'type']);

            if (
                $entityType === Opportunity::ENTITY_TYPE &&
                $columnData->field === 'amountWeightedConverted'
            ) {
                $type = 'currencyConverted';
            }

            if ($columnData->function === 'COUNT') {
                $type = 'int';
            }

            $columnTypes[$item] = $type;
        }

        $columnLabels = [];

        if ($groupCount === 2) {
            $columnNameMap = $reportResult->getColumnNameMap() ?? [];

            foreach ($columnList as $column) {
                $columnLabels[$column] = $columnNameMap[$column];
            }
        }

        $exportParams = [
            'exportName' => $report->getName(),
            'columnList' => $columnList,
            'columnTypes' => $columnTypes,
            'chartType' => $report->get('chartType'),
            'groupByList' => $groupByList,
            'columnLabels' => $columnLabels,
            'reportResult' => $reportResult,
            'groupLabel' => '',
        ];

        if ($groupCount) {
            $group = $groupByList[$groupCount - 1];
            $exportParams['groupLabel'] = $this->gridUtil->translateGroupName($entityType, $group);
        }

        $export = $this->injectableFactory->create(ExportXlsx::class);

        return $export->process($entityType, $exportParams, $result);
    }

    /**
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function exportCsv(
        string $id,
        ?WhereItem $where,
        ?string $column = null,
        ?User $user = null
    ): string  {

        /** @var ?Report $report */
        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $id);

        if (!$report) {
            throw new NotFound();
        }

        if ($user && !$this->aclManager->checkEntityRead($user, $report)) {
            throw new Forbidden();
        }

        $contents = $this->getGridReportCsv($id, $where, $column, $user);

        $name = preg_replace("/([^\w\s\d\-_~,;:\[\]().])/u", '_', $report->getName()) . ' ' . date('Y-m-d');

        $mimeType = $this->metadata->get(['app', 'export', 'formatDefs', 'csv', 'mimeType']);
        $fileExtension = $this->metadata->get(['app', 'export', 'formatDefs', 'csv', 'fileExtension']);

        $fileName = $name . '.' . $fileExtension;

        $attachment = $this->entityManager->getEntity('Attachment');

        $attachment->set('name', $fileName);
        $attachment->set('role', 'Export File');
        $attachment->set('type', $mimeType);
        $attachment->set('contents', $contents);
        $attachment->set([
            'relatedType' => Report::ENTITY_TYPE,
            'relatedId' => $id,
        ]);

        $this->entityManager->saveEntity($attachment);

        return $attachment->getId();
    }

    /**
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     * @throws BadRequest
     */
    private function getGridReportCsv(
        string $id,
        ?WhereItem $where,
        ?string $column = null,
        ?User $user = null
    ): string {

        $result = $this->getGridReportResultForExport($id, $where, $column, $user);

        $delimiter = $this->config->get('exportDelimiter', ';');

        $fp = fopen('php://temp', 'w');

        if ($fp === false) {
            throw new RuntimeException("Could not open temp.");
        }

        foreach ($result as $row) {
            fputcsv($fp, $row, $delimiter);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        if ($csv === false) {
            throw new RuntimeException("Could not get from stream.");
        }

        return $csv;
    }

    /**
     * @return array<int, mixed>[]
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     * @throws BadRequest
     */
    private function getGridReportResultForExport(
        string $id,
        ?WhereItem $where,
        ?string $currentColumn = null,
        ?User $user = null,
        ?GridResult $reportResult = null
    ): array {

        if (!$reportResult) {
            $reportResult = $this->service->runGrid($id, $where, $user);
        }

        $depth = count($reportResult->getGroupByList());
        $reportData = $reportResult->getReportData();

        $result = [];

        if ($depth == 2) {
            $groupName1 = $reportResult->getGroupByList()[0];
            $groupName2 = $reportResult->getGroupByList()[1];

            $group1NonSummaryColumnList = [];
            $group2NonSummaryColumnList = [];

            if ($reportResult->getGroup1NonSummaryColumnList() !== null) {
                $group1NonSummaryColumnList = $reportResult->getGroup1NonSummaryColumnList();
            }

            if ($reportResult->getGroup2NonSummaryColumnList() !== null) {
                $group2NonSummaryColumnList = $reportResult->getGroup2NonSummaryColumnList();
            }

            $row = [];

            $row[] = '';

            foreach ($group2NonSummaryColumnList as $column) {
                $text = $reportResult->getColumnNameMap()[$column];

                $row[] = $text;
            }

            foreach ($reportResult->getGrouping()[0] ?? [] as $gr1) {
                $label = $gr1;

                if (empty($label)) {
                    $label = $this->language->translate('-Empty-', 'labels', 'Report');
                }
                else if (!empty($reportResult->getGroupValueMap()[$groupName1][$gr1])) {
                    $label = $reportResult->getGroupValueMap()[$groupName1][$gr1];
                }

                $row[] = $label;
            }

            $result[] = $row;

            foreach ($reportResult->getGrouping()[1] ?? [] as $gr2) {
                $row = [];
                $label = $gr2;

                if (empty($label)) {
                    $label = $this->language->translate('-Empty-', 'labels', 'Report');
                }
                else if (!empty($reportResult->getGroupValueMap()[$groupName2][$gr2])) {
                    $label = $reportResult->getGroupValueMap()[$groupName2][$gr2];
                }

                $row[] = $label;

                foreach ($group2NonSummaryColumnList as $column) {
                    $row[] = $this->getCellDisplayValueFromResult(1, $gr2, $column, $reportResult);
                }

                foreach ($reportResult->getGrouping()[0] ?? [] as $gr1) {
                    $value = 0;

                    if (!empty($reportData->$gr1) && !empty($reportData->$gr1->$gr2)) {
                        if (!empty($reportData->$gr1->$gr2->$currentColumn)) {
                            $value = $reportData->$gr1->$gr2->$currentColumn;
                        }
                    }

                    $row[] = $value;
                }

                $result[] = $row;
            }

            $row = [];

            $row[] = $this->language->translate('Total', 'labels', 'Report');

            foreach ($group2NonSummaryColumnList as $ignored) {
                $row[] = '';
            }

            foreach ($reportResult->getGrouping()[0] ?? [] as $gr1) {
                $sum = 0;

                if (!empty($reportResult->getGroup1Sums()->$gr1)) {
                    if (!empty($reportResult->getGroup1Sums()->$gr1->$currentColumn)) {
                        $sum = $reportResult->getGroup1Sums()->$gr1->$currentColumn;
                    }
                }

                $row[] = $sum;
            }

            $result[] = $row;

            if (count($group1NonSummaryColumnList)) {
                $result[] = [];
            }

            foreach ($group1NonSummaryColumnList as $column) {
                $row = [];
                $text = $reportResult->getColumnNameMap()[$column];
                $row[] = $text;

                foreach ($group2NonSummaryColumnList as $ignored) {
                    $row[] = '';
                }

                foreach ($reportResult->getGrouping()[0] ?? [] as $gr1) {
                    $row[] = $this->getCellDisplayValueFromResult(0, $gr1, $column, $reportResult);
                }

                $result[] = $row;
            }

        } else if ($depth === 1 || $depth === 0) {
            $aggregatedColumnList = $reportResult->getAggregatedColumnList();

            if ($depth === 1) {
                $groupName = $reportResult->getGroupByList()[0];
            } else {
                $groupName = self::STUB_KEY;
            }

            $row = [];
            $row[] = '';

            foreach ($aggregatedColumnList as $column) {
                $label = $column;

                if (!empty($reportResult->getColumnNameMap()[$column])) {
                    $label = $reportResult->getColumnNameMap()[$column];
                }

                $row[] = $label;
            }

            $result[] = $row;

            foreach ($reportResult->getGrouping()[0] ?? [] as $gr) {
                $row = [];

                $label = $gr;

                if (empty($label)) {
                    $label = $this->language->translate('-Empty-', 'labels', 'Report');
                }
                else if (
                    !empty($reportResult->getGroupValueMap()[$groupName]) &&
                    array_key_exists($gr, $reportResult->getGroupValueMap()[$groupName])
                ) {
                    $label = $reportResult->getGroupValueMap()[$groupName][$gr];
                }

                $row[] = $label;

                foreach ($aggregatedColumnList as $column) {
                    if (in_array($column, $reportResult->getNumericColumnList())) {
                        $value = 0;

                        if (!empty($reportData->$gr)) {
                            if (!empty($reportData->$gr->$column)) {
                                $value = $reportData->$gr->$column;
                            }
                        }
                    }
                    else {
                        $value = '';

                        if (property_exists($reportData, $gr) && property_exists($reportData->$gr, $column)) {
                            $value = $reportData->$gr->$column;

                            if (
                                !is_null($value) &&
                                property_exists($reportResult->getCellValueMaps(), $column) &&
                                property_exists($reportResult->getCellValueMaps()->$column, $value)
                            ) {
                                $value = $reportResult->getCellValueMaps()->$column->$value;
                            }
                        }
                    }

                    $row[] = $value;
                }

                $result[] = $row;
            }

            if ($depth) {
                $row = [];

                $row[] = $this->language->translate('Total', 'labels', 'Report');

                foreach ($aggregatedColumnList as $column) {
                    if (!in_array($column, $reportResult->getNumericColumnList())) {
                        $row[] = '';

                        continue;
                    }

                    $sum = 0;

                    if (!empty($reportResult->getSums()->$column)) {
                        $sum = $reportResult->getSums()->$column;
                    }

                    $row[] = $sum;
                }

                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * @return mixed
     */
    public function getCellDisplayValueFromResult(
        int $groupIndex,
        string $gr1,
        string $column,
        GridResult $reportResult
    ) {

        $groupName = $reportResult->getGroupByList()[$groupIndex];

        $dataMap = $reportResult->getNonSummaryData()->$groupName;

        $value = '';

        if ($this->gridHelper->isColumnNumeric($column, $reportResult)) {
            $value = 0;
        }

        if (
            property_exists($dataMap, $gr1) &&
            property_exists($dataMap->$gr1, $column)
        ) {
            $value = $dataMap->$gr1->$column;
        }

        if (
            !$this->gridHelper->isColumnNumeric($column, $reportResult) &&
            !is_null($value)
        ) {
            if (property_exists($reportResult->getCellValueMaps(), $column)) {
                if (property_exists($reportResult->getCellValueMaps()->$column, $value)) {
                    $value = $reportResult->getCellValueMaps()->$column->$value;
                }
            }
        }

        if (is_null($value)) {
            $value = '';
        }

        return $value;
    }

    /**
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function exportPdf(
        string $id,
        ?WhereItem $where,
        string $templateId,
        ?User $user = null
    ): string {

        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $id);
        $template = $this->entityManager->getEntityById(Template::ENTITY_TYPE, $templateId);

        if (!$report || !$template) {
            throw new NotFound();
        }

        if ($user) {
            if (!$this->aclManager->checkEntityRead($user, $report)) {
                throw new Forbidden("No access to report.");
            }

            if (!$this->aclManager->checkEntityRead($user, $template)) {
                throw new Forbidden("No access to template.");
            }
        }

        $additionalData = [
            'user' => $user,
            'reportWhere' => $where,
        ];

        $pdfService = $this->injectableFactory->create(PdfService::class);

        $contents = $pdfService
            ->generate(
                Report::ENTITY_TYPE,
                $report->getId(),
                $template->getId(),
                null,
                Data::create()->withAdditionalTemplateData((object) $additionalData)
            )
            ->getString();

        $attachment = $this->entityManager->createEntity(Attachment::ENTITY_TYPE, [
            'contents' => $contents,
            'role' => 'Export File',
            'type' => 'application/pdf',
            'relatedId' => $id,
            'relatedType' => Report::ENTITY_TYPE,
        ]);

        return $attachment->getId();
    }
}
