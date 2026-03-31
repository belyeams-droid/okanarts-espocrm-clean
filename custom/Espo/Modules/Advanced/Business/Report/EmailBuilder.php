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

namespace Espo\Modules\Advanced\Business\Report;

use Espo\Core\Exceptions\Error;
use Espo\Core\Htmlizer\TemplateRendererFactory;
use Espo\Core\InjectableFactory;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Utils\Log;
use Espo\Entities\Email;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Tools\Report\GridExportService;
use Espo\Modules\Advanced\Tools\Report\GridType\Helper;
use Espo\Modules\Advanced\Tools\Report\GridType\Result as GridResult;
use Espo\ORM\EntityManager;
use Espo\Entities\Attachment;
use Espo\Entities\Preferences;
use Espo\Core\Utils\DateTime;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\TemplateFileManager;
use Exception;
use RuntimeException;
use stdClass;

class EmailBuilder
{
    private DateTime $dateTime;
    private Preferences $preferences;

    public function __construct(
        private Metadata $metadata,
        private EntityManager $entityManager,
        private Config $config,
        private Language $language,
        private TemplateFileManager $templateFileManager,
        private FileStorageManager $fileStorageManager,
        private Helper $gridHelper,
        private GridExportService $gridExportService,
        private InjectableFactory $injectableFactory,
        private EmailSender $emailSender,
        private TemplateRendererFactory $templateRendererFactory,
        private Log $log
    ) {
    }

    private function initForUserById(string $userId): void
    {
        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

        if (!$user) {
            throw new RuntimeException('Report Sending Builder: No User with id = ' . $userId);
        }

        /** @var ?Preferences $preferences */
        $preferences = $this->entityManager->getEntityById(Preferences::ENTITY_TYPE, $userId);

        $this->preferences = $preferences;

        $this->language = $this->injectableFactory->createWith(Language::class, [
            'language' => $this->getPreference('language'),
            'useCache' => $this->config->get('useCache'),
        ]);

        $this->dateTime = new DateTime(
            $this->getPreference('dateFormat'),
            $this->getPreference('timeFormat'),
            $this->getPreference('timeZone')
        );
    }

    /**
     * @return mixed
     */
    private function getPreference(string $attribute)
    {
        if (
            $this->preferences->get($attribute) !== null &&
            $this->preferences->get($attribute) !== ''
        ) {
            $this->preferences->get($attribute);
        }

        return $this->config->get($attribute);
    }

    /**
     * @param stdClass $data
     * @param GridResult|array<int, mixed> $reportResult
     * @param bool $isLocal Images will be included.
     * @throws Error
     */
    public function buildEmailData($data, $reportResult, Report $report, bool $isLocal = false): void
    {
        if (!is_object($data) || !isset($data->userId)) {
            throw new RuntimeException('Report Sending Builder: Not enough data for sending email.');
        }

        $this->initForUserById($data->userId);

        $type = $report->getType();

        switch ($type) {
            case Report::TYPE_GRID:
            case Report::TYPE_JOINT_GRID:
                assert($reportResult instanceof GridResult);

                $this->buildEmailGridData($data, $reportResult, $report);

                break;

            case Report::TYPE_LIST:
                assert(is_array($reportResult));

                $this->buildEmailListData($data, $reportResult, $report, $isLocal);

                break;
        }
    }

    /**
     * @param stdClass $data
     * @throws Error
     */
    private function buildEmailGridData($data, GridResult $reportResult, Report $report): void
    {
        $depth = count($reportResult->getGroupByList());

        if ($depth === 2) {
            $this->buildEmailGrid2Data($data, $reportResult, $report);

            return;
        }

        $this->buildEmailGrid1Data($data, $reportResult, $report);
    }

