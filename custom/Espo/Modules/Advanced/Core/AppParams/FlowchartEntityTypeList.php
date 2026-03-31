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

namespace Espo\Modules\Advanced\Core\AppParams;

use Espo\Core\Acl;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Modules\Advanced\Entities\BpmnFlowchart;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\ORM\EntityManager;

class FlowchartEntityTypeList
{
    private Acl $acl;
    private EntityManager $entityManager;
    private SelectBuilderFactory $selectBuilderFactory;

    public function __construct(
        Acl $acl,
        EntityManager $entityManager,
        SelectBuilderFactory $selectBuilderFactory
    ) {
        $this->acl = $acl;
        $this->entityManager = $entityManager;
        $this->selectBuilderFactory = $selectBuilderFactory;
    }

    /**
     * @return string[]
     */
    public function get(): array
    {
        if (!$this->acl->checkScope(BpmnProcess::ENTITY_TYPE, Acl\Table::ACTION_CREATE)) {
            return [];
        }

        if (!$this->acl->checkScope(BpmnFlowchart::ENTITY_TYPE, Acl\Table::ACTION_READ)) {
            return [];
        }

        $list = [];

        $query = $this->selectBuilderFactory
            ->create()
            ->from(BpmnFlowchart::ENTITY_TYPE)
            ->withAccessControlFilter()
            ->build();

        $collection = $this->entityManager
            ->getRDBRepository(BpmnFlowchart::ENTITY_TYPE)
            ->clone($query)
            ->select(['targetType'])
            ->group('targetType')
            ->where(['isActive' => true])
            ->find();

        foreach ($collection as $item) {
            $list[] = $item->get('targetType');
        }

        return $list;
    }
}
