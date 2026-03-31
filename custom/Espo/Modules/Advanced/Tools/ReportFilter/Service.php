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

namespace Espo\Modules\Advanced\Tools\ReportFilter;

use Espo\Core\DataManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Advanced\Classes\Select\Common\PrimaryFilters\ReportFilter as ReportPrimaryFilter;
use Espo\Modules\Advanced\Core\ReportFilter as ReportFilterUtil;
use Espo\Modules\Advanced\Entities\ReportFilter;
use Espo\ORM\EntityManager;

class Service
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private InjectableFactory $injectableFactory,
        private DataManager $dataManager,
        private Config $config
    ) {}

    public function rebuild(?string $specificEntityType = null): void
    {
        $scopeData = $this->metadata->get(['scopes'], []);

        $entityTypeList = [];

        $language = $this->injectableFactory->createWith(Language::class, ['language' => 'en_US']);

        $isAnythingChanged = false;

        if ($specificEntityType) {
            $entityTypeList[] = $specificEntityType;
        } else {
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

        foreach ($entityTypeList as $entityType) {
            /** @var array<string, mixed> $removedHash */
            $removedHash = [];
            $isChanged = false;

            $clientDefs = $this->metadata->getCustom('clientDefs', $entityType, (object) []);
            $filterList = [];
            $toAppend = true;

            if (isset($clientDefs->filterList)) {
                $toAppend = false;
                $filterList = $clientDefs->filterList;
            }

            foreach ($filterList as $i => $item) {
                if (is_string($item)) {
                    if ($item === '__APPEND__') {
                        unset($filterList[$i]);
                        $toAppend = true;
                    }

                    continue;
                }

                if (!empty($item->isReportFilter)) {
                    unset($filterList[$i]);
                    $isChanged = true;
                }
            }

            $filterList = array_values($filterList);

            $entityDefs = $this->metadata->getCustom('entityDefs', $entityType, (object) []);

            $filtersData = (object) [];

            if (isset($entityDefs->collection) && isset($entityDefs->collection->filters)) {
                $filtersData = $entityDefs->collection->filters;

                if (is_array($filtersData)) {
                    $filtersData = (object) [];
                }
            }

            foreach ($filtersData as $filter => $item) {
                if (!empty($item->isReportFilter)) {
                    unset($filtersData->$filter);

                    $removedHash[$filter] = true;
                    $isChanged = true;
                }
            }

            $reportFilterList = $this->entityManager
                ->getRDBRepositoryByClass(ReportFilter::class)
                ->where([
                    'isActive' => true,
                    'entityType' => $entityType,
                ])
                ->order('order')
                ->find();

            $supportsFilterNames = $this->supportsFilterNames();

            foreach ($reportFilterList as $reportFilter) {
                $isChanged = true;
                $name = 'reportFilter' . $reportFilter->getId();

                $o = (object) [
                    'isReportFilter' => true,
                    'name' => $name,
                ];

                if (count($reportFilter->getLinkMultipleIdList('teams'))) {
                    $o->accessDataList = [
                        (object) ['teamIdList' => $reportFilter->getLinkMultipleIdList('teams')]
                    ];
                }

                $filterList[] = $o;

                unset($removedHash[$name]);

                $filtersData->$name = (object) [
                    'isReportFilter' => true,
                    'className' => ReportFilterUtil::class,
                    'id' => $reportFilter->getId(),
                ];

                if ($supportsFilterNames) {
                    unset($filtersData->$name->className);
                }

                $language->set($entityType, 'presetFilters', $name, $reportFilter->get('name'));
            }

            if ($isChanged) {
                $isAnythingChanged = true;

                $clientDefs = $this->metadata->getCustom('clientDefs', $entityType, (object) []);

                if (!empty($filterList)) {
                    if ($toAppend) {
                        array_unshift($filterList, '__APPEND__');
                    }

                    $clientDefs->filterList = $filterList;
                } else {
                    unset($clientDefs->filterList);
                }

                $this->metadata->saveCustom('clientDefs', $entityType, $clientDefs);

                if ($supportsFilterNames) {
                    $selectDefs = $this->metadata->getCustom('selectDefs', $entityType, (object) []);

                    if (!isset($selectDefs->primaryFilterClassNameMap)) {
                        $selectDefs->primaryFilterClassNameMap = (object) [];
                    }
                }

                $entityDefs = $this->metadata->getCustom('entityDefs', $entityType, (object) []);

                if (!isset($entityDefs->collection)) {
                    $entityDefs->collection = (object) [];
                }

                $entityDefs->collection->filters = $filtersData;

                $this->metadata->saveCustom('entityDefs', $entityType, $entityDefs);

                if ($supportsFilterNames && isset($selectDefs)) {
                    foreach (get_object_vars($filtersData) as $name => $ignored) {
                        $selectDefs->primaryFilterClassNameMap->$name = ReportPrimaryFilter::class;
                    }
                }

                foreach ($removedHash as $name => $item) {
                    $language->delete($entityType, 'presetFilters', $name);

                    if ($supportsFilterNames && isset($selectDefs)) {
                        unset($selectDefs->primaryFilterClassNameMap->$name);
                    }
                }

                if ($supportsFilterNames && isset($selectDefs)) {
                    $this->metadata->saveCustom('selectDefs', $entityType, $selectDefs);
                }
            }
        }

        if ($isAnythingChanged) {
            $language->save();

            $this->dataManager->clearCache();
        }
    }

    private function supportsFilterNames(): bool
    {
        $version = $this->config->get('version');

        return version_compare($version, '7.5.0') >= 0;
    }
}