    /**
     * @param stdClass $data
     * @param array<int, mixed> $reportResult
     * @throws Error
     */
    private function buildEmailListData($data, array $reportResult, Report $report, bool $isLocal): void
    {
        $entityType = $report->getTargetEntityType();
        $columns = $report->getColumns();
        $columnsData = get_object_vars($report->getColumnsData());

        $fields = $this->metadata->get(['entityDefs', $entityType, 'fields']);

        $columnAttributes = [];

        foreach ($columns as $column) {
            $columnData = $columnsData[$column] ?? null;
            $attrs = [];

            if (is_object($columnData)) {
                if (isset($columnData->width)) {
                    $attrs['width'] = $columnData->width . '%';
                }

                if (isset($columnData->align)) {
                    $attrs['align'] = $columnData->align;
                }
            }

            $columnAttributes[$column] = $attrs;
        }

        $columnTitles = [];

        foreach ($columns as $column) {
            $field = $column;
            $scope = $entityType;
            $isForeign = false;

            if (str_contains($column, '.')) {
                $isForeign = true;

                [$link, $field] = explode('.', $column);

                $scope = $this->metadata->get(['entityDefs', $entityType, 'links', $link, 'entity']);
                $fields[$column] = $this->metadata->get(['entityDefs', $scope, 'fields', $field]);
                $fields[$column]['scope'] = $scope;
                $fields[$column]['isForeign'] = true;
            }

            $label = $this->language->translateLabel($field, 'fields', $scope);

            if ($isForeign) {
                $label = $this->language->translateLabel($link ?? '', 'links', $entityType) . ' . ' . $label;
            }

            $columnTitles[] = [
                'label' => $label,
                'attrs' => $columnAttributes[$column],
                'value' => $label,
                'isBold' => true,
            ];
        }

        $rows = [];

        foreach ($reportResult as $recordKey => $record) {
            foreach ($columns as $columnKey => $column) {
                $type = (isset($fields[$column])) ? $fields[$column]['type'] : '';
                $columnInRecord = str_replace('.', '_', $column);

                $value = $record[$columnInRecord] ?? null;
                $value = is_scalar($value) ? (string) $record[$columnInRecord] : '';

                switch ($type) {
                    case 'date':
                        if (!empty($value)) {
                            $value = $this->dateTime->convertSystemDate($value);
                        }

                        break;

                    case 'datetime':
                    case 'datetimeOptional':
                        if (!empty($value)) {
                            $value = $this->dateTime->convertSystemDateTime($value);
                        }

                        break;

                    case 'link':
                    case 'linkParent':
                        if (!empty($record[$columnInRecord . 'Name'])) {
                            $value = $record[$columnInRecord . 'Name'];
                        }

                        break;

                    case 'linkMultiple':
                        if (!empty($record[$columnInRecord . 'Names'])) {
                            $value = implode(', ', array_values( (array) $record[$columnInRecord . 'Names']));
                        }

                        break;

                    case 'jsonArray':
                        break;

                    case 'bool':
                        $value = ($value) ? '1' : '0';

                        break;

                    case 'enum':
                        if (isset($fields[$column]['isForeign']) && $fields[$column]['isForeign']) {
                            [, $field] = explode('.', $column);

                            $value = $this->language->translateOption($value, $field, $fields[$column]['scope']);
                        }
                        else {
                            $value = $this->language->translateOption($value, $column, $entityType);
                        }

                        break;

                    case 'int':
                        $value = $this->formatInt($value);

                        break;

                    case 'float':
                        $isCurrency = isset($fields[$column]['view']) &&
                            strpos($fields[$column]['view'], 'currency-converted');

                        $decimalPlaces = $fields[$column]['decimalPlaces'] ?? null;

                        $value = ($isCurrency) ?
                            $this->formatCurrency($value, null, $decimalPlaces) :
                            $this->formatFloat($value, $decimalPlaces);

                        break;

                    case 'currency':
                        $decimalPlaces = $fields[$column]['decimalPlaces'] ?? null;

                        $value = $this->formatCurrency($value, $record[$columnInRecord . 'Currency'], $decimalPlaces);

                        break;

                    case 'currencyConverted':
                        $decimalPlaces = $fields[$column]['decimalPlaces'] ?? null;

                        $value = $this->formatCurrency($value, null, $decimalPlaces);

                        break;

                    case 'address':
                        $value = '';

                        if (!empty($record[$columnInRecord . 'Street'])) {
                            $value = $record[$columnInRecord.'Street'];
                        }

                        if (
                            !empty($record[$columnInRecord.'City']) ||
                            !empty($record[$columnInRecord.'State']) ||
                            !empty($record[$columnInRecord.'PostalCode'])
                        ) {
                            if ($value) {
                                $value .= "  ";
                            }

                            if (!empty($record[$columnInRecord.'City'])) {
                                $value .= $record[$columnInRecord.'City'];

                                if (
                                    !empty($record[$columnInRecord.'State']) ||
                                    !empty($record[$columnInRecord.'PostalCode'])
                                ) {
                                    $value .= ', ';
                                }
                            }

                            if (!empty($record[$columnInRecord.'State'])) {
                                $value .= $record[$columnInRecord.'State'];

                                if (!empty($record[$columnInRecord.'PostalCode'])) {
                                    $value .= ' ';
                                }
                            }

                            if (!empty($record[$columnInRecord.'PostalCode'])) {
                                $value .= $record[$columnInRecord.'PostalCode'];
                            }
                        }

                        if (!empty($record[$columnInRecord.'Country'])) {
                            if ($value) {
                                $value .= "  ";
                            }

                            $value .= $record[$columnInRecord.'Country'];
                        }
                            break;

                        case 'array':
                        case 'multiEnum':
                        case 'checklist':

                            $value = $record[$columnInRecord] ?? [];

                            if (is_array($value)) {
                                $value = implode(", ", $value);
                            } else {
                                $value = '';
                            }

                            break;

                    case 'image':
                        if (!$isLocal) {
                            break;
                        }

                        $attachmentId = $record[$columnInRecord . 'Id'] ?? null;

                        if ($attachmentId) {
                            /** @var ?Attachment $attachment */
                            $attachment = $this->entityManager
                                ->getEntity(Attachment::ENTITY_TYPE, $attachmentId);

                            if ($attachment) {
                                $filePath = $this->fileStorageManager->getLocalFilePath($attachment);

                                $value = "<img src=\"$filePath\" alt=\"image\">";
                            }
                        }

                        break;
                    }

                $rows[$recordKey][$columnKey] = [
                    'value' => $value,
                    'attrs' => $columnAttributes[$column],
                ];
            }
        }
        $bodyData = [
            'columnList' => $columnTitles,
            'rowList' => $rows,
            'noDataLabel' => $this->language->translateLabel('No Data'),
        ];

        try {
            $subject = $this->renderReport($report, 'reportSendingList', 'subject');
            $body = $this->renderReport($report, 'reportSendingList', 'body', $bodyData);
        }
        catch (Exception $e) {
            $this->log->error($e->getMessage());

            throw Error::createWithBody(
                'emailTemplateParsingError',
                Error\Body::create()
                    ->withMessageTranslation('emailTemplateParsingError', 'Report',
                        ['template' => 'reportSendingList'])
                    ->encode()
            );
        }

        $data->emailSubject = $subject;
        $data->emailBody = $body;
        $data->tableData = array_merge([$columnTitles], $rows);
    }

