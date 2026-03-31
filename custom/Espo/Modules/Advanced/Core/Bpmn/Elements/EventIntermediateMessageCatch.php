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

namespace Espo\Modules\Advanced\Core\Bpmn\Elements;

use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Entities\Email;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;

class EventIntermediateMessageCatch extends Event
{
    public function process(): void
    {
        $flowNode = $this->getFlowNode();
        $flowNode->setStatus(BpmnFlowNode::STATUS_PENDING);

        $this->getEntityManager()->saveEntity($flowNode);
    }

    /**
     * @throws FormulaError
     * @throws Error
     */
    public function proceedPending(): void
    {
        $repliedToAliasId = $this->getAttributeValue('repliedTo');
        $messageType = $this->getAttributeValue('messageType') ?? 'Email';
        $relatedTo = $this->getAttributeValue('relatedTo');

        $conditionsFormula = $this->getAttributeValue('conditionsFormula');
        $conditionsFormula = trim($conditionsFormula, " \t\n\r");

        if (strlen($conditionsFormula) && str_ends_with($conditionsFormula, ';')) {
            $conditionsFormula = substr($conditionsFormula, 0, -1);
        }

        $target = $this->getTarget();

        $createdEntitiesData = $this->getCreatedEntitiesData();

        $repliedToId = null;

        if ($repliedToAliasId) {
            if (!isset($createdEntitiesData->$repliedToAliasId)) {
                return;
            }

            $repliedToId = $createdEntitiesData->$repliedToAliasId->entityId ?? null;
            $repliedToType = $createdEntitiesData->$repliedToAliasId->entityType ?? null;

            if (!$repliedToId || $messageType !== $repliedToType) {
                $this->fail();

                return;
            }
        }

        $flowNode = $this->getFlowNode();

        if ($messageType === 'Email') {
            $from = $flowNode->getDataItemValue('checkedAt') ?? $flowNode->get('createdAt');

            $whereClause = [
                'createdAt>=' => $from,
                'status' => Email::STATUS_ARCHIVED,
                'dateSent>=' => $flowNode->get('createdAt'),
                [
                    'OR' => [
                        'sentById' => null,
                        'sentBy.type' => 'portal', // @todo Change to const.
                    ]
                ],
            ];

            if ($repliedToId) {
                $whereClause['repliedId'] = $repliedToId;

            } else if ($relatedTo) {
                $relatedTarget = $this->getSpecificTarget($relatedTo);

                if (!$relatedTarget) {
                    $this->updateCheckedAt();

                    return;
                }

                if ($relatedTarget->getEntityType() === 'Account') {
                    $whereClause['accountId'] = $relatedTarget->getId();
                } else {
                    $whereClause['parentId'] = $relatedTarget->getId();
                    $whereClause['parentType'] = $relatedTarget->getEntityType();
                }
            }

            if (!$repliedToId && !$relatedTo) {
                if ($target->getEntityType() === 'Contact' && $target->get('accountId')) {
                    $whereClause[] = [
                        'OR' => [
                            [
                                'parentType' => 'Contact',
                                'parentId' => $target->getId(),
                            ],
                            [
                                'parentType' => 'Account',
                                'parentId' => $target->get('accountId'),
                            ],
                        ]
                    ];
                }
                else if ($target->getEntityType() === 'Account') {
                    $whereClause['accountId'] = $target->getId();
                }
                else {
                    $whereClause['parentId'] = $target->getId();
                    $whereClause['parentType'] = $target->getEntityType();
                }
            }

            /** @var Config $config */
            $config = $this->getContainer()->get('config');

            $limit = $config->get('bpmnMessageCatchLimit', 50);

            $emailList = $this->getEntityManager()
                ->getRDBRepository(Email::ENTITY_TYPE)
                ->leftJoin('sentBy')
                ->where($whereClause)
                ->limit(0, $limit)
                ->find();

            if (!count($emailList)) {
                $this->updateCheckedAt();

                return;
            }

            if ($conditionsFormula) {
                $isFound = false;

                foreach ($emailList as $email) {
                    $formulaResult = $this->getFormulaManager()
                        ->run($conditionsFormula, $email, $this->getVariablesForFormula());

                    if ($formulaResult) {
                        $isFound = true;

                        break;
                    }
                }

                if (!$isFound) {
                    $this->updateCheckedAt();

                    return;
                }
            }
        }
        else {
            $this->fail();

            return;
        }

        $flowNode = $this->getFlowNode();

        $flowNode->setStatus(BpmnFlowNode::STATUS_IN_PROCESS);

        $this->getEntityManager()->saveEntity($flowNode);

        $this->proceedPendingFinal();
    }

    /**
     * @throws Error
     */
    protected function proceedPendingFinal(): void
    {
        $this->rejectConcurrentPendingFlows();
        $this->processNextElement();
    }

    protected function updateCheckedAt(): void
    {
        $flowNode = $this->getFlowNode();

        $flowNode->setDataItemValue('checkedAt', date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT));

        $this->getEntityManager()->saveEntity($flowNode);
    }
}
