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

namespace Espo\Modules\Advanced\Tools\Workflow;

use Espo\Core\Mail\Exceptions\NoSmtp;
use Espo\Core\Mail\Sender;
use Espo\Core\Mail\SenderParams;
use Espo\Core\Mail\SmtpParams;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Tools\EmailTemplate\Result;
use Laminas\Mail\Message;

use Espo\Core\Mail\Account\GroupAccount\AccountFactory as GroupAccountFactory;
use Espo\Core\Mail\Account\PersonalAccount\AccountFactory as PersonalAccountFactory;
use Espo\Core\InjectableFactory;
use Espo\Core\Exceptions\Error;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Hasher;
use Espo\Core\Utils\Language;
use Espo\Entities\Attachment;
use Espo\Entities\Email;
use Espo\Entities\EmailAccount;
use Espo\Entities\EmailTemplate;
use Espo\Entities\InboundEmail;
use Espo\Entities\User;
use Espo\Modules\Advanced\Core\Workflow\Helper;
use Espo\Modules\Advanced\Entities\BpmnProcess as BpmnProcessEntity;
use Espo\Modules\Advanced\Entities\Workflow as WorkflowEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\EmailTemplate\Processor as EmailTemplateProcessor;
use Espo\Tools\EmailTemplate\Data as EmailTemplateData;
use Espo\Tools\EmailTemplate\Params as EmailTemplateParams;

use RuntimeException;
use Exception;
use stdClass;

class SendEmailService
{
    public function __construct(
        private EntityManager $entityManager,
        private ServiceContainer $recordServiceContainer,
        private Config $config,
        private Helper $workflowHelper,
        private EmailSender $emailSender,
        private Hasher $hasher,
        private Language $defaultLanguage,
        private EmailTemplateProcessor $emailTemplateProcessor,
        private InjectableFactory $injectableFactory
    ) {}

    /**
     * Send email for a workflow.
     * @return bool|string
     * @throws Error
     * @throws NoSmtp
     * @todo Introduce SendEmailData class.
     */
    public function send(stdClass $data)
    {
        $workflowId = $data->workflowId;

        if (!$this->validateSendEmailData($data)) {
            throw new Error("Workflow[$workflowId][sendEmail]: Email data is invalid.");
        }

        $data->doNotStore ??= false;
        $data->returnEmailId ??= false;
        $data->from ??= (object) [];
        $data->to ??= (object) [];
        $data->cc ??= null;
        $data->replyTo ??= null;
        $data->attachmentIds ??= [];

        /**
         * @var object{
         *     variables?: stdClass,
         *     optOutLink?: bool,
         *     attachmentIds: string[],
         *     entityType?: string|null,
         *     entityId?: string|null,
         *     from: stdClass,
         *     to: stdClass,
         *     cc: stdClass|null,
         *     replyTo: stdClass|null,
         *     doNotStore: bool,
         *     returnEmailId: bool,
         * } & stdClass $data
         */

        if ($workflowId) {
            $workflow = $this->entityManager->getRDBRepositoryByClass(WorkflowEntity::class)->getById($workflowId);

            if (!$workflow || !$workflow->isActive()) {
                return false;
            }
        }

        $entity = null;

        if (!empty($data->entityType) && !empty($data->entityId)) {
            $entity = $this->entityManager->getEntityById($data->entityType, $data->entityId);
        }

        if (!$entity) {
            throw new Error("Workflow[$workflowId][sendEmail]: Target Entity is not found.");
        }

        $this->recordServiceContainer->get($entity->getEntityType())
            ->loadAdditionalFields($entity);

        $fromAddress = $this->getEmailAddress($data->from);
        $toAddress = $this->getEmailAddress($data->to);
        $replyToAddress = !empty($data->replyTo) ? $this->getEmailAddress($data->replyTo) : null;
        $ccAddress = !empty($data->cc) ? $this->getEmailAddress($data->cc) : null;

        if (!$fromAddress) {
            throw new Error("Workflow[$workflowId][sendEmail]: From email address is empty or could not be obtained.");
        }

        if (!$toAddress) {
            throw new Error("Workflow[$workflowId][sendEmail]: To email address is empty.");
        }

        /** @var array<string, Entity> $entityHash */
        $entityHash = [$data->entityType => $entity];

        if (
            isset($data->to->entityType) &&
            isset($data->to->entityId) &&
            $data->to->entityType !== $data->entityType
        ) {
            /** @var string $toEntityType */
            $toEntityType = $data->to->entityType;

            $toEntity = $this->entityManager->getEntityById($toEntityType, $data->to->entityId);

            if ($toEntity) {
                $entityHash[$toEntityType] = $toEntity;
            }
        }

        $fromName = null;

        if (
            isset($data->from->entityType) &&
            isset($data->from->entityId) &&
            $data->from->entityType === User::ENTITY_TYPE
        ) {
            $user = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($data->from->entityId);

            if ($user) {
                $entityHash[User::ENTITY_TYPE] = $user;

                $fromName = $user->getName();
            }
        }

        $sender = $this->emailSender->create();

        $templateResult = $this->getTemplateResult(
            data: $data,
            entityHash: $entityHash,
            toEmailAddress: $toAddress,
            entity: $entity,
        );

        [$subject, $body] = $this->prepareSubjectBody(
            templateResult: $templateResult,
            data: $data,
            toEmailAddress: $toAddress,
            sender: $sender,
        );

        $emailData = [
            'from' => $fromAddress,
            'to' => $toAddress,
            'cc' => $ccAddress,
            'replyTo' => $replyToAddress,
            'subject' => $subject,
            'body' => $body,
            'isHtml' => $templateResult->isHtml(),
            'parentId' => $entity->getId(),
            'parentType' => $entity->getEntityType(),
        ];

        if ($fromName !== null) {
            $emailData['fromName'] = $fromName;
        }

        $email = $this->entityManager->getRDBRepositoryByClass(Email::class)->getNew();

        $email->setMultiple($emailData);

        $attachmentList = $this->getAttachmentList($templateResult, $data->attachmentIds);

        if (!$data->doNotStore) {
            // Additional attachments not added intentionally?
            $email->set('attachmentsIds', $templateResult->getAttachmentIdList());
        }

        $smtpParams = $this->prepareSmtpParams($data, $fromAddress);

        if ($smtpParams) {
            $sender->withSmtpParams($smtpParams);
        }

        $sender->withAttachments($attachmentList);

        if ($replyToAddress) {
            $senderParams = SenderParams::create()->withReplyToAddress($replyToAddress);

            $sender->withParams($senderParams);
        }

        try {
            $sender->send($email);
        } catch (Exception $e) {
            $sendExceptionMessage = $e->getMessage();

            throw new Error("Workflow[$workflowId][sendEmail]: $sendExceptionMessage.", 0, $e);
        }

        if ($data->doNotStore) {
            return true;
        }

        $this->storeEmail($email, $data);

        if ($data->returnEmailId) {
            return $email->getId();
        }

        return true;
    }