    /**
     * @param stdClass $data
     * @throws Error
     */
    private function buildEmailGrid1Data($data, GridResult $reportResult, Report $report): void
    {
        $reportData = $reportResult->getReportData();

        /** @var array<string, int> $columnDecimalPlacesMap */
        $columnDecimalPlacesMap = get_object_vars($reportResult->getColumnDecimalPlacesMap());

        $rows = [];

        $groupCount = count($reportResult->getGroupByList());

        if ($groupCount) {
            $groupName = $reportResult->getGroupByList()[0];
        } else {
            $groupName = '__STUB__';
        }

        $row = [];

        $row[] = ['value' => ''];

        $columnTypes = [];

        $hasSubListColumns = count($reportResult->getSubListColumnList()) > 0;

        foreach ($reportResult->getColumnList() as $column) {
            $allowedTypeList = [
                'int',
                'float',
                'currency',
                'currencyConverted',
            ];

            $columnType = null;
            $function = null;

            if (strpos($column, ':')) {
                [$function, $field] = explode(':', $column);
            }
            else {
                $field = $column;
            }

            $fieldEntityType = $report->getTargetEntityType();

            $columnType = $reportResult->getColumnTypeMap()[$column] ?? null;

            if (strpos($column, '.')) {
                [$link, $field] = explode('.', $column);

                $fieldEntityType = $this->metadata->get(['entityDefs', $fieldEntityType, 'links', $link, 'entity']);
            }

            if (!$columnType) {
                if ($function === 'COUNT') {
                    $columnType = 'int';
                }
                else {
                    $columnType = $this->metadata->get(['entityDefs', $fieldEntityType, 'fields', $field, 'type']);
                }
            }

            $columnTypes[$column] = (in_array($columnType, $allowedTypeList)) ? $columnType : 'float';

            $label = $column;

            if (isset($reportResult->getColumnNameMap()[$column])) {
                $label = $reportResult->getColumnNameMap()[$column];
            }

            $row[] = [
                'value' => $label,
                'isBold' => true,
            ];
        }

        $rows[] = $row;

        foreach ($reportResult->getGrouping()[0] ?? [] as $gr) {
            $rows[] = $this->buildEmailGrid1GroupingRow(
                $gr,
                $groupName,
                $reportData,
                $reportResult,
                $columnTypes,
                $columnDecimalPlacesMap
            );

            if ($hasSubListColumns) {
                $rows = array_merge(
                    $rows,
                    $this->buildEmailGrid1SubListRowList($gr, $reportResult, $columnTypes, $columnDecimalPlacesMap)
                );

                $rows[] = $this->buildEmailGrid1GroupingRow(
                    $gr,
                    $groupName,
                    $reportData,
                    $reportResult,
                    $columnTypes,
                    $columnDecimalPlacesMap,
                    true
                );
            }
        }

        if ($groupCount) {
            $row = [];

            $totalLabel = $this->language->translateLabel('Total', 'labels', 'Report');

            $row[] = [
                'value' => $totalLabel,
                'isBold' => true,
            ];

            foreach ($reportResult->getColumnList() as $column) {
                if (
                    in_array($column, $reportResult->getNumericColumnList()) &&
                    (
                        //!isset($reportResult['aggregatedColumnList']) ||
                        in_array($column, $reportResult->getAggregatedColumnList())
                    )
                ) {
                    $sum = 0;

                    if (isset($reportResult->getSums()->$column)) {
                        $sum = $reportResult->getSums()->$column;

                        switch ($columnTypes[$column]) {
                            case 'int':
                                $sum = $this->formatInt($sum);

                                break;

                            case 'float':
                                $decimalPlaces = $columnDecimalPlacesMap[$column] ?? null;

                                $sum = $this->formatFloat($sum, $decimalPlaces);

                                break;

                            case 'currency':
                            case 'currencyConverted':
                                $decimalPlaces = $columnDecimalPlacesMap[$column] ?? null;

                                $sum = $this->formatCurrency($sum, null, $decimalPlaces);
                                break;
                        }
                    }
                }
                else {
                    $sum = '';
                }

                $row[] = [
                    'value' => $sum,
                    'isBold' => true,
                    'attrs' => ['align' => 'right'],
                ];
            }

            $rows[] = $row;
        }
        else {
            foreach ($rows as &$row) {
                unset($row[0]);
            }
        }

        $bodyData = [
            'rowList' => $rows,
        ];

        try {
            $subject = $this->renderReport($report, 'reportSendingGrid1', 'subject');
            $body = $this->renderReport($report, 'reportSendingGrid1', 'body', $bodyData);
        }
        catch (Exception $e) {
            $this->log->error($e->getMessage());

            throw Error::createWithBody(
                'emailTemplateParsingError',
                Error\Body::create()
                    ->withMessageTranslation('emailTemplateParsingError', 'Report',
                        ['template' => 'reportSendingGrid1'])
                    ->encode()
            );
        }

        $data->emailSubject = $subject;
        $data->emailBody = $body;
        $data->tableData = $rows;
    }

