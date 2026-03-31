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

namespace Espo\Modules\Advanced\Classes\FieldProcessing\BpmnProcess;

use Espo\Core\FieldProcessing\Loader;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\ORM\Collection;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * @implements Loader<BpmnProcess>
 */
class FlowchartDataLoader implements Loader
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function process(Entity $entity, Params $params): void
    {
        $flowchartData = $entity->get('flowchartData') ?? (object) [];

        $list = $flowchartData->list ?? [];

        $flowNodes = $this->entityManager
            ->getRDBRepositoryByClass(BpmnFlowNode::class)
            ->where(['processId' => $entity->getId()])
            ->order('number', true)
            ->limit(0, 400)
            ->find();

        foreach ($list as $item) {
            $this->loadOutlineData($item, $flowNodes);
        }

        $entity->set('flowchartData', $flowchartData);
    }

    /**
     * @param stdClass $item
     * @param Collection<BpmnFlowNode> $flowNodeList
     */
    private function loadOutlineData($item, $flowNodeList): void
    {
        $type = $item->type ?? null;
        $id = $item->id ?? null;

        if (!$type || !$id) {
            return;
        }

        if ($type === 'flow') {
            return;
        }

        if ($type === 'eventSubProcess' || $type === 'subProcess') {
            $list = $item->dataList ?? [];

            foreach ($flowNodeList as $flowNode) {
                if ($flowNode->get('elementId') == $id) {
                    $subProcessId = $flowNode->getDataItemValue('subProcessId');

                    $spFlowNodeList = $this->entityManager
                        ->getRDBRepositoryByClass(BpmnFlowNode::class)
                        ->where(['processId' => $subProcessId])
                        ->order('number', true)
                        ->limit(0, 400)
                        ->find();

                    foreach ($list as $spItem) {
                        $this->loadOutlineData($spItem, $spFlowNodeList);
                    }

                    break;
                }
            }
        }

        foreach ($flowNodeList as $flowNode) {
            $status = $flowNode->get('status');

            if ($flowNode->get('elementId') == $id) {
                if ($status === BpmnFlowNode::STATUS_PROCESSED) {
                    $item->outline = 3;

                    return;
                }
            }
        }

        foreach ($flowNodeList as $flowNode) {
            $status = $flowNode->get('status');

            if ($flowNode->get('elementId') == $id) {
                if ($status === BpmnFlowNode::STATUS_IN_PROCESS) {
                    $item->outline = 1;

                    return;
                }
            }
        }

        foreach ($flowNodeList as $flowNode) {
            $status = $flowNode->get('status');

            if ($flowNode->get('elementId') == $id) {
                if ($status === BpmnFlowNode::STATUS_PENDING) {
                    $item->outline = 2;

                    return;
                }
            }
        }

        foreach ($flowNodeList as $flowNode) {
            $status = $flowNode->get('status');

            if ($flowNode->get('elementId') == $id) {
                if (
                    $status === BpmnFlowNode::STATUS_FAILED ||
                    $status === BpmnFlowNode::STATUS_INTERRUPTED
                ) {
                    $item->outline = 4;

                    return;
                }
            }
        }
    }
}
