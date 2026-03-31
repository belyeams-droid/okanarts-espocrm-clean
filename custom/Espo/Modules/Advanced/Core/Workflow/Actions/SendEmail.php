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

namespace Espo\Modules\Advanced\Core\Workflow\Actions;

use Espo\Core\Exceptions\Error;
use Espo\Core\Job\QueueName;
use Espo\Core\Mail\Exceptions\NoSmtp;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\Email;
use Espo\Entities\Job;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Workflow\Jobs\SendEmail as SendEmailJob;
use Espo\Modules\Advanced\Tools\Workflow\SendEmailService;
use Espo\Modules\Crm\Entities\Contact;
use Espo\Repositories\Email as EmailRepository;

use RuntimeException;
use stdClass;

class SendEmail extends Base
{
    /**
     * @throws Error
     * @throws NoSmtp
     */
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        $jobData = [
            'workflowId' => $this->getWorkflowId(),
            'entityId' => $this->getEntity()->getId(),
            'entityType' => $this->getEntity()->getEntityType(),
            'from' => $this->getEmailAddressData('from'),
            'to' => $this->getEmailAddressData('to'),
            'replyTo' => $this->getEmailAddressData('replyTo'),
            'cc' => $this->getEmailAddressData('cc'),
            'emailTemplateId' => $actionData->emailTemplateId ?? null,
            'doNotStore' => $actionData->doNotStore ?? false,
            'optOutLink' => $actionData->optOutLink ?? false,
        ];

        if ($this->bpmnProcess) {
            $jobData['processId'] = $this->bpmnProcess->getId();
        }

        $attachmentsVariable = $actionData->attachmentsVariable ?? null;

        $jobData['attachmentIds'] = $this->getAttachmentIds($attachmentsVariable);

        if (is_null($jobData['to'])) {
            return true;
        }

        if (!empty($actionData->processImmediately)) {
            $storeSentEmailData = !!$this->createdEntitiesData && !$jobData['doNotStore'];

            if ($storeSentEmailData) {
                $jobData['returnEmailId'] = true;
            }

            if ($this->hasVariables()) {
                $jobData['variables'] = $this->getVariables();
            }

            $service = $this->injectableFactory->create(SendEmailService::class);

            /** @phpstan-ignore-next-line  */
            $jobData = json_decode(json_encode($jobData));

            $emailId = $service->send($jobData);

            if (
                $storeSentEmailData &&
                $emailId &&
                isset($actionData->elementId)
            ) {
                $alias = $actionData->elementId;

                $this->createdEntitiesData->$alias = (object) [
                    'entityType' => Email::ENTITY_TYPE,
                    'entityId' => $emailId,
                ];
            }

            return true;
        }

        $job = $this->entityManager->getNewEntity(Job::ENTITY_TYPE);

        $job->set([
            'name' => SendEmailJob::class,
            'className' => SendEmailJob::class,
            'data' => $jobData,
            'executeTime' => $this->getExecuteTime($actionData),
            'queue' => QueueName::E0,
        ]);

        $this->entityManager->saveEntity($job);