    /**
     * @param array<string, string> $columnTypes
     * @param array<string, int> $columnDecimalPlacesMap
     * @return (array{value: mixed, isBold?: bool, attrs?: array<string, mixed>}|array<int, mixed>)[]
     */
    private function buildEmailGrid1GroupingRow(
        string $gr,
        string $groupName,
        object $reportData,
        GridResult $reportResult,
        array $columnTypes,
        array $columnDecimalPlacesMap,
        bool $onlyNumeric = false
    ): array {

        $row = [];

        $hasSubListColumns = count($reportResult->getSubListColumnList()) > 0;

        $label = $gr;

        if (empty($label)) {
            $label = $this->language->translateLabel('-Empty-', 'labels', 'Report');
        }
        else if (isset($reportResult->getGroupValueMap()[$groupName][$gr])) {
            $label = $reportResult->getGroupValueMap()[$groupName][$gr];
        }

        if (strpos($groupName , ':')) {
            [$function,] = explode(':', $groupName);

            $label = $this->handleDateGroupValue($function, $label);
        }

        if ($hasSubListColumns && $onlyNumeric) {
            $label = $this->language->translateLabel('Group Total', 'labels', 'Report');
        }

        $row[] = [
            'value' => $label,
            'isBold' => $hasSubListColumns && !$onlyNumeric,
        ];

        foreach ($reportResult->getColumnList() as $column) {
            $isNumericValue = in_array($column, $reportResult->getNumericColumnList());

            if ($hasSubListColumns && !$onlyNumeric && $isNumericValue) {
                $row[] = [];

                continue;
            }

            if ($hasSubListColumns && $onlyNumeric && !$isNumericValue) {
                $row[] = [];

                continue;
            }

            if (
                $isNumericValue &&
                (
                    //isset($reportResult['aggregatedColumnList']) &&
                    !in_array($column, $reportResult->getAggregatedColumnList())
                )
            ) {
                $row[] = [];

                continue;
            }

            if ($isNumericValue) {
                $value = $this->formatColumnValue(
                    $reportData->$gr->$column ?? 0,
                    $columnTypes[$column],
                    $columnDecimalPlacesMap[$column] ?? null
                );
            }else {
                $value = $reportData->$gr->$column ?? '';
            }

            $row[] = [
                'value' => $value,
                'attrs' => [
                    'align' => $isNumericValue ? 'right' : 'left',
                ],
            ];
        }

        return $row;
    }

