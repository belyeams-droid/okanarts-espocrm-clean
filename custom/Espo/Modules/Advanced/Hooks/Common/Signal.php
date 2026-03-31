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

namespace Espo\Modules\Advanced\Hooks\Common;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Entities\EmailAddress;
use Espo\Entities\LeadCapture;
use Espo\Entities\Note;
use Espo\Entities\Notification;
use Espo\Entities\PhoneNumber;
use Espo\Modules\Advanced\Core\SignalManager;
use Espo\Modules\Crm\Entities\Meeting;
use Espo\ORM\Entity;

/**
 * @noinspection PhpUnused
 */
class Signal
{
    /** @var int */
    public static $order = 100;

    /** @var string[] */
    private $ignoreEntityTypeList = [
        Notification::ENTITY_TYPE,
        EmailAddress::ENTITY_TYPE,
        PhoneNumber::ENTITY_TYPE,
    ];

    /** @var string[] */
    private $ignoreRegularEntityTypeList = [
        Note::ENTITY_TYPE,
    ];

    public function __construct(
        private Metadata $metadata,
        private Config $config,
        private SignalManager $signalManager
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if ($this->toSkipSignal($options)) {
            return;
        }

        if ($this->config->get('signalCrudHooksDisabled')) {
            return;
        }

        if (in_array($entity->getEntityType(), $this->ignoreEntityTypeList)) {
            return;
        }

        if (!$entity instanceof CoreEntity) {
            return;
        }

        $ignoreRegular = in_array($entity->getEntityType(), $this->ignoreRegularEntityTypeList);

        $signalManager = $this->signalManager;

        if ($entity->isNew()) {
            $signalManager->trigger('@create', $entity, $options);

            if (!$ignoreRegular) {
                $signalManager->trigger(['create', $entity->getEntityType()]);
            }

            if (
                $entity->getEntityType() === Note::ENTITY_TYPE &&
                $entity->get('type') === Note::TYPE_POST
            ) {
                $parentId = $entity->get('parentId');
                $parentType = $entity->get('parentType');

                if ($parentType && $parentId) {
                    $signalManager->trigger([
                        'streamPost',
                        $parentType,
                        $parentId
                    ]);
                }
            }
        } else {
            $signalManager->trigger('@update', $entity, $options);

            if (!$ignoreRegular) {
                $signalManager->trigger([
                    'update',
                    $entity->getEntityType(),
                    $entity->getId()
                ]);
            }
        }

        if ($ignoreRegular) {
            return;
        }

        foreach ($entity->getRelationList() as $relation) {
            $type = $entity->getRelationType($relation);

            if ($type === Entity::BELONGS_TO_PARENT && $entity->isNew()) {
                $parentId = $entity->get($relation . 'Id');
                $parentType = $entity->get($relation . 'Type');

                if (!$parentType || !$parentId) {
                    continue;
                }

                if (!$this->metadata->get(['scopes', $parentType, 'object'])) {
                    continue;
                }

                $signalManager->trigger([
                    'createChild',
                    $parentType,
                    $parentId,
                    $entity->getEntityType()
                ]);

                continue;
            }

            if ($type === Entity::BELONGS_TO) {
                $idAttribute = $relation . 'Id';
                $idValue = $entity->get($idAttribute);

                if (!$entity->isNew()) {
                    if (!$entity->isAttributeChanged($idAttribute)) {
                        continue;
                    }
                } else if (!$idValue) {
                    continue;
                }

                $foreignEntityType = $entity->getRelationParam($relation, 'entity');
                $foreign = $entity->getRelationParam($relation, 'foreign');

                if (!$foreignEntityType) {
                    continue;
                }

                if (!$foreign) {
                    continue;
                }

                if (in_array($foreignEntityType, ['User', 'Team'])) {
                    continue;
                }

                if (!$this->metadata->get(['scopes', $foreignEntityType, 'object'])) {
                    continue;
                }

                if ($entity->isNew()) {
                    $signalManager->trigger([
                        'createRelated',
                        $foreignEntityType,
                        $idValue,
                        $foreign
                    ]);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function afterRemove(Entity $entity, array $options): void
    {
        if ($this->toSkipSignal($options)) {
            return;
        }

        if ($this->config->get('signalCrudHooksDisabled')) {
            return;
        }

        if (in_array($entity->getEntityType(), $this->ignoreEntityTypeList)) {
            return;
        }

        $ignoreRegular = in_array($entity->getEntityType(), $this->ignoreRegularEntityTypeList);

        $signalManager = $this->signalManager;

        $signalManager->trigger('@delete', $entity, $options);

        if (!$ignoreRegular) {
            $signalManager->trigger([
                'delete',
                $entity->getEntityType(),
                $entity->getId()
            ]);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $hookData
     */
    public function afterRelate(Entity $entity, array $options, array $hookData): void
    {
        if ($this->toSkipSignal($options)) {
            return;
        }

        if ($this->config->get('signalCrudHooksDisabled')) {
            return;
        }

        if (in_array($entity->getEntityType(), $this->ignoreEntityTypeList)) {
            return;
        }

        if (!$entity instanceof CoreEntity) {
            return;
        }

        $ignoreRegular = in_array($entity->getEntityType(), $this->ignoreRegularEntityTypeList);

        if ($entity->isNew()) {
            return;
        }

        $signalManager = $this->signalManager;

        $foreign = $hookData['foreignEntity'] ?? null;
        $link = $hookData['relationName'] ?? null;

        if (!$foreign || !$link) {
            return;
        }

        $foreignId = $foreign->getId();

        $relationType = $entity->getRelationParam($link, 'type');

        if ($relationType !== Entity::MANY_MANY) {
            $ignoreRegular = true;
        }

        $signalManager->trigger(['@relate', $link, $foreignId], $entity, $options);
        $signalManager->trigger(['@relate', $link], $entity, $options, ['id' => $foreignId]);

        if (!$ignoreRegular) {
            $signalManager->trigger([
                'relate',
                $entity->getEntityType(),
                $entity->getId(),
                $link,
                $foreignId
            ]);

            $signalManager->trigger([
                'relate',
                $entity->getEntityType(),
                $entity->getId(),
                $link
            ]);
        }

        $foreignLink = $entity->getRelationParam($link, 'foreign');

        if (!$foreignLink) {
            return;
        }

        $signalManager->trigger(['@relate', $foreignLink, $entity->getId()], $foreign);
        $signalManager->trigger(['@relate', $foreignLink], $foreign, [], ['id' => $entity->getId()]);

        if ($ignoreRegular) {
            return;
        }

        $signalManager->trigger([
            'relate',
            $foreign->getEntityType(),
            $foreign->getId(),
            $foreignLink,
            $entity->getId()
        ]);

        $signalManager->trigger([
            'relate',
            $foreign->getEntityType(),
            $foreign->getId(),
            $foreignLink
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $hookData
     */
    public function afterUnrelate(Entity $entity, array $options, array $hookData): void
    {
        if ($this->toSkipSignal($options)) {
            return;
        }

        if ($this->config->get('signalCrudHooksDisabled')) {
            return;
        }

        if (in_array($entity->getEntityType(), $this->ignoreEntityTypeList)) {
            return;
        }

        if (!$entity instanceof CoreEntity) {
            return;
        }

        $ignoreRegular = in_array($entity->getEntityType(), $this->ignoreRegularEntityTypeList);

        if ($entity->isNew()) {
            return;
        }

        $signalManager = $this->signalManager;

        $foreign = $hookData['foreignEntity'] ?? null;
        $link = $hookData['relationName'] ?? null;

        if (!$foreign || !$link) {
            return;
        }

        $foreignId = $foreign->getId();

        $relationType = $entity->getRelationParam($link, 'type');

        if ($relationType !== Entity::MANY_MANY) {
            $ignoreRegular = true;
        }

        $signalManager->trigger(['@unrelate', $link, $foreignId], $entity, $options);
        $signalManager->trigger(['@unrelate', $link], $entity, $options, ['id' => $foreignId]);

        if (!$ignoreRegular) {
            $signalManager->trigger([
                'unrelate',
                $entity->getEntityType(),
                $entity->getId(),
                $link,
                $foreignId
            ]);

            $signalManager->trigger([
                'unrelate',
                $entity->getEntityType(),
                $entity->getId(),
                $link
            ]);
        }

        $foreignLink = $entity->getRelationParam($link, 'foreign');

        if (!$foreignLink) {
            return;
        }

        $signalManager->trigger(['@unrelate', $foreignLink, $entity->getId()], $foreign);
        $signalManager->trigger(['@unrelate', $foreignLink], $foreign, [], ['id' => $entity->getId()]);

        $signalManager->trigger([
            'unrelate',
            $foreign->getEntityType(),
            $foreign->getId(),
            $foreignLink,
            $entity->getId()
        ]);

        $signalManager->trigger([
            'unrelate',
            $foreign->getEntityType(),
            $foreign->getId(),
            $foreignLink
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $hookData
     * @noinspection PhpUnused
     */
    public function afterMassRelate(Entity $entity, array $options, array $hookData): void
    {
        if ($this->toSkipSignal($options)) {
            return;
        }

        if ($this->config->get('signalCrudHooksDisabled')) {
            return;
        }

        if (in_array($entity->getEntityType(), $this->ignoreEntityTypeList)) {
            return;
        }

        $link = $hookData['relationName'] ?? null;

        if (!$link) {
            return;
        }

        $signalManager = $this->signalManager;

        $signalManager->trigger(['@relate', $link], $entity, $options);

        $signalManager->trigger([
            'relate',
            $entity->getEntityType(),
            $entity->getId(),
            $link
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $hookData
     * @noinspection PhpUnused
     */
    public function afterLeadCapture(Entity $entity, array $options, array $hookData): void
    {
        if ($this->toSkipSignal($options)) {
            return;
        }

        if ($entity->getEntityType() === LeadCapture::ENTITY_TYPE) {
            return;
        }

        $id = $hookData['leadCaptureId'];

        $signalManager = $this->signalManager;

        $signalManager->trigger(['@leadCapture', $id], $entity);
        $signalManager->trigger(['@leadCapture'], $entity);

        $signalManager->trigger([
            'leadCapture',
            $entity->getEntityType(),
            $entity->getId(),
            $id
        ]);

        $signalManager->trigger([
            'leadCapture',
            $entity->getEntityType(),
            $entity->getId()
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $hookData
     * @noinspection PhpUnused
     */
    public function afterConfirmation(Entity $entity, array $options, array $hookData): void
    {
        if ($this->toSkipSignal($options)) {
            return;
        }

        $eventEntityType = $entity->getEntityType();
        $eventId = $entity->getId();
        $status = $hookData['status'];
        $entityType = $hookData['inviteeType'];
        $id = $hookData['inviteeId'];

        $signalManager = $this->signalManager;

        if ($status === Meeting::ATTENDEE_STATUS_ACCEPTED) {
            $signalManager->trigger(['@eventAccepted', $entityType], $entity, [], ['id' => $id]);

            $signalManager->trigger([
                'eventAccepted',
                $entityType,
                $id,
                $eventEntityType,
                $eventId
            ]);

            $signalManager->trigger([
                'eventAccepted',
                $entityType,
                $id,
                $eventEntityType
            ]);
        }

        if ($status === Meeting::ATTENDEE_STATUS_TENTATIVE) {
            $signalManager->trigger(['@eventTentative', $entityType], $entity, [], ['id' => $id]);

            $signalManager->trigger([
                'eventTentative',
                $entityType,
                $id,
                $eventEntityType,
                $eventId
            ]);

            $signalManager->trigger([
                'eventTentative',
                $entityType,
                $id,
                $eventEntityType
            ]);
        }

        if ($status === Meeting::ATTENDEE_STATUS_DECLINED) {
            $signalManager->trigger(['@eventDeclined', $entityType], $entity, [], ['id' => $id]);

            $signalManager->trigger([
                'eventDeclined',
                $entityType,
                $id,
                $eventEntityType,
                $eventId
            ]);

            $signalManager->trigger([
                'eventDeclined',
                $entityType,
                $id,
                $eventEntityType
            ]);
        }

        if (
            $status === Meeting::ATTENDEE_STATUS_ACCEPTED ||
            $status === Meeting::ATTENDEE_STATUS_TENTATIVE
        ) {
            $signalManager->trigger(['@eventAcceptedTentative', $entityType], $entity, [], ['id' => $id]);

            $signalManager->trigger([
                'eventAcceptedTentative',
                $entityType,
                $id,
                $eventEntityType,
                $eventId
            ]);

            $signalManager->trigger([
                'eventAcceptedTentative',
                $entityType,
                $id,
                $eventEntityType
            ]);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $hookData
     * @noinspection PhpUnused
     * @noinspection PhpUnusedParameterInspection
     */
    public function afterOptOut(Entity $entity, array $options, array $hookData): void
    {
        if ($this->toSkipSignal($options)) {
            return;
        }

        $signalManager = $this->signalManager;

        $signalManager->trigger(['@optOut'], $entity);

        $signalManager->trigger([
            'optOut',
            $entity->getEntityType(),
            $entity->getId()
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $hookData
     * @noinspection PhpUnused
     * @noinspection PhpUnusedParameterInspection
     */
    public function afterCancelOptOut(Entity $entity, array $options, array $hookData): void
    {
        if ($this->toSkipSignal($options)) {
            return;
        }

        $signalManager = $this->signalManager;

        $signalManager->trigger(['@cancelOptOut'], $entity);

        $signalManager->trigger([
            'cancelOptOut',
            $entity->getEntityType(),
            $entity->getId()
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function toSkipSignal(array $options): bool
    {
        return !empty($options['skipWorkflow']) ||
            !empty($options['skipSignal']) ||
            !empty($options['silent']);
    }
}
