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

namespace Espo\Modules\Advanced\Core\Report;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Advanced\Tools\Report\GridType\Result;

use PhpOffice\PhpSpreadsheet\Chart\Properties;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\Axis;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Chart;

use DateTime;
use DateTimeZone;
use LogicException;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Exception;
use RuntimeException;

class ExportXlsx
{
    /** @var string[] */
    private array $badCharList = [
        '*',
        ':',
        '/',
        '\\',
        '?',
        '[',
        ']',
    ];

    private const CHART_BAR_VERTICAL = 'BarVertical';
    private const CHART_BAR_HORIZONTAL = 'BarHorizontal';
    private const CHART_BAR_GROUPED_VERTICAL = 'BarGroupedVertical';
    private const CHART_BAR_GROUPED_HORIZONTAL = 'BarGroupedHorizontal';
    private const CHART_LINE = 'Line';
    private const CHART_PIE = 'Pie';
    private const CHART_RADAR = 'Radar';

    public function __construct(
        private Config $config,
        private Metadata $metadata,
        private DateTimeUtil $dateTime,
        private Language $language,
        private FileManager $fileManager
    ) {}

    /**
     * @param array{
     *     reportResult: Result,
     *     groupByList: string[],
     *     columnList: string[],
     *     columnTypes: array<string, string>,
     *     columnLabels: array<string, string>,
     *     exportName: ?string,
     *     chartType?: ?string,
     *     groupLabel: ?string,
     * } $params
     * @param array<int, (string[]|int)[]> $result
     * @throws Exception
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function process(string $entityType, array $params, array $result): string
    {
        $reportResult = $params['reportResult'] ?? null;

        if (!$reportResult instanceof Result) {
            throw new LogicException("No report result passed.");
        }

        $phpExcel = new Spreadsheet();

        $exportName = $params['exportName'] ?? $this->language->translateLabel($entityType, 'scopeNamesPlural');

        $groupCount = count($params['groupByList']);
        $is2d = $groupCount === 2;

        foreach ($result as $sheetIndex => $dataList) {
            $currentColumn = null;

            if ($is2d) {
                $currentColumn = $reportResult->getSummaryColumnList()[$sheetIndex];
                /** @var string $sheetName */
                $sheetName = $params['columnLabels'][$currentColumn] ?? '';
            } else {
                $sheetName = $exportName;
            }

            $totalFunction = null;

            $sheetName = str_replace($this->badCharList, ' ', $sheetName);
            $sheetName = str_replace('\'', '', $sheetName);
            $sheetName = mb_substr($sheetName, 0, 30, 'utf-8');

            if ($sheetIndex > 0) {
                $sheet = $phpExcel->createSheet();
                $sheet->setTitle($sheetName);
                $sheet = $phpExcel->setActiveSheetIndex($sheetIndex);
            } else {
                $sheet = $phpExcel->setActiveSheetIndex($sheetIndex);
                $sheet->setTitle($sheetName);
            }

            $titleStyle = [
                'font' => [
                   'bold' => true,
                   'size' => 12
                ],
            ];

            $dateStyle = [
                'font'  => [
                   'size' => 12
                ],
            ];

            $now = new DateTime();
            $now->setTimezone(new DateTimeZone($this->config->get('timeZone', 'UTC')));

            $sheet->setCellValue('A1', $this->sanitizeCell($exportName));
            $sheet->setCellValue('B1', Date::PHPToExcel(strtotime($now->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT))));

            if ($currentColumn) {
                $sheet->setCellValue('A2', $params['columnLabels'][$currentColumn]);
                $sheet->getStyle('A2')->applyFromArray($titleStyle);
            }

            $sheet->getStyle('A1')->applyFromArray($titleStyle);
            $sheet->getStyle('B1')->applyFromArray($dateStyle);

            $sheet->getStyle('B1')
                ->getNumberFormat()
                ->setFormatCode($this->dateTime->getDateTimeFormat());

            /*$colCount = 1;

            foreach ($dataList as $i => $row) {
                foreach ($row as $j => $item) {
                    $colCount ++;
                }

                break;
            }*/

            $maxColumnIndex = count($dataList);

            if (
                isset($dataList[0]) &&
                is_countable($dataList[0]) &&
                count($dataList[0]) > $maxColumnIndex
            ) {
                $maxColumnIndex = count($dataList[0]);
            }

            $maxColumnIndex += 3;

            [$azRange, $i, $j] = $this->prepareAzRange($maxColumnIndex);

            $rowNumber = 2;

            if ($currentColumn) {
                $rowNumber++;
            }

            if (!isset($i)) {
                throw new LogicException();
            }

            $col = $azRange[$i];

            $headerStyle = [
                'font' => [
                    'bold'  => true,
                    'size'  => 12,
                ]
            ];

            $sheet->getStyle("A$rowNumber:$col$rowNumber")->applyFromArray($headerStyle);

            $headerRowNumber = $rowNumber + 1;
            $firstRowNumber = $rowNumber + 1;

            $currency = $this->config->get('defaultCurrency');
            $currencySymbol = $this->metadata->get(['app', 'currency', 'symbolMap', $currency], '');

            $lastCol = null;

            /** @noinspection SpellCheckingInspection */
            $borderStyle = [
                'borders' => [
                    'allborders' => ['style' => Border::BORDER_THIN]
                ]
            ];

            if ($is2d) {
                $summaryRowCount = count($reportResult->getGrouping()[1] ?? []);

                $firstSummaryColumn = $azRange[1 + count($reportResult->getGroup2NonSummaryColumnList())];
            } else {
                $summaryRowCount = count($reportResult->getGrouping()[0] ?? []);

                $firstSummaryColumn = 'B';
            }

            $totalRow = null;

            foreach ($dataList as $i => $row) {
                $rowNumber++;

                if ($groupCount && $i - 1 === $summaryRowCount) {
                    $totalRow = $row;
                    $rowNumber--;

                    continue;
                }

                $isNotSummaryRow = $i > $summaryRowCount;

                assert(is_iterable($row));
                assert(is_countable($row));

                if ($currentColumn) {
                    if (count($row) === 0) {
                        continue;
                    }

                    if ($i - 1 === $summaryRowCount) {
                        continue;
                    }
                }

                foreach ($row as $j => $item) {
                    $col = $azRange[$j];

                    if ($j === count($row) - 1) {
                        $lastCol = $col;
                    }

                    if ($i === 0) {
                        $sheet->getColumnDimension($col)->setAutoSize(true);

                        if ($j === 0) {
                            $lastCol = $azRange[count($row) - 1];
                            $lastRowNumber = $firstRowNumber + count($dataList) - 2;

                            $sheet->setAutoFilter("A$rowNumber:$lastCol$lastRowNumber");

                            if (!empty($params['groupLabel'])) {
                                $sheet->setCellValue("$col$rowNumber", $this->sanitizeCell($params['groupLabel']));
                            }

                            continue;
                        }

                        if ($currentColumn) {
                            $gr = $params['groupByList'][0];

                            [$f2] = explode(':', $gr);

                            if ($f2 && $j > count($reportResult->getGroup2NonSummaryColumnList())) {
                                $item = $this->handleGroupValue($f2, $item);
                                $formatCode = $this->getGroupCellFormatCodeForFunction($f2);

                                $sheet->setCellValue("$col$rowNumber", $this->sanitizeCell($item));

                                if ($formatCode) {
                                    $sheet->getStyle("$col$rowNumber")
                                        ->getNumberFormat()
                                        ->setFormatCode($formatCode);

                                    $sheet->getStyle("$col$rowNumber")
                                        ->getAlignment()
                                        ->setHorizontal(Alignment::HORIZONTAL_LEFT);
                                }
                            }
                        }
                    }

                    $sheet->setCellValue("$col$rowNumber", $this->sanitizeCell($item));

                    $column = null;

                    if ($currentColumn) {
                        $column = $currentColumn;
                    } else if ($j) {
                        $column = $params['columnList'][$j - 1];
                    }

                    if ($j === 0) {
                        if ($currentColumn) {
                            $gr = $params['groupByList'][1];
                        } else if ($groupCount) {
                            $gr = $params['groupByList'][0];
                        } else {
                            $gr = '__STUB__';
                        }

                        [$f1] = explode(':', $gr);

                        if ($f1 && !$isNotSummaryRow) {
                            $item = $this->handleGroupValue($f1, $item);
                            $formatCode = $this->getGroupCellFormatCodeForFunction($f1);

                            $sheet->setCellValue("$col$rowNumber", $this->sanitizeCell($item));

                            if ($formatCode) {
                                $sheet->getStyle("$col$rowNumber")
                                    ->getNumberFormat()
                                    ->setFormatCode($formatCode);

                                $sheet->getStyle("$col$rowNumber")->getAlignment()
                                    ->setHorizontal(Alignment::HORIZONTAL_LEFT);
                            }
                        }
                    }

                    /** @var int $i */
                    /** @var int $j */

                    $cellColumn = $column;

                    if ($currentColumn) {
                        $cellColumn = null;

                        if ($i - 1 < $summaryRowCount) {
                            if ($j) {
                                if ($j > count($reportResult->getGroup2NonSummaryColumnList())) {
                                    $cellColumn = $column;
                                } else if (count($reportResult->getGroup2NonSummaryColumnList())) {
                                    $cellColumn = $reportResult->getGroup2NonSummaryColumnList()[$j - 1];
                                }
                            }
                        } else if (count($reportResult->getGroup1NonSummaryColumnList())) {
                            $cellColumn =
                                $reportResult->getGroup1NonSummaryColumnList()[$i - $summaryRowCount - 3];
                        }
                    }

                    $cellIsAppropriate = $j && $i && $cellColumn;

                    if ($cellIsAppropriate) {
                        $this->formatNumberCell($params, $cellColumn, $sheet, $col, $rowNumber, $currencySymbol);
                    }
                }

                if ($i === 0) {
                    $sheet->getStyle("A$rowNumber:$col$rowNumber")->applyFromArray($headerStyle);
                }

                /** @var int $i */
                /** @var int $j */

                if ($i && $lastCol && $currentColumn && $i < count($dataList)) {
                    $skipRowTotal = false;

                    if ($i - 1 > $summaryRowCount) {
                        $skipRowTotal = true;
                    }

                    if (!isset($j)) {
                        throw new LogicException();
                    }

                    $rightTotalCol = $azRange[$j + 2];

                    if ($i === 1) {
                        $sheet->getStyle($rightTotalCol . $headerRowNumber)->applyFromArray($headerStyle);
                        $sheet->setCellValue(
                            $rightTotalCol . $headerRowNumber,
                            $this->language->translateLabel('Total', 'labels', 'Report')
                        );
                    }

                    if (!$skipRowTotal) {
                        if ($totalFunction) {
                            $function = $totalFunction;
                        } else {
                            [$function] = explode(':', $currentColumn);

                            if ($function === 'COUNT') {
                                $function = 'SUM';
                            } else if ($function === 'AVG') {
                                $function = 'AVERAGE';
                            } else if (!in_array($function, ['SUM', 'MIN', 'MAX'])) {
                                $function = 'SUM';
                            }

                            $totalFunction = $function;
                        }

                        $value = "=$function($firstSummaryColumn$rowNumber:$lastCol$rowNumber)";

                        $totalCell = $rightTotalCol . $rowNumber;

                        $sheet->getColumnDimension($rightTotalCol)->setAutoSize(true);
                        $sheet->setCellValue($totalCell, $value);

                        $this->formatNumberCell(
                            $params,
                            $currentColumn,
                            $sheet,
                            $rightTotalCol,
                            $rowNumber,
                            $currencySymbol,
                            $function
                        );
                    }
                }
            }

            if ($groupCount && $lastCol && $totalRow) {
                $rowNumber++;
                $row = $totalRow;

                assert(is_iterable($row));

                foreach ($row as $j => $item) {
                    if ($j === 0) {
                        continue;
                    }

                    if ($item !== 0 && empty($item)) {
                        continue;
                    }

                    $col = $azRange[$j];

                    if ($currentColumn) {
                        $column = $currentColumn;
                    }
                    else {
                        $column = $params['columnList'][$j - 1];
                    }

                    if (!in_array($column, $reportResult->getNumericColumnList())) {
                        continue;
                    }

                    if (strpos($column, ':')) {
                        [$function] = explode(':', $column);

                        if ($function === 'COUNT') {
                            $function = 'SUM';
                        } else if ($function === 'AVG') {
                            $function = 'AVERAGE';
                        } else if (!in_array($function, ['SUM', 'MIN', 'MAX'])) {
                            $function = 'SUM';
                        }
                    }
                    else {
                        $function = 'SUM';
                    }

                    $value = '='. $function . "(".$col.($firstRowNumber + 1) . ":" .
                        $col . ($firstRowNumber + $summaryRowCount).")";

                    $sheet->setCellValue($col . "" . ($rowNumber + 1), $value);

                    $this->formatNumberCell(
                        $params,
                        $column,
                        $sheet,
                        $col,
                        $rowNumber + 1,
                        $currencySymbol,
                        $function
                    );
                }

                $sheet->getStyle("A".($rowNumber + 1))->applyFromArray($headerStyle);

                $sheet->setCellValue(
                    "A".($rowNumber + 1),
                    $this->language->translateLabel('Total', 'labels', 'Report')
                );
            }

            if ($lastCol) {
                $borderRange = "A$firstRowNumber:$lastCol" . ($rowNumber + 1);



                if ($currentColumn && isset($rightTotalCol)) {
                    $borderRange = "A$firstRowNumber:$rightTotalCol" . ($rowNumber + 1);

                    if ($totalFunction) {
                        $superTotalCell = $rightTotalCol . ($rowNumber + 1);

                        $superTotalValue = '=' .
                            $totalFunction . "(". $firstSummaryColumn . ($rowNumber + 1) .
                            ":" . $lastCol . ($rowNumber + 1) . ")";

                        $sheet->setCellValue($superTotalCell, $superTotalValue);

                        if (isset($column)) {
                            $this->formatNumberCell(
                                $params,
                                $column,
                                $sheet,
                                $rightTotalCol,
                                $rowNumber + 1,
                                $currencySymbol,
                                $function ?? null
                            );
                        }
                    }
                }

                $sheet->getStyle($borderRange)->applyFromArray($borderStyle);

                $chartStartRow = $rowNumber + 3;

                if (!$groupCount) {
                    $dataLastRowNumber = $rowNumber;
                }
                else {
                    $dataLastRowNumber = $firstRowNumber + $summaryRowCount;
                }

                if (!empty($params['chartType'])) {
                    /** @var string[][] $dataList */

                    if (!$currentColumn) {
                        $columnGroupList = $this->getColumnGroupList($params, $reportResult);

                        foreach ($columnGroupList as $columnIndexList) {
                            $this->drawChart1(
                                $params,
                                $dataList,
                                $sheet,
                                $sheetName,
                                $azRange,
                                $firstRowNumber,
                                $dataLastRowNumber,
                                $chartStartRow,
                                $columnIndexList
                            );
                        }
                    } else {
                        /** @var string[][] $dataList */

                        $this->drawChart2(
                            $params,
                            $dataList,
                            $sheet,
                            $sheetName,
                            $firstRowNumber,
                            $dataLastRowNumber,
                            $lastCol,
                            $chartStartRow,
                            1 + count($reportResult->getGroup2NonSummaryColumnList()),
                            $firstSummaryColumn
                        );
                    }
                }
            }
        }

        $objWriter = IOFactory::createWriter($phpExcel, 'Xlsx');

        $objWriter->setIncludeCharts(true);
        $objWriter->setPreCalculateFormulas(true);

        if (!$this->fileManager->isDir('data/cache/')) {
            $this->fileManager->mkdir('data/cache/');
        }

        $tempFileName = 'data/cache/' . 'export_' . substr(md5(strval(rand())), 0, 7);

        $objWriter->save($tempFileName);

        $fp = fopen($tempFileName, 'r');

        if ($fp === false) {
            throw new RuntimeException("Could not open temp file.");
        }

        $xlsx = stream_get_contents($fp);

        if ($xlsx === false) {
            throw new RuntimeException("Could not get contents from temp file.");
        }

        $this->fileManager->unlink($tempFileName);

        return $xlsx;
    }

    /**
     * @param array<string, mixed> $params
     * @param Result $reportResult
     * @return int[][]
     */
    private function getColumnGroupList($params, Result $reportResult)
    {
        $list = [];

        $countGroup = [];
        $sumCurrencyGroup = [];
        $currencyGroup = [];

        $columnList = $reportResult->getAggregatedColumnList();

        if ($params['chartType'] == self::CHART_PIE) {
            foreach ($columnList as $j => $column) {
                $list[] = [$j];
            }

            return $list;
        }

        foreach ($columnList as $j => $column) {
            if (!in_array($column, $reportResult->getNumericColumnList())) {
                continue;
            }

            if (str_starts_with($column, 'COUNT:')) {
                $countGroup[] = $j;

                continue;
            }

            if (
                (
                    str_starts_with($column, 'SUM:') ||
                    !str_contains($column, ':') && str_contains($column, '.')
                ) &&
                $params['columnTypes'][$column] == 'currencyConverted'
            ) {
                $sumCurrencyGroup[] = $j;

                continue;
            }

            if ($params['columnTypes'][$column] == 'currencyConverted') {
                $currencyGroup[] = $j;

                continue;
            }

            $list[] = [$j];
        }

        if (count($currencyGroup)) {
            array_unshift($list, $currencyGroup);
        }

        if (count($countGroup)) {
            array_unshift($list, $countGroup);
        }

        if (count($sumCurrencyGroup)) {
            array_unshift($list, $sumCurrencyGroup);
        }

        return $list;
    }

    /**
     * @param array{chartType: string, groupByList: string[]} $params
     * @param string[][] $dataList
     * @param string[] $azRange
     * @param int[] $columnIndexList
     */
    private function drawChart1(
        array $params,
        array $dataList,
        Worksheet $sheet,
        string $sheetName,
        $azRange,
        int $firstRowNumber,
        int $dataLastRowNumber,
        int &$chartStartRow,
        $columnIndexList
    ): void {

        $chartType = $params['chartType'];
        $groupCount = count($params['groupByList']);

        if ($groupCount === 0 && count($columnIndexList) === 1) {
            return;
        }

        $titleString = null;
        $labelSeries = [];
        $valueSeries = [];
        $dataValues = [];

        foreach ($dataList as $k => $row) {
            if ($k === 0) {
                continue;
            }

            if ($k === count($dataList) - 1) {
                continue;
            }

            $dataValues[] = $row[0];
        }

        if ($groupCount) {
            [$f1] = explode(':', $params['groupByList'][0]);

            foreach ($dataValues as $k => $item) {
                if ($f1) {
                    $item = $this->handleGroupValueForChart($f1, $item);
                    $dataValues[$k] = $item;
                }
            }
        }

        foreach ($columnIndexList as $j) {
            $i = $j + 1;

            $col = $azRange[$i];
            $titleString = $dataList[0][$i];

            $labelSeries[] = new DataSeriesValues(
                'String',
                sprintf("'%s'!\$%s\$%s", $sheetName, $col, $firstRowNumber),
                null,
                1
            );

            $valueSeries[] = new DataSeriesValues(
                'Number',
                sprintf("'%s'!\$%s\$%d:\$%s\$%s", $sheetName, $col, $firstRowNumber + 1, $col, $dataLastRowNumber),
                null,
                count($dataValues)
            );
        }

        $chartHeight = 18;

        $title = new Title($titleString);

        $legendPosition = null;
        $excelChartType = DataSeries::TYPE_BARCHART;

        if ($chartType === self::CHART_LINE) {
            $excelChartType = DataSeries::TYPE_LINECHART;
        }
        else if ($chartType === self::CHART_PIE) {
            $excelChartType = DataSeries::TYPE_PIECHART;
            $legendPosition = Legend::POSITION_RIGHT;
        }
        else if ($chartType === self::CHART_RADAR) {
            $excelChartType = DataSeries::TYPE_RADARCHART;
            $legendPosition = Legend::POSITION_BOTTOM;
            $title = null;
        }

        if ($chartType !== self::CHART_PIE && count($columnIndexList) > 1) {
            $legendPosition = Legend::POSITION_BOTTOM;
            $title = null;
        }

        $categorySeries = [
            new DataSeriesValues(
                'String',
                sprintf("'%s'!\$A\$%d:\$A\$%d", $sheetName, $firstRowNumber + 1, $dataLastRowNumber),
                null,
                count($dataValues)
            )
        ];

        $legend = null;

        if ($legendPosition) {
            $legend = new Legend($legendPosition, null, false);
        }

        $dataSeries = new DataSeries(
            $excelChartType,
            DataSeries::GROUPING_STANDARD,
            range(0, count($valueSeries) - 1),
            $labelSeries,
            $categorySeries,
            $valueSeries
        );

        if ($chartType === self::CHART_BAR_HORIZONTAL) {
            $chartHeight = count($dataList) + 10;
            $dataSeries->setPlotDirection(DataSeries::DIRECTION_BAR);
        }
        else if ($chartType === self::CHART_BAR_VERTICAL) {
            $dataSeries->setPlotDirection(DataSeries::DIRECTION_COL);
        }

        $chartEndRow = $chartStartRow + $chartHeight;

        $yAxis = null;

        if ($chartType === self::CHART_BAR_HORIZONTAL) {
            $yAxis = new Axis();
            $yAxis->setAxisOrientation(Properties::ORIENTATION_REVERSED);
        }

        $plotArea = new PlotArea(null, [$dataSeries]);

        $chart = new Chart(
            'chart1',
            $title,
            $legend,
            $plotArea,
            true,
            'gap',
            null,
            null,
            null,
            $yAxis
        );

        $chart->setTopLeftPosition('A' . $chartStartRow);
        $chart->setBottomRightPosition('E' . $chartEndRow);

        $sheet->addChart($chart);

        $chartStartRow = $chartEndRow + 2;
    }

    /**
     * @param array{chartType: string, groupByList: string[]} $params
     * @param string[][] $dataList
     */
    private function drawChart2(
        array $params,
        array $dataList,
        Worksheet $sheet,
        string $sheetName,
        int $firstRowNumber,
        int $dataLastRowNumber,
        string $lastCol,
        int $chartStartRow,
        int $firstSummaryColumnIndex,
        string $firstSummaryColumn
    ): void {

        $chartType = $params['chartType'];

        $chartHeight = count($dataList) + 10;

        $legendPosition = Legend::POSITION_BOTTOM;

        $labelSeries = [];
        $valueSeries = [];
        $dataValues = [];

        foreach ($dataList[0] as $k => $item) {
            if ($k === 0) {
                continue;
            }

            if ($k < $firstSummaryColumnIndex) {
                continue;
            }

            $dataValues[] = $item;
        }

        [$f1] = explode(':', $params['groupByList'][0]);

        foreach ($dataValues as $k => $item) {
            if ($f1) {
                $item = $this->handleGroupValueForChart($f1, $item);
                $dataValues[$k] = $item;
            }
        }

        if (!count($dataValues)) {
            return;
        }

        for ($i = $firstRowNumber + 1; $i <= $dataLastRowNumber; $i++) {
            $labelSeries[] = new DataSeriesValues(
                'String',
                "'" . $sheetName . "'" . "!" ."\$A" . "\$" .($i),
                null,
                1
            );

            $valueSeries[] = new DataSeriesValues(
                'Number',
                sprintf("'%s'!\$%s\$%d:\$%s\$%d", $sheetName, $firstSummaryColumn, $i, $lastCol, $i),
                null,
                count($dataValues)
            );
        }

        $categorySeries = [
            new DataSeriesValues(
                'String',
                sprintf("'%s'!\$%s\$%d:\$%s\$%d",
                    $sheetName, $firstSummaryColumn, $firstRowNumber, $lastCol, $firstRowNumber),
                null,
                count($dataValues)
            )
        ];

        $legend = new Legend($legendPosition, null, false);

        $excelChartType = DataSeries::TYPE_BARCHART;

        if ($chartType === self::CHART_LINE) {
            $excelChartType = DataSeries::TYPE_LINECHART;
        }
        else if ($chartType === self::CHART_PIE) {
            return;
        }

        $groupingType = DataSeries::GROUPING_STACKED;

        if ($chartType === self::CHART_BAR_GROUPED_VERTICAL || $chartType === self::CHART_BAR_GROUPED_HORIZONTAL) {
            $groupingType = DataSeries::GROUPING_CLUSTERED;
        }

        $dataSeries = new DataSeries(
            $excelChartType,
            $groupingType,
            range(0, count($valueSeries) - 1),
            $labelSeries,
            $categorySeries,
            $valueSeries
        );

        if ($chartType === self::CHART_BAR_HORIZONTAL || $chartType === self::CHART_BAR_GROUPED_HORIZONTAL) {
            //$chartHeight = count($dataList) + 10;

            $dataSeries->setPlotDirection(DataSeries::DIRECTION_BAR);
        }
        else if ($chartType === self::CHART_BAR_VERTICAL || $chartType === self::CHART_BAR_GROUPED_VERTICAL) {
            $dataSeries->setPlotDirection(DataSeries::DIRECTION_COL);
        }

        $chartEndRow = $chartStartRow + $chartHeight;

        $yAxis = null;

        if ($chartType === self::CHART_BAR_HORIZONTAL || $chartType === self::CHART_BAR_GROUPED_HORIZONTAL) {
            $yAxis = new Axis();

            $yAxis->setAxisOrientation(Properties::ORIENTATION_REVERSED);
        }

        $plotArea = new PlotArea(null, [$dataSeries]);

        $chart = new Chart(
            'chart1',
            null,
            $legend,
            $plotArea,
            true,
            'gap',
            null,
            null,
            null,
            $yAxis
        );

        $chart->setTopLeftPosition('A' . $chartStartRow);
        $chart->setBottomRightPosition($lastCol . $chartEndRow);
        $sheet->addChart($chart);
    }


    /**
     * @param ?string $function
     * @param mixed $value
     * @return mixed
     */
    private function handleGroupValueForChart($function, $value)
    {
        if ($function === 'MONTH') {
            [$year, $month] = explode('-', $value);

            $monthNamesShort = $this->language->get('Global.lists.monthNamesShort');
            $monthLabel = $monthNamesShort[intval($month) - 1];
            $value = $monthLabel . ' ' . $year;
        }
        else if ($function === 'DAY') {
            $value = $this->dateTime->convertSystemDate($value);
        }

        return $value;
    }

    /**
     * @param ?string $function
     * @param mixed $value
     * @return mixed
     */
    private function handleGroupValue($function, $value)
    {
        if ($function === 'MONTH') {
            return Date::PHPToExcel(strtotime($value . '-01'));
        }
        else if ($function === 'YEAR') {
            return Date::PHPToExcel(strtotime($value . '-01-01'));
        }
        else if ($function === 'DAY') {
            return Date::PHPToExcel(strtotime($value));
        }

        return $value;
    }

    /**
     * @param ?string $function
     * @return ?string
     */
    private function getGroupCellFormatCodeForFunction($function)
    {
        if ($function === 'MONTH') {
            return 'MMM YYYY';
        } else if ($function === 'YEAR') {
            return 'YYYY';
        } else if ($function === 'DAY') {
            return $this->dateTime->getDateFormat();
        }

        return null;
    }

    private function getCurrencyFormatCode(string $currencySymbol, ?int $decimalPlaces = null): string
    {
        $currencyFormat = $this->config->get('currencyFormat') ?? 2;

        $pad = str_pad('', $decimalPlaces ?? 2, '0');

        if ($currencyFormat == 3) {
            return "#,##0.{$pad}_-\"$currencySymbol\"";
        }

        return "[\$$currencySymbol-409]#,##0.$pad;-[\$$currencySymbol-409]#,##0.$pad";
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeCell($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        if ($value === '') {
            return $value;
        }

        if (in_array($value[0], ['+', '-', '@', '='])) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param array{reportResult: Result, columnTypes: array<string, string>} $params
     */
    private function formatNumberCell(
        array $params,
        string $cellColumn,
        Worksheet $sheet,
        string $col,
        int $rowNumber,
        string $currencySymbol,
        ?string $function = null
    ): void {

        $type = $params['columnTypes'][$cellColumn] ?? null;

        $decimalPlaces = $cellColumn ?
            ($params['reportResult']->getColumnDecimalPlacesMap()->$cellColumn ?? null) :
            null;

        if (!$type) {
            if ($decimalPlaces === null) {
                return;
            }

            $this->formatWithDecimalPlaces($decimalPlaces, $sheet, $col, $rowNumber);
        }

        $type = $params['columnTypes'][$cellColumn];

        if ($type === 'currency' || $type === 'currencyConverted') {
            $sheet->getStyle("$col$rowNumber")
                ->getNumberFormat()
                ->setFormatCode(
                    $this->getCurrencyFormatCode($currencySymbol, $decimalPlaces)
                );

            return;
        }

        if ($type === 'float' || $function === 'AVERAGE') {
            $this->formatWithDecimalPlaces($decimalPlaces ?? 2, $sheet, $col, $rowNumber);

            return;
        }

        if ($type === 'int') {
            $sheet->getStyle("$col$rowNumber")
                ->getNumberFormat()
                ->setFormatCode('#,##0');
        }
    }

    private function formatWithDecimalPlaces(
        int $decimalPlaces,
        Worksheet $sheet,
        string $col,
        int $rowNumber
    ): void {

        if ($decimalPlaces === 0) {
            $sheet->getStyle("$col$rowNumber")
                ->getNumberFormat()
                ->setFormatCode('#,##0');

            return;
        }

        $sheet->getStyle("$col$rowNumber")
            ->getNumberFormat()
            ->setFormatCode('#,##0.' . str_pad('', $decimalPlaces, '0'));
    }

    /**
     * @return array{string[], int, int}
     */
    private function prepareAzRange(int $maxColumnIndex): array
    {
        $azRange = range('A', 'Z');
        $azRangeCopied = $azRange;

        $i = 0;
        $j = 0;

        foreach ($azRangeCopied as $i => $char1) {
            foreach ($azRangeCopied as $j => $char2) {
                $azRange[] = $char1 . $char2;

                if ($i * 26 + $j > $maxColumnIndex) {
                    break 2;
                }
            }
        }

        if (count($azRange) < $maxColumnIndex) {
            foreach ($azRangeCopied as $i => $char1) {
                foreach ($azRangeCopied as $j => $char2) {
                    foreach ($azRangeCopied as $char3) {
                        $azRange[] = $char1 . $char2 . $char3;

                        if (count($azRange) > $maxColumnIndex) {
                            break 3;
                        }
                    }
                }
            }
        }

        return array($azRange, $i, $j);
    }
}