    /**
     * @param mixed $value
     */
    private function formatColumnValue($value, ?string $type, ?int $decimalPlaces = null): string
    {
        if ((!$type || $type === 'int') && $decimalPlaces !== null) {
            $type = 'float';
        }

        return match ($type) {
            'int' => $this->formatInt($value),
            'float' => $this->formatFloat($value, $decimalPlaces),
            'currency', 'currencyConverted' => $this->formatCurrency($value, null, $decimalPlaces),
            default => (string)$value,
        };

    }

    /**
     * @param array<string, string> $columnTypes
     * @param array<string, int> $columnDecimalPlacesMap
     * @return array{value: mixed, isBold?: bool, attrs?: array<string, mixed>}[][]
     */
    private function buildEmailGrid1SubListRowList(
        string $gr,
        GridResult $reportResult,
        array $columnTypes,
        array $columnDecimalPlacesMap
    ): array {

        $itemList = $reportResult->getSubListData()->$gr;

        $rowList = [];

        foreach ($itemList as $item) {
            $rowList[] = $this->buildEmailGrid1SubListRow(
                $item,
                $reportResult,
                $columnTypes,
                $columnDecimalPlacesMap
            );
        }

        return $rowList;
    }

    /**
     * @param array<string, string> $columnTypes
     * @param array<string, int> $columnDecimalPlacesMap
     * @return array{value: mixed, isBold?: bool, attrs?: array<string, mixed>}[]
     */
    private function buildEmailGrid1SubListRow(
        object $item,
        GridResult $reportResult,
        array $columnTypes,
        array $columnDecimalPlacesMap
    ): array {

        $row = [];

        $row[] = [
            'value' => '',
        ];

        foreach ($reportResult->getColumnList() as $column) {
            if (!in_array($column, $reportResult->getSubListColumnList())) {
                $row[] = [
                    'value' => '',
                ];

                continue;
            }

            $isNumericValue = in_array($column, $reportResult->getNumericColumnList());

            if ($isNumericValue) {
                $value = $this->formatColumnValue(
                    $item->$column ?? 0,
                    $columnTypes[$column],
                    $columnDecimalPlacesMap[$column] ?? null
                );
            }
            else {
                $value = $item->$column ?? '';
            }

            $row[] = [
                'value' => $value,
                'attrs' => ['align' => $isNumericValue ? 'right' : 'left'],
            ];
        }

        return $row;
    }

