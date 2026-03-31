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

use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Exception;
use DateTime;

class EventIntermediateTimerCatch extends Event
{
    /** @var string */
    protected $pendingStatus = BpmnFlowNode::STATUS_PENDING;

    public function process(): void
    {
        $timerBase = $this->getAttributeValue('timerBase');

        if (!$timerBase || $timerBase === 'moment') {
            $dt = new DateTime();

            $this->shiftDateTime($dt);
        } else if ($timerBase === 'formula') {
            $timerFormula = $this->getAttributeValue('timerFormula');

            $formulaManager = $this->getFormulaManager();

            if (!$timerFormula) {
                $this->setFailed();

                throw new Error('Bpmn Flow: EventIntermediateTimer error.');
            }

            $value = $formulaManager->run($timerFormula, $this->getTarget(), $this->getVariablesForFormula());

            if (!$value || !is_string($value)) {
                $this->setFailed();

                throw new Error();
            }

            try {
                $dt = new DateTime($value);
            } catch (Exception) {
                $this->setFailed();

                throw new Error('Bpmn Flow: EventIntermediateTimer error.');
            }
        }
        else if (str_starts_with($timerBase, 'field:')) {
            $field = substr($timerBase, 6);
            $entity = $this->getTarget();

            if (strpos($field, '.') > 0) {
                [$link, $field] = explode('.', $field);

                $target = $this->getTarget();

                $entity = $this->getEntityManager()
                    ->getRDBRepository($target->getEntityType())
                    ->getRelation($target, $link)
                    ->findOne();

                if (!$entity) {
                    $this->setFailed();

                    throw new Error("Bpmn Flow: EventIntermediateTimer. Related entity doesn't exist.");
                }
            }

            $value = $entity->get($field);

            if (!$value || !is_string($value)) {
                $this->setFailed();

                throw new Error('Bpmn Flow: EventIntermediateTimer.');
            }

            try {
                $dt = new DateTime($value);
            }
            catch (Exception) {
                $this->setFailed();

                throw new Error('Bpmn Flow: EventIntermediateTimer error.');
            }

            $this->shiftDateTime($dt);
        }
        else {
            $this->setFailed();

            throw new Error('Bpmn Flow: EventIntermediateTimer error.');
        }

        $flowNode = $this->getFlowNode();

        $flowNode->set([
            'status' => $this->pendingStatus,
            'proceedAt' => $dt->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT)
        ]);

        $this->getEntityManager()->saveEntity($flowNode);
    }

    /**
     * @throws Error
     */
    protected function shiftDateTime(DateTime $dt): void
    {
        $timerShiftOperator = $this->getAttributeValue('timerShiftOperator');
        $timerShift = $this->getAttributeValue('timerShift');
        $timerShiftUnits = $this->getAttributeValue('timerShiftUnits');

        if (!in_array($timerShiftUnits, ['minutes', 'hours', 'days', 'months', 'seconds'])) {
            $flowNode = $this->getFlowNode();

            $this->setFailed();

            throw new Error("Bpmn Flow: Bad shift in ". $flowNode->get('elementType') . " " .
                $flowNode->get('elementId') . " in flowchart " . $flowNode->get('flowchartId') . ".");
        }

        if ($timerShift) {
            $modifyString = $timerShift . ' ' . $timerShiftUnits;

            if ($timerShiftOperator === 'minus') {
                $modifyString = '-' . $modifyString;
            }

            try {
                $dt->modify($modifyString);
                /** @phpstan-ignore-next-line  */
            } catch (Exception) {
                $this->setFailed();

                throw new Error('Bpmn Flow: EventIntermediateTimer error.');
            }
        }
    }

    public function proceedPending(): void
    {
        $this->getFlowNode()->set('status', BpmnFlowNode::STATUS_IN_PROCESS);
        $this->saveFlowNode();

        $this->rejectConcurrentPendingFlows();
        $this->processNextElement();
    }
}