        return true;
    }

    /**
     * @param string $type
     * @return ?array{
     *     email?: string,
     *     type: string,
     *     entityType?: string,
     *     entityId?: string,
     * }
     * @throws Error
     */
    private function getEmailAddressData(string $type): ?array
    {
        $data = $this->getActionData();

        $fieldValue = $data->$type ?? null;

        switch ($fieldValue) {
            case 'specifiedEmailAddress':
                $address = $data->{$type . 'Email'};

                if ($address && str_contains($address, '{$$') && $this->hasVariables()) {
                    $variables = $this->getVariables();

                    foreach (get_object_vars($variables) as $key => $v) {
                        if ($v && is_string($v)) {
                            $address = str_replace('{$$'.$key.'}', $v, $address);
                        }
                    }
                }

                return [
                    'email' => $address,
                    'type' => $fieldValue,
                ];

            case 'processAssignedUser':
                if (!$this->bpmnProcess) {
                    return null;
                }

                if (!$this->bpmnProcess->get('assignedUserId')) {
                    return null;
                }

                return [
                    'entityType' => User::ENTITY_TYPE,
                    'entityId' => $this->bpmnProcess->get('assignedUserId'),
                    'type' => $fieldValue,
                ];

            case 'targetEntity':
            case 'teamUsers':
            case 'followers':
            case 'followersExcludingAssignedUser':
                $entity = $this->getEntity();

                return [
                    'entityType' => $entity->getEntityType(),
                    'entityId' => $entity->getId(),
                    'type' => $fieldValue,
                ];

            case 'specifiedTeams':
            case 'specifiedUsers':
            case 'specifiedContacts':
                $specifiedEntityType = null;

                if ($fieldValue === 'specifiedTeams') {
                    $specifiedEntityType = Team::ENTITY_TYPE;
                }

                if ($fieldValue === 'specifiedUsers') {
                    $specifiedEntityType = User::ENTITY_TYPE;
                }

                if ($fieldValue === 'specifiedContacts') {
                    $specifiedEntityType = Contact::ENTITY_TYPE;
                }

                /** @var string $specifiedEntityType */

                return [
                    'type' => $fieldValue,
                    'entityIds' => $data->{$type . 'SpecifiedEntityIds'},
                    'entityType' => $specifiedEntityType,
                ];

            case 'currentUser':
                return [
                    'entityType' => User::ENTITY_TYPE,
                    'entityId' => $this->user->getId(),
                    'type' => $fieldValue,
                ];

            case 'system':
                return [
                    'type' => $fieldValue,
                ];

            case 'fromOrReplyTo':
                $entity = $this->getEntity();
                $emailAddress = null;

                /** @var EmailRepository $repo */
                $repo = $this->entityManager->getRepository(Email::ENTITY_TYPE);

                if (!$entity instanceof Email) {
                    throw new RuntimeException("Workflow send-email fromOrReplyTo did not receive email.");
                }

                $repo->loadFromField($entity);

                if ($entity->has('replyToString') && $entity->get('replyToString')) {
                    $replyTo = $entity->get('replyToString');

                    $arr = explode(';', $replyTo);
                    $emailAddress = $arr[0];

                    /** @noinspection RegExpRedundantEscape */
                    preg_match_all("/[\._a-zA-Z0-9-]+@[\._a-zA-Z0-9-]+/i", $emailAddress, $matches);

                    if (empty($matches[0])) {
                        return null;
                    }

                    $emailAddress = $matches[0][0];
                }
                else if ($entity->has('from') && $entity->get('from')) {
                    $emailAddress = $entity->get('from');
                }

                if (!$emailAddress) {
                    return null;
                }

                return [
                    'type' => $fieldValue,
                    'email' => $emailAddress,
                ];

            default:
                if (!$fieldValue) {
                    return null;
                }

                $recipients = $this->getRecipients($this->getEntity(), $fieldValue);

                if ($recipients->getIds() === []) {
                    return null;
                }

                if (!$recipients->getEntityType()) {
                    throw new Error("No Send Email action recipients entity type.");
                }

                if ($recipients->isOne()) {
                    return [
                        'entityType' => $recipients->getEntityType(),
                        'entityId' => $recipients->getIds()[0],
                        'type' => $fieldValue,
                    ];
                }

                return [
                    'entityType' => $recipients->getEntityType(),
                    'entityIds' => $recipients->getIds(),
                    'type' => $fieldValue,
                ];
        }
    }

    /**
     * @return string[]
     */
    private function getAttachmentIds(mixed $attachmentsVariable): array
    {
        $attachmentIds = [];

        if (is_string($attachmentsVariable) && $attachmentsVariable[0] === '$') {
            $attachmentsVariable = substr($attachmentsVariable, 1);
        }

        if ($this->hasVariables() && is_string($attachmentsVariable) && $attachmentsVariable) {
            $attachmentIds = $this->getVariables()->$attachmentsVariable ?? null;

            if (is_string($attachmentIds)) {
                $attachmentIds = [$attachmentIds];
            }

            if (!is_array($attachmentIds)) {
                $attachmentIds = [];
            }
        }

        foreach ($attachmentIds as $id) {
            if (!is_string($id)) {
                throw new RuntimeException("Not a string value in attachments variable.");
            }
        }

        return $attachmentIds;
    }
}