    /**
     * @param stdClass $data
     * @throws Error
     */
    private function buildEmailGrid2Data($data, GridResult $reportResult, Report $report): void
    {
        $reportData = $reportResult->getReportData();

        /** @var array<string, int> $columnDecimalPlacesMap */
        $columnDecimalPlacesMap = get_object_vars($reportResult->getColumnDecimalPlacesMap());

        $allowedTypeList = [
            'int',
            'float',
            'currency',
            'currencyConverted',
        ];

        $specificColumn = $data->specificColumn ?? null;

        $grids = [];

        foreach ($reportResult->getSummaryColumnList() as $column) {
            $groupName1 = $reportResult->getGroupByList()[0];
            $groupName2 = $reportResult->getGroupByList()[1];

            if ($specificColumn && $specificColumn !== $column) {
                continue;
            }

            $group1NonSummaryColumnList = $reportResult->getGroup1NonSummaryColumnList() ?? [];
            $group2NonSummaryColumnList = $reportResult->getGroup2NonSummaryColumnList() ?? [];

            $columnType = 'int';

            if (strpos($column , ':')) {
                [$function, $field] = explode(':', $column);

                if ($function !== 'COUNT') {
                    $columnType = $this->metadata
                        ->get(['entityDefs', $report->getTargetEntityType(), 'fields', $field, 'type']);
                }
            }

            if ($columnType === 'float') {
                $view = $this->metadata
                    ->get(['entityDefs', $report->getTargetEntityType(), 'fields', $field ?? $column, 'view']);

                if ($view && strpos($view, 'currency-converted')) {
                    $columnType = 'currencyConverted';
                }
            }

            $columnTypes[$column] = (in_array($columnType, $allowedTypeList)) ? $columnType : 'int';

            $grid = [];
            $row = [];
            $row[] = [];

            foreach ($group2NonSummaryColumnList as $c) {
                $text = $reportResult->getColumnNameMap()[$c];

                $row[] = ['value' => $text];
            }

            foreach ($reportResult->getGrouping()[0] ?? [] as $gr1) {
                $label = $gr1;

                if (empty($label)) {
                    $label = $this->language->translateLabel('-Empty-', 'labels', 'Report');
                }
                else if (!empty($reportResult->getGroupValueMap()[$groupName1][$gr1])) {
                    $label = $reportResult->getGroupValueMap()[$groupName1][$gr1];
                }

                if (strpos($groupName1 , ':')) {
                    [$function, $field] = explode(':', $groupName1);

                    $label = $this->handleDateGroupValue($function, $label);
                }

                $row[] = ['value' => $label];
            }

            if ($reportResult->getGroup2Sums() !== null) {
                $row[] = [
                    'value' => $this->language->translateLabel('Total', 'labels', 'Report'),
                    'isBold' => true,
                ];
            }

            $grid[] = $row;

            foreach ($reportResult->getGrouping()[1] ?? [] as $gr2) {
                $row = [];
                $label = $gr2;

                if (empty($label)) {
                    $label = $this->language->translateLabel('-Empty-', 'labels', 'Report');
                } else if (isset($reportResult->getGroupValueMap()[$groupName2][$gr2])) {
                    $label = $reportResult->getGroupValueMap()[$groupName2][$gr2];
                }

                if (strpos($groupName2 , ':')) {
                    [$function, $field] = explode(':', $groupName2);

                    $label = $this->handleDateGroupValue($function, $label);
                }

                $row[] = [
                    'value' => $label,
                    'isBold' => true,
                ];

                foreach ($group2NonSummaryColumnList as $c) {
                    $value = $this->gridExportService->getCellDisplayValueFromResult(1, $gr2, $c, $reportResult);

                    $cData = $this->gridHelper->getDataFromColumnName(
                        $reportResult->getEntityType(),
                        $c,
                        $reportResult
                    );

                    $align = 'left';


                    switch ($cData->fieldType) {
                        case 'int':
                            $value = $this->formatInt($value);
                            $align = 'right';

                            break;

                        case 'float':
                            $decimalPlaces = $columnDecimalPlacesMap[$column] ?? null;

                            $value = $this->formatFloat($value, $decimalPlaces);
                            $align = 'right';

                            break;

                        case 'currency':
                        case 'currencyConverted':
                            $decimalPlaces = $columnDecimalPlacesMap[$column] ?? null;

                            $value = $this->formatCurrency($value, null, $decimalPlaces);
                            $align = 'right';

                            break;
                    }

                    $row[] = [
                        'value' => $value,
                        'attrs' => ['align' => $align],
                    ];
                }

                foreach ($reportResult->getGrouping()[0] ?? [] as $gr1) {
                    $value = 0;

                    if (isset($reportData->$gr1->$gr2->$column)) {
                        $value = $reportData->$gr1->$gr2->$column;

                        $value = $this->formatColumnValue(
                            $value,
                            $columnTypes[$column],
                            $columnDecimalPlacesMap[$column] ?? null
                        );
                    }

                    $row[] = [
                        'value' => $value,
                        'attrs' => ['align' => 'right'],
                    ];
                }

                if ($reportResult->getGroup2Sums() !== null) {
                    $value = $reportResult->getGroup2Sums()->$gr2->$column;

                    $value = $this->formatColumnValue(
                        $value ?? 0,
                        $columnTypes[$column],
                        $columnDecimalPlacesMap[$column] ?? null
                    );

                    $row[] = [
                        'value' => $value,
                        'attrs' => ['align' => 'right'],
                        'isBold' => true,
                    ];
                }

                $grid[] = $row;
            }

            $row = [];

            $row[] = [
                'value' => $this->language->translateLabel('Total', 'labels', 'Report'),
                'isBold' => true,
            ];

            foreach ($group2NonSummaryColumnList as $ignored) {
                $row[] = ['value' => ''];
            }

            foreach ($reportResult->getGrouping()[0] ?? [] as $gr1) {
                $sum = 0;

                if (!empty($reportResult->getGroup1Sums()->$gr1)) {
                    if (!empty($reportResult->getGroup1Sums()->$gr1->$column)) {
                        $sum = $reportResult->getGroup1Sums()->$gr1->$column;

                        $sum = $this->formatColumnValue(
                            $sum ,
                            $columnTypes[$column],
                            $columnDecimalPlacesMap[$column] ?? null
                        );
                    }
                }

                $row[] = [
                    'value' => $sum,
                    'isBold' => true,
                    'attrs' => ['align' => 'right'],
                ];
            }

            if ($reportResult->getGroup2Sums() !== null) {
                $value = $reportResult->getSums()->$column;

                $value = $this->formatColumnValue(
                    $value ?? 0,
                    $columnTypes[$column],
                    $columnDecimalPlacesMap[$column] ?? null
                );

                $row[] = [
                    'value' => $value,
                    'attrs' => ['align' => 'right'],
                    'isBold' => true,
                ];
            }

            $grid[] = $row;

            if (count($group1NonSummaryColumnList)) {
                $row = [];

                foreach ($group2NonSummaryColumnList as $ignored1) {
                    $row[] = [];
                }

                foreach ($reportResult->getGrouping()[0] ?? [] as $ignored2) {
                    $row[] = [];
                }

                $grid[] = $row;
            }

            foreach ($group1NonSummaryColumnList as $c) {
                $row = [];
                $text = $reportResult->getColumnNameMap()[$c];
                $row[] = ['value' => $text];

                foreach ($group2NonSummaryColumnList as $ignored3) {
                    $row[] = [];
                }

                foreach ($reportResult->getGrouping()[0] ?? [] as $gr1) {
                    $value = $this->gridExportService->getCellDisplayValueFromResult(0, $gr1, $c, $reportResult);

                    $cData = $this->gridHelper->getDataFromColumnName(
                        $reportResult->getEntityType(),
                        $c,
                        $reportResult
                    );

                    $align = 'left';

                    switch ($cData->fieldType) {
                        case 'int':
                            $value = $this->formatInt($value);
                            $align = 'right';

                            break;

                        case 'float':
                            $decimalPlaces = $columnDecimalPlacesMap[$column] ?? null;

                            $value = $this->formatFloat($value, $decimalPlaces);
                            $align = 'right';

                            break;

                        case 'currency':
                        case 'currencyConverted':
                            $decimalPlaces = $columnDecimalPlacesMap[$column] ?? null;

                            $value = $this->formatCurrency($value, null, $decimalPlaces);
                            $align = 'right';

                            break;
                    }

                    $row[] = [
                        'value' => $value,
                        'attrs' => ['align' => $align],
                    ];
                }

                $grid[] = $row;
            }

            $rows = $grid;

            $grids[] = [
                'rowList' => $rows,
                'header' => $reportResult->getColumnNameMap()[$column],
            ];
        }

        $bodyData = ['gridList' => $grids];

        try {
            $subject = $this->renderReport($report, 'reportSendingGrid2', 'subject');
            $body = $this->renderReport($report, 'reportSendingGrid2', 'body', $bodyData);
        }
        catch (Exception $e) {
            $this->log->error($e->getMessage());

            throw Error::createWithBody(
                'emailTemplateParsingError',
                Error\Body::create()
                    ->withMessageTranslation('emailTemplateParsingError', 'Report',
                        ['template' => 'reportSendingGrid2'])
                    ->encode()
            );
        }

        $data->emailSubject = $subject;
        $data->emailBody = $body;

        if (count($grids)) {
            $data->tableData = $grids[0]['rowList'];
        } else {
            $data->tableData = [];
        }
    }