    private function validateSendEmailData(stdClass $data): bool
    {
        if (
            !isset($data->entityId) ||
            !(isset($data->entityType)) ||
            !isset($data->emailTemplateId) ||
            !isset($data->from) ||
            !isset($data->to)
        ) {
            return false;
        }

        return true;
    }

    private function getEmailAddress(stdClass $data): ?string
    {
        if (isset($data->email)) {
            return $data->email;
        }

        $entityType = $data->entityType ?? $data->entityName ?? null;

        $entity = null;

        if (isset($entityType) && isset($data->entityId)) {
            $entity = $this->entityManager->getEntityById($entityType, $data->entityId);
        }

        $workflowHelper = $this->workflowHelper;

        if (isset($data->type)) {
            switch ($data->type) {
                case 'specifiedTeams':
                    $userIds = $workflowHelper->getUserIdsByTeamIds($data->entityIds);

                    return implode('; ', $workflowHelper->getUsersEmailAddress($userIds));

                case 'teamUsers':
                    if (!$entity instanceof CoreEntity) {
                        return null;
                    }

                    $entity->loadLinkMultipleField('teams');
                    $userIds = $workflowHelper->getUserIdsByTeamIds($entity->get('teamsIds'));

                    return implode('; ', $workflowHelper->getUsersEmailAddress($userIds));

                case 'followers':
                    if (!$entity) {
                        return null;
                    }

                    $userIds = $workflowHelper->getFollowerUserIds($entity);

                    return implode('; ', $workflowHelper->getUsersEmailAddress($userIds));

                case 'followersExcludingAssignedUser':
                    if (!$entity) {
                        return null;
                    }

                    $userIds = $workflowHelper->getFollowerUserIdsExcludingAssignedUser($entity);

                    return implode('; ', $workflowHelper->getUsersEmailAddress($userIds));

                case 'system':
                    return $this->config->get('outboundEmailFromAddress');

                case 'specifiedUsers':
                    return implode('; ', $workflowHelper->getUsersEmailAddress($data->entityIds));

                case 'specifiedContacts':
                    return implode('; ', $workflowHelper->getEmailAddressesForEntity('Contact', $data->entityIds));
            }
        }

        if ($entity instanceof Entity && $entity->hasAttribute('emailAddress')) {
            return $entity->get('emailAddress');
        }

        if (
            isset($data->type) &&
            isset($entityType) &&
            isset($data->entityIds) &&
            is_array($data->entityIds)
        ) {
            return implode('; ', $workflowHelper->getEmailAddressesForEntity($entityType, $data->entityIds));
        }

        return null;
    }

