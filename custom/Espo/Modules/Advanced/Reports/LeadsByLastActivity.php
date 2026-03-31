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

namespace Espo\Modules\Advanced\Reports;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Field\DateTime;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Report\GridType\Result;
use Espo\Modules\Advanced\Tools\Report\ListType\Result as ListResult;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\Modules\Crm\Entities\Call;
use Espo\Modules\Crm\Entities\Lead;
use Espo\Modules\Crm\Entities\Meeting;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition as C;
use Espo\ORM\Query\Part\Expression as E;
use Espo\ORM\Query\Part\Order;
use Espo\ORM\Query\Part\WhereItem as WherePart;
use Espo\ORM\Query\SelectBuilder;
use RuntimeException;

class LeadsByLastActivity implements GridReport
{
    /** @var array<int, ?array{int, ?int}> */
    private array $rangeList = [
        [0, 7],
        [7, 15],
        [15, 30],
        [30, 60],
        [60, 120],
        [120, null],
        null,
    ];

    /** @var string[] */
    private array $ignoreStatusList;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Language $language,
        private SelectBuilderFactory $selectBuilderFactory
    ) {

        $this->ignoreStatusList = $this->metadata
            ->get(['entityDefs', 'Lead', 'fields', 'status', 'notActualOptions']) ?? [];
    }

    private function executeSubReport(
        SearchParams $searchParams,
        SubReportParams $subReportParams,
        ?User $user
    ): ListResult {

        /** @var string $groupValue */
        $groupValue = $subReportParams->getGroupValue();

        $groupIndex = $subReportParams->getGroupIndex();

        $selectBuilder = $this->selectBuilderFactory
            ->create()
            ->from(Lead::ENTITY_TYPE)
            ->withStrictAccessControl()
            ->withSearchParams($searchParams);

        if ($user) {
            $selectBuilder->forUser($user);
        }

        try {
            $queryBuilder = $selectBuilder->buildQueryBuilder();
        } catch (BadRequest|Forbidden $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        if (!$groupIndex) {
            if ($groupValue === '-') {
                $range = null;
            } else {
                $range = explode('-', $groupValue);

                if (empty($range[1])) {
                    $range[1] = null;
                }

                foreach ($range as $i => $it) {
                    if ($it !== null) {
                        $range[$i] = intval($it);
                    }
                }
            }
        }

        if (!$groupIndex) {
            /** @var ?array{?int, ?int} $range */

            $queryBuilder->where(
                $this->getWherePart($range)
            );

            $queryBuilder->where(['status!=' => $this->ignoreStatusList]);

            if ($subReportParams->hasGroupValue2()) {
                $queryBuilder->where(['status' => $subReportParams->getGroupValue2()]);
            }
        } else {
            $queryBuilder->where(['status' => $groupValue]);
        }

        $query = $queryBuilder->build();

        $collection = $this->entityManager
            ->getRDBRepository(Lead::ENTITY_TYPE)
            ->clone($query)
            ->find();

        $count =  $this->entityManager
            ->getRDBRepository(Lead::ENTITY_TYPE)
            ->clone($query)
            ->count();

        return new ListResult($collection, $count);
    }

    public function runSubReport(SearchParams $searchParams, SubReportParams $subReportParams, ?User $user): ListResult
    {

        return $this->executeSubReport($searchParams, $subReportParams, $user);
    }

    public function run(?WhereItem $where, ?User $user): Result
    {
        $reportData = $this->getDataResults();

        $columns = ['COUNT:id'];
        $groupBy = ['RANGE', 'status'];

        $group1Sums = [];

        $grouping = [[], []];

        foreach ($this->rangeList as $i => $range) {
            $grouping[0][] = $this->getStringRange($i);
        }

        foreach ($reportData as $range => $d1) {
            $group1Sums[$range] = [
                'COUNT:id' => 0
            ];

            foreach ($d1 as $d2) {
                $group1Sums[$range]['COUNT:id'] += $d2['COUNT:id'];
            }
        }

        $statusList = $this->metadata->get('entityDefs.Lead.fields.status.options', []);

        foreach ($statusList as $status) {
            if (!in_array($status, $this->ignoreStatusList)) {
                $grouping[1][] = $status;
            }
        }

        $columnNameMap = [
            'COUNT:id' => $this->language->translateLabel('COUNT', 'functions', 'Report')
        ];

        $groupValueMap = [
            'RANGE' => [],
            'status' => [],
        ];

        foreach ($this->rangeList as $i => $r) {
            $groupValueMap['RANGE'][$this->getStringRange($i)] = $this->getRangeTranslation($i);
        }

        foreach ($grouping[1] as $status) {
            /** @var string $status */

            $groupValueMap['status'][$status] = $this->language->translateOption($status, 'status', 'Lead');
        }

        $sums = (object) [];

        $sum = 0;

        foreach ($grouping[0] as $group) {
            if (
                !isset($group1Sums[$group]) ||
                /** @phpstan-ignore-next-line  */
                !isset($group1Sums[$group][$columns[0]])
            ) {
                $group1Sums[$group][$columns[0]] = 0;
            }

            $sum += $group1Sums[$group][$columns[0]];
        }

        $sums->{$columns[0]} = $sum;

        foreach ($reportData as $k => $v) {
            $reportData[$k] = (object) $v;

            foreach ($v as $k1 => $v1) {
                if (is_array($v1)) {
                    $reportData[$k]->$k1 = (object) $v1;
                }
            }
        }

        $reportData = (object) $reportData;

        $result = new Result(
            Lead::ENTITY_TYPE,
            $groupBy,
            $columns,
            $columns,
            $columns,
            [],
            [],
            [],
            null,
            null,
            $sums,
            $groupValueMap,
            $columnNameMap,
            [],
            null,
            $grouping,
            $reportData,
            null,
            null
        );

        $result->setGroup1Sums((object) $group1Sums);
        $result->setGroup1NonSummaryColumnList([]);
        $result->setGroup2NonSummaryColumnList([]);

        return $result;
    }

    /**
     * @param int $i
     * @return string
     */
    private function getStringRange($i): string
    {
        $range = $this->rangeList[$i];

        if (!$range) {
            return '-';
        }

        return $range[0] . '-' .  $range[1];
    }

    /**
     * @param int $i
     */
    private function getRangeTranslation($i): string
    {
        $range = $this->rangeList[$i];

        if ($range === null) {
            return $this->language->translateLabel('never', 'labels', 'Report');
        }

        if (empty($range[1])) {
            return '>' . $range[0] . ' ' . $this->language->translateLabel('days', 'labels', 'Report');
        }

        return $range[0] . '-' . $range[1] . ' ' . $this->language->translateLabel('days', 'labels', 'Report');
    }

    /**
     * @param ?array{?int, ?int} $range
     */
    private function getWherePart(?array $range): WherePart
    {
        $completedStatusList1 = $this->metadata->get(['scopes', 'Call', 'completedStatusList']) ?? [];
        $completedStatusList2 = $this->metadata->get(['scopes', 'Meeting', 'completedStatusList']) ?? [];

        $subQueryBuilder1 = SelectBuilder::create()
            ->from(Call::ENTITY_TYPE, 'event')
            ->join('CallLead', 'm',
                C::and(
                    C::equal(
                        E::column('event.id'),
                        E::column('m.callId')
                    ),
                    C::equal(E::column('m.deleted'), false)
                )
            )
            ->where(
                C::equal(
                    E::column('m.leadId'),
                    E::column('lead.id')
                )
            )
            ->where(['status' => $completedStatusList1])
            ->limit(1);

        $subQueryBuilder2 = SelectBuilder::create()
            ->from(Meeting::ENTITY_TYPE, 'event')
            ->join('LeadMeeting', 'm',
                C::and(
                    C::equal(
                        E::column('event.id'),
                        E::column('m.meetingId')
                    ),
                    C::equal(E::column('m.deleted'), false)
                )
            )
            ->where(
                C::equal(
                    E::column('m.leadId'),
                    E::column('lead.id')
                )
            )
            ->where(['status' => $completedStatusList2])
            ->limit(1);

        $subQueryExists1 = SelectBuilder::create()
            ->clone($subQueryBuilder1->build())
            ->select(E::column('id'))
            ->build();

        $subQueryExists2 = SelectBuilder::create()
            ->clone($subQueryBuilder2->build())
            ->select(E::column('id'))
            ->build();

        if (!$range) {
            return C::and(
                C::not(C::exists($subQueryExists1)),
                C::not(C::exists($subQueryExists2))
            );
        }

        $select = E::max(E::column('dateStart'));

        $subQuery1 = $subQueryBuilder1
            ->select($select)
            ->order($select, Order::DESC)
            ->build();

        $subQuery2 = $subQueryBuilder2
            ->select($select)
            ->order($select, Order::DESC)
            ->build();

        if (!$range[1]) {
            $day = DateTime::createNow()
                ->addDays(- $range[0])
                ->getString();

            return C::or(
                C::and(
                    C::exists($subQueryExists1),
                    C::greaterOrEqual(E::value($day), $subQuery1),
                ),
                C::and(
                    C::exists($subQueryExists2),
                    C::greaterOrEqual(E::value($day), $subQuery2),
                )
            );
        }

        $day1 = DateTime::createNow()
            ->addDays(- $range[0])
            ->getString();

        $day2 = DateTime::createNow()
            ->addDays(- $range[1])
            ->getString();

        return C::or(
            C::and(
                C::exists($subQueryExists1),
                C::lessOrEqual(E::value($day2), $subQuery1),
                C::greater(E::value($day1), $subQuery1)
            ),
            C::and(
                C::exists($subQueryExists2),
                C::lessOrEqual(E::value($day2), $subQuery2),
                C::greater(E::value($day1), $subQuery2)
            )
        );
    }

    /**
     * @return array<string, array<string, array<string, int>>>
     */
    private function getDataResults(): array
    {
        $resultData = [];

        foreach ($this->rangeList as $i => $range) {
            $where = $this->getWherePart($range);

            $query = SelectBuilder::create()
                ->from(Lead::ENTITY_TYPE)
                ->select(
                    E::count(E::column('id')),
                    'COUNT:id'
                )
                ->select('status')
                ->where(['status!=' => $this->ignoreStatusList])
                ->where($where)
                ->group('status')
                ->build();

            $sth = $this->entityManager->getQueryExecutor()->execute($query);

            $dateString = $this->getStringRange($i);

            foreach ($sth->fetchAll() as $row) {
                if (!array_key_exists($dateString, $resultData)) {
                    $resultData[$dateString] = [];
                }

                /** @var string $status */
                $status = $row['status'];

                $resultData[$dateString][$status] = [
                    'COUNT:id' => (int) $row['COUNT:id'],
                ];
            }
        }

        return $resultData;
    }
}
