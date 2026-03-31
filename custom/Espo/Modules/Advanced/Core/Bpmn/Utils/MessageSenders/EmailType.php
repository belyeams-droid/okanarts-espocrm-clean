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

namespace Espo\Modules\Advanced\Core\Bpmn\Utils\MessageSenders;

use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Modules\Advanced\Core\Workflow\Actions\SendEmail;
use Espo\ORM\Entity;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use stdClass;

class EmailType
{

    public function __construct(
        private InjectableFactory $injectableFactory,
    ) {}

    /**
     * @throws Error
     */
    public function process(
        Entity $target,
        BpmnFlowNode $flowNode,
        BpmnProcess $process,
        stdClass $createdEntitiesData,
        stdClass $variables
    ): void {

        $elementData = $flowNode->getElementData();

        if (empty($elementData->from)) {
            throw new Error("No 'from'.");
        }

        $from = $elementData->from;

        if (empty($elementData->to)) {
            throw new Error("No 'to'.");
        }

        $to = $elementData->to;

        $replyTo = null;

        if (!empty($elementData->replyTo)) {
            $replyTo = $elementData->replyTo;
        }

        $cc = null;

        if (!empty($elementData->cc)) {
            $cc = $elementData->cc;
        }

        if (empty($elementData->emailTemplateId)) {
            throw new Error("No 'emailTemplateId'.");
        }

        $emailTemplateId = $elementData->emailTemplateId;

        $doNotStore = false;

        if (isset($elementData->doNotStore)) {
            $doNotStore = $elementData->doNotStore;
        }

        $actionData = (object) [
            'type' => 'SendEmail',
            'from' => $from,
            'to' => $to,
            'cc' => $cc,
            'replyTo' => $replyTo,
            'emailTemplateId' => $emailTemplateId,
            'doNotStore' => $doNotStore,
            'processImmediately' => true,
            'elementId' => $flowNode->get('elementId'),
            'optOutLink' => $elementData->optOutLink ?? false,
            'attachmentsVariable' => $elementData->attachmentsVariable ?? null,
        ];

        if (property_exists($elementData, 'toEmailAddress')) {
            $actionData->toEmail = $elementData->toEmailAddress;
        }

        if (property_exists($elementData, 'fromEmailAddress')) {
            $actionData->fromEmail = $elementData->fromEmailAddress;
        }

        if (property_exists($elementData, 'replyToEmailAddress')) {
            $actionData->replyToEmail = $elementData->replyToEmailAddress;
        }

        if (property_exists($elementData, 'ccEmailAddress')) {
            $actionData->ccEmail = $elementData->ccEmailAddress;
        }

        if (in_array($to, ['specifiedContacts', 'specifiedUsers', 'specifiedTeams'])) {
            $actionData->toSpecifiedEntityIds = $elementData->{'to' . ucfirst($to) . 'Ids'};
        }

        // Not used. Not available on UI.
        if (in_array($replyTo, ['specifiedContacts', 'specifiedUsers', 'specifiedTeams'])) {
            $actionData->replyToSpecifiedEntityIds = $elementData->{'replyTo' . ucfirst($replyTo) . 'Ids'};
        }

        // Not used. Not available on UI.
        if (in_array($cc, ['specifiedContacts', 'specifiedUsers', 'specifiedTeams'])) {
            $actionData->ccSpecifiedEntityIds = $elementData->{'cc' . ucfirst($cc) . 'Ids'};
        }

        $this->getActionImplementation()->process(
            entity: $target,
            actionData: $actionData,
            createdEntitiesData: $createdEntitiesData,
            variables: $variables,
            bpmnProcess: $process,
        );
    }

    private function getActionImplementation(): SendEmail
    {
        return $this->injectableFactory->create(SendEmail::class);
    }
}
