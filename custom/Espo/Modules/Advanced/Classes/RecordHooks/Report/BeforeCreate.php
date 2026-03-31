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

namespace Espo\Modules\Advanced\Classes\RecordHooks\Report;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\Hook\CreateHook;
use Espo\Modules\Advanced\Entities\Report;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * @implements CreateHook<Report>
 */
class BeforeCreate implements CreateHook
{
    private Acl $acl;
    private EntityManager $entityManager;

    public function __construct(
        Acl $acl,
        EntityManager $entityManager
    ) {
        $this->acl = $acl;
        $this->entityManager = $entityManager;
    }

    public function process(Entity $entity, CreateParams $params): void
    {
        $this->processJointGridBeforeSave($entity);

        if (
            in_array('applyAcl', $this->acl->getScopeForbiddenFieldList(Report::ENTITY_TYPE, Table::ACTION_EDIT))
        ) {
            $entity->setApplyAcl();
        }

        if (!$this->acl->check($entity->getTargetEntityType(), Table::ACTION_READ)) {
            throw new Forbidden("No 'read' access to target entity type.");
        }
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     */
    public function processJointGridBeforeSave(Report $entity): void
    {
        if ($entity->getType() !== Report::TYPE_JOINT_GRID) {
            return;
        }

        $joinedReportDataList = $entity->get('joinedReportDataList');

        if (!is_array($joinedReportDataList) || !count($joinedReportDataList)) {
            return;
        }

        $groupCount = 0;

        foreach ($joinedReportDataList as $i => $item) {
            if (empty($item->id)) {
                throw new BadRequest();
            }

            /** @var ?Report $report */
            $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $item->id);

            if (!$report) {
                throw new Forbidden('Report not found.');
            }

            if (!$this->acl->check($report->getTargetEntityType(), Table::ACTION_READ)) {
                throw new Forbidden();
            }

            $groupBy = $report->getGroupBy();

            if (
                count($groupBy) > 1 ||
                $report->getType() !== Report::TYPE_GRID
            ) {
                throw new Forbidden("Sub-report $item->id is not supported in joint report.");
            }

            if ($i === 0) {
                $groupCount = count($groupBy);

                $entityType = $report->getTargetEntityType();
                $entity->set('entityType', $entityType);

                continue;
            }

            if ($groupCount !== count($groupBy)) {
                throw new BadRequest("Sub-reports must have the same Group By number.");
            }
        }
    }
}
