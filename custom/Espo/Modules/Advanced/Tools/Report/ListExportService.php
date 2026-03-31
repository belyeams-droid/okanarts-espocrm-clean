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
use Espo\Core\AclManager;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Tools\Report\ListType\ExportParams;
use Espo\Modules\Advanced\Tools\Report\ListType\RunParams as ListRunParams;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\ORM\Defs as OrmDefs;
use Espo\ORM\EntityManager;
use Espo\Tools\Export\Export as ExportTool;
use Espo\Tools\Export\Params as ExportToolParams;

class ListExportService
{
    private AclManager $aclManager;
    private InjectableFactory $injectableFactory;
    private Service $service;
    private OrmDefs $ormDefs;
    private EntityManager $entityManager;

    public function __construct(
        AclManager $aclManager,
        InjectableFactory $injectableFactory,
        Service $service,
        OrmDefs $ormDefs,
        EntityManager $entityManager
    ) {
        $this->aclManager = $aclManager;
        $this->injectableFactory = $injectableFactory;
        $this->service = $service;
        $this->ormDefs = $ormDefs;
        $this->entityManager = $entityManager;
    }

    /**
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function export(
        string $id,
        SearchParams $searchParams,
        ExportParams $exportParams,
        ?SubReportParams $subReportParams = null,
        ?User $user = null
    ): string {

        $runParams = ListRunParams::create()->withIsExport();

        if (
            $user &&
            $this->aclManager->getPermissionLevel($user, 'exportPermission') !== AclTable::LEVEL_YES
        ) {
            throw new Forbidden("Export is forbidden.");
        }

        if ($exportParams->getFieldList() === null) {
            $runParams = $runParams->withFullSelect();
        }
        else {
            $customColumnList = [];

            foreach ($exportParams->getFieldList() as $item) {
                $value = $item;

                if (strpos($item, '_') !== false) {
                    $value = str_replace('_', '.', $item);
                }

                $customColumnList[] = $value;
            }

            $runParams = $runParams->withCustomColumnList($customColumnList);
        }

        if ($exportParams->getIds()) {
            $searchParams = $searchParams->withWhereAdded(
                WhereItem::createBuilder()
                    ->setAttribute('id')
                    ->setType('equals')
                    ->setValue($exportParams->getIds())
                    ->build()
            );
        }

        if ($subReportParams) {
            $searchParams = $searchParams->withSelect($exportParams->getAttributeList());
        }

        $reportResult = $subReportParams ?
            $this->service->runSubReportList(
                $id,
                $searchParams,
                $subReportParams,
                $user,
                $runParams
            ) :
            $this->service->runList(
                $id,
                $searchParams,
                $user,
                $runParams
            );

        $collection = $reportResult->getCollection();

        /** @var ?Report $report */
        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $id);

        if (!$report) {
            throw new NotFound("Report $id not found.");
        }

        $entityType = $report->getTargetEntityType();

        if (
            $user &&
            !$this->aclManager->checkScope($user, $entityType, AclTable::ACTION_READ)
        ) {
            throw new Forbidden("No 'read' access for '$entityType' scope.");
        }

        $attributeList = null;

        if ($exportParams->getAttributeList()) {
            $attributeList = [];

            foreach ($exportParams->getAttributeList() as $attribute) {
                if (strpos($attribute, '_')) {
                    [$link, $field] = explode('_', $attribute);

                    $foreignType = $this->getForeignFieldType($entityType, $link, $field);

                    if ($foreignType === 'link') {
                        $attributeList[] = $attribute . 'Id';
                        $attributeList[] = $attribute . 'Name';

                        continue;
                    }
                }

                $attributeList[] = $attribute;
            }
        }

        $export = $this->injectableFactory->create(ExportTool::class);

        $exportParamsNew = ExportToolParams::create($entityType)
            ->withAttributeList($attributeList)
            ->withFieldList($exportParams->getFieldList())
            ->withFormat($exportParams->getFormat())
            ->withName($report->getName())
            ->withFileName($report->getName() . ' ' . date('Y-m-d'));

        foreach ($exportParams->getParams() as $k => $v) {
            $exportParamsNew = $exportParamsNew->withParam($k, $v);
        }

        return $export
            ->setParams($exportParamsNew)
            ->setCollection($collection)
            ->run()
            ->getAttachmentId();
    }

    private function getForeignFieldType(string $entityType, string $link, string $field): ?string
    {
        $entityDefs = $this->ormDefs->getEntity($entityType);

        if (!$entityDefs->hasRelation($link)) {
            return null;
        }

        $relationDefs = $entityDefs->getRelation($link);

        if (!$relationDefs->hasForeignEntityType()) {
            return null;
        }

        $entityDefs = $this->ormDefs->getEntity($relationDefs->getForeignEntityType());

        if (!$entityDefs->hasField($field)) {
            return null;
        }

        return $entityDefs->getField($field)->getType();
    }
}
