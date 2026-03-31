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
use Espo\Core\InjectableFactory;
use Espo\Modules\Advanced\Core\Bpmn\Utils\MessageSenders\EmailType;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Throwable;

/**
 * @noinspection PhpUnused
 */
class TaskSendMessage extends Activity
{
    public function process(): void
    {
        $this->getFlowNode()->setStatus(BpmnFlowNode::STATUS_PENDING);
        $this->saveFlowNode();
    }

    public function proceedPending(): void
    {
        $createdEntitiesData = $this->getCreatedEntitiesData();

        try {
            $this->getImplementation()->process(
                $this->getTarget(),
                $this->getFlowNode(),
                $this->getProcess(),
                $createdEntitiesData,
                $this->getVariables()
            );
        } catch (Throwable $e) {
            $message = "Process {$this->getProcess()->getId()}, element {$this->getFlowNode()->getElementId()}, " .
                "send message error: {$e->getMessage()}";

            $this->getLog()->error($message, ['exception' => $e]);

            $this->setFailedWithException($e);

            return;
        }

        $this->getProcess()->set('createdEntitiesData', $createdEntitiesData);
        $this->getEntityManager()->saveEntity($this->getProcess());

        $this->processNextElement();
    }

    /**
     * @return EmailType
     * @throws Error
     * @todo Use factory.
     */
    private function getImplementation(): EmailType
    {
        $messageType = $this->getAttributeValue('messageType');

        if (!$messageType) {
            throw new Error('Process ' . $this->getProcess()->getId() . ', no message type.');
        }

        $messageType = str_replace('\\', '', $messageType);

        /** @var class-string<EmailType> $className */
        $className = "Espo\\Modules\\Advanced\\Core\\Bpmn\\Utils\\MessageSenders\\{$messageType}Type";

        if (!class_exists($className)) {
            throw new Error(
                'Process ' . $this->getProcess()->getId() . ' element ' .
                $this->getFlowNode()->get('elementId'). ' send message not found implementation class.');
        }


        return $this->getContainer()
            ->getByClass(InjectableFactory::class)
            ->create($className);
    }
}