    public function sendEmail(
        string $userId,
        string $emailSubject,
        string $emailBody,
        ?string $attachmentId
    ): void {

        if (!$emailSubject || !$emailBody) {
            throw new RuntimeException('Not enough data for sending report email.');
        }

        $user = $this->entityManager->getEntity('User', $userId);

        if (!$user) {
            throw new RuntimeException('Report Sending Builder[sendEmail]: No user with id ' . $userId);
        }

        $emailAddress = $user->get('emailAddress');

        if (!$emailAddress) {
            throw new RuntimeException('Report Sending Builder[sendEmail]: User has no email address');
        }

        /** @var Email $email */
        $email = $this->entityManager->getNewEntity(Email::ENTITY_TYPE);

        $email->set([
            'to' => $emailAddress,
            'subject' => $emailSubject,
            'body' => $emailBody,
            'isHtml' => true,
        ]);

        $attachmentList = [];

        if ($attachmentId) {
            $attachment = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

            if ($attachment) {
                $attachmentList[] = $attachment;
            }
        }

        try {
            $this->emailSender
                ->create()
                ->withAttachments($attachmentList)
                ->send($email);
        }
        catch (Exception $e) {
            if (isset($attachment)) {
                $this->entityManager->removeEntity($attachment);
            }

            throw new RuntimeException("Report Email Sending:" . $e->getMessage());
        }

        if (isset($attachment)) {
            $this->entityManager->removeEntity($attachment);
        }
    }