    private function applyTrackingUrlsToEmailBody(string $body, string $toEmailAddress): string
    {
        $siteUrl = $this->config->get('siteUrl');

        if (!str_contains($body, '{trackingUrl:')) {
            return $body;
        }

        $hash = $this->hasher->hash($toEmailAddress);

        preg_match_all('/\{trackingUrl:(.*?)}/', $body, $matches);

        /** @phpstan-ignore-next-line */
        if (!$matches || !count($matches)) {
            return $body;
        }

        foreach ($matches[0] as $item) {
            $id = explode(':', trim($item, '{}'), 2)[1] ?? null;

            if (!$id) {
                continue;
            }

            if (strpos($id, '.')) {
                [$id, $uid] = explode('.', $id);

                $uidHash = $this->hasher->hash($uid);

                $url = "$siteUrl?entryPoint=campaignUrl&id=$id&uid=$uid&hash=$uidHash";
            } else {
                $url = "$siteUrl?entryPoint=campaignUrl&id=$id&emailAddress=$toEmailAddress&hash=$hash";
            }

            $body = str_replace($item, $url, $body);
        }

        return $body;
    }

    /**
     * @throws Error
     * @throws NoSmtp
     */
    private function getUserSmtpParams(string $emailAddress, string $userId): ?SmtpParams
    {
        $user = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($userId);

        if (!$user || !$user->isActive()) {
            return null;
        }

        $emailAccount = $this->entityManager
            ->getRDBRepositoryByClass(EmailAccount::class)
            ->where([
                'emailAddress' => $emailAddress,
                'assignedUserId' => $userId,
                'useSmtp' => true,
                'status' => EmailAccount::STATUS_ACTIVE,
            ])
            ->findOne();

        if (!$emailAccount) {
            return null;
        }

        $factory = $this->injectableFactory->create(PersonalAccountFactory::class);

        $params = $factory->create($emailAccount->getId())
            ->getSmtpParams();

        if (!$params) {
            return null;
        }

        return $params->withFromName($user->getName());
    }

    /**
     * @throws Error
     * @throws NoSmtp
     */
    private function getGroupSmtpParams(string $emailAddress): ?SmtpParams
    {
        $inboundEmail = $this->entityManager
            ->getRDBRepositoryByClass(InboundEmail::class)
            ->where([
                'status' => InboundEmail::STATUS_ACTIVE,
                'useSmtp' => true,
                'smtpHost!=' => null,
                'emailAddress' => $emailAddress,
            ])
            ->findOne();

        if (!$inboundEmail) {
            return null;
        }

        return $this->injectableFactory
            ->create(GroupAccountFactory::class)
            ->create($inboundEmail->getId())
            ->getSmtpParams();
    }

    /**
     * @param Result $templateResult
     * @param string[] $attachmentIds
     * @return Attachment[]
     */
    private function getAttachmentList(Result $templateResult, array $attachmentIds): array
    {
        $attachmentList = [];

        foreach (array_merge($templateResult->getAttachmentIdList(), $attachmentIds) as $attachmentId) {
            $attachment = $this->entityManager
                ->getRDBRepositoryByClass(Attachment::class)
                ->getById($attachmentId);

            if ($attachment) {
                $attachmentList[] = $attachment;
            }
        }

        return $attachmentList;
    }

    private function storeEmail(Email $email, stdClass $data): void
    {
        $processId = $data->processId ?? null;
        $emailTemplateId = $data->emailTemplateId ?? null;

        $teamsIds = [];

        if ($processId) {
            $process = $this->entityManager
                ->getRDBRepositoryByClass(BpmnProcessEntity::class)
                ->getById($processId);

            if ($process) {
                $teamsIds = $process->getLinkMultipleIdList('teams');
            }
        } else if ($emailTemplateId) {
            $emailTemplate = $this->entityManager
                ->getRDBRepositoryByClass(EmailTemplate::class)
                ->getById($emailTemplateId);

            if ($emailTemplate) {
                $teamsIds = $emailTemplate->getLinkMultipleIdList('teams');
            }
        }

        if (count($teamsIds)) {
            $email->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($email, ['createdById' => 'system']);
    }

    /**
     * @throws Error
     * @throws NoSmtp
     */
    private function prepareSmtpParams(stdClass $data, string $fromEmailAddress): ?SmtpParams
    {
        if (
            isset($data->from->entityType) &&
            $data->from->entityType === User::ENTITY_TYPE &&
            isset($data->from->entityId)
        ) {
            return $this->getUserSmtpParams($fromEmailAddress, $data->from->entityId);
        }

        if (isset($data->from->email)) {
            return $this->getGroupSmtpParams($fromEmailAddress);
        }

        return null;
    }

    private function getEmailTemplate(stdClass $data): EmailTemplate
    {
        $emailTemplateId = $data->emailTemplateId ?? null;

        if (!$emailTemplateId) {
            throw new RuntimeException("No email template.");
        }

        $emailTemplate = $this->entityManager
            ->getRDBRepositoryByClass(EmailTemplate::class)
            ->getById($emailTemplateId);

        if (!$emailTemplate) {
            throw new RuntimeException("Email template $emailTemplateId not found.");
        }

        return $emailTemplate;
    }

    /**
     * @param array<string, Entity> $entityHash
     * @return Result
     */
    private function getTemplateResult(
        stdClass $data,
        array $entityHash,
        string $toEmailAddress,
        Entity $entity
    ): Result {

        $emailTemplate = $this->getEmailTemplate($data);

        $emailTemplateData = EmailTemplateData::create()
            ->withEntityHash($entityHash)
            ->withEmailAddress($toEmailAddress)
            ->withParentId($entity->getId())
            ->withParentType($entity->getEntityType());

        if (
            $entity->hasAttribute('parentId') &&
            $entity->hasAttribute('parentType')
        ) {
            $emailTemplateData = $emailTemplateData
                ->withRelatedId($entity->get('parentId'))
                ->withRelatedType($entity->get('parentType'));
        }

        return $this->emailTemplateProcessor->process(
            $emailTemplate,
            EmailTemplateParams::create()->withCopyAttachments(),
            $emailTemplateData
        );
    }

    private function applyOptOutLink(
        string $toEmailAddress,
        string $body,
        Result $templateResult,
        Sender $sender,
    ): string {

        $siteUrl = $this->config->get('siteUrl');

        $hash = $this->hasher->hash($toEmailAddress);

        $optOutUrl = "$siteUrl?entryPoint=unsubscribe&emailAddress=$toEmailAddress&hash=$hash";

        $optOutLink = "<a href=\"$optOutUrl\">" .
            "{$this->defaultLanguage->translateLabel('Unsubscribe', 'labels', 'Campaign')}</a>";

        $body = str_replace('{optOutUrl}', $optOutUrl, $body);
        $body = str_replace('{optOutLink}', $optOutLink, $body);

        if (stripos($body, '?entryPoint=unsubscribe') === false) {
            if ($templateResult->isHtml()) {
                $body .= "<br><br>" . $optOutLink;
            } else {
                $body .= "\n\n" . $optOutUrl;
            }
        }

        if (method_exists($sender, 'withAddedHeader')) { /** @phpstan-ignore-line */
            $sender->withAddedHeader('List-Unsubscribe', '<' . $optOutUrl . '>');
        } else {
            $message = new Message();
            $message->getHeaders()->addHeaderLine('List-Unsubscribe', '<' . $optOutUrl . '>');

            $sender->withMessage($message);
        }

        return $body;
    }

    /**
     * @param Result $templateResult
     * @param object{variables?: stdClass, optOutLink?: bool}&stdClass $data
     * @return array{?string, ?string}
     */
    private function prepareSubjectBody(
        Result $templateResult,
        stdClass $data,
        string $toEmailAddress,
        Sender $sender
    ): array {

        $subject = $templateResult->getSubject();
        $body = $templateResult->getBody();

        if (isset($data->variables)) {
            foreach (get_object_vars($data->variables) as $key => $value) {
                if (!is_string($value) && !is_int($value) && !is_float($value)) {
                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $value = strval($value);
                } else if (!$value) {
                    continue;
                }

                $subject = str_replace('{$$' . $key . '}', $value, $subject);
                $body = str_replace('{$$' . $key . '}', $value, $body);
            }
        }

        $body = $this->applyTrackingUrlsToEmailBody($body, $toEmailAddress);

        if ($data->optOutLink ?? false) {
            $body = $this->applyOptOutLink($toEmailAddress, $body, $templateResult, $sender);
        }

        return [$subject, $body];
    }
}