    /**
     * @param string|numeric $value
     */
    private function formatCurrency($value, ?string $currency = null, ?int $decimalPlaces = null): string
    {
        if ($value === "") {
            return $value;
        }

        $userThousandSeparator = $this->getPreference('thousandSeparator');
        $userDecimalMark = $this->getPreference('decimalMark');

        // @todo Revise.
        $currencyFormat = (int) $this->config->get('currencyFormat');

        if (!$currency) {
            $currency = $this->config->get('defaultCurrency');
        }

        if ($currencyFormat) {
            $pad = $decimalPlaces ?? ((int) $this->config->get('currencyDecimalPlaces'));

            $value = number_format(floatval($value), $pad, $userDecimalMark, $userThousandSeparator);
        } else {
            $value = $this->formatFloat($value, $decimalPlaces);
        }

        switch ($currencyFormat) {
            case 1:
                $value = $value . ' ' . $currency;

                break;

            case 2:
                $currencySign = $this->metadata->get(['app', 'currency', 'symbolMap', $currency]);

                $value = $currencySign . $value;

                break;

            case 3:
                $currencySign = $this->metadata->get(['app', 'currency', 'symbolMap', $currency]);

                $value = $value . ' ' . $currencySign;

                break;
        }

        return $value;
    }

    /**
     * @param string|int $value
     */
    private function formatInt($value): string
    {
        if ($value === '') {
            return $value;
        }

        $userThousandSeparator = $this->getPreference('thousandSeparator');
        $userDecimalMark = $this->getPreference('decimalMark');

        return number_format(floatval($value), 0, $userDecimalMark, $userThousandSeparator);
    }

    /**
     * @param string|float|int $value
     */
    private function formatFloat($value, ?int $decimalPlaces = null): string
    {
        if ($value === '') {
            return $value;
        }

        if ($value === 0 || $value === 0.0) {
            return '0';
        }

        $userThousandSeparator = $this->getPreference('thousandSeparator');
        $userDecimalMark = $this->getPreference('decimalMark');

        $valueString = number_format(floatval($value), $decimalPlaces ?? 8, $userDecimalMark, $userThousandSeparator);

        if ($decimalPlaces !== null) {
            return $valueString;
        }

        return rtrim(rtrim($valueString, '0'), $userDecimalMark);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderReport(
        Report $entity,
        string $templateName,
        string $type,
        array $data = []
    ): string {

        $template = $this->templateFileManager->getTemplate($templateName, $type, null, 'Advanced');
        $template = str_replace(["\n", "\r"], '', $template);

        return $this->templateRendererFactory
            ->create()
            ->setTemplate($template)
            ->setEntity($entity)
            ->setSkipInlineAttachmentHandling()
            ->setData($data)
            ->render();
    }

    private function handleDateGroupValue(string $function, string $value): string
    {
        if ($function === 'MONTH') {
            [$year, $month] = explode('-', $value);

            $monthNamesShort = $this->language->get('Global.lists.monthNamesShort');
            $monthLabel = $monthNamesShort[intval($month) - 1];

            return $monthLabel . ' ' . $year;
        }

        if ($function === 'DAY') {
            return $this->dateTime->convertSystemDate($value);
        }

        if ($function === 'QUARTER') {
            return $value;
        }

        if ($function === 'YEAR_FISCAL') {
            return $value . '-' . ((string) ((int) $value + 1));
        }

        return $value;
    }
}
