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

namespace Espo\Modules\Advanced\Classes\ServiceActions\Meeting;

use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Workflow\Action\RunAction\ServiceAction;
use Espo\Modules\Crm\Business\Event\Invitations;
use Espo\Modules\Crm\Entities\Call;
use Espo\Modules\Crm\Entities\Meeting;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;

/**
 * @implements ServiceAction<Meeting|Call>
 */
class SendInvitations implements ServiceAction
{
    private InjectableFactory $injectableFactory;
    private EntityManager $entityManager;
    private User $user;

    public function __construct(
        InjectableFactory $injectableFactory,
        EntityManager $entityManager,
        User $user
    ) {
        $this->injectableFactory = $injectableFactory;
        $this->entityManager = $entityManager;
        $this->user = $user;
    }

    private function getInvitationManager(): Invitations
    {
        return $this->injectableFactory->create(Invitations::class);
    }

    /**
     * @inheritDoc
     */
    public function run(Entity $entity, mixed $data): mixed
    {
        if (!$entity instanceof CoreEntity) {
            throw new RuntimeException();
        }

        $invitationManager = $this->getInvitationManager();
        $emailHash = [];

        $users = $this->entityManager
            ->getRDBRepository($entity->getEntityType())
            ->getRelation($entity, 'users')
            ->find();

        foreach ($users as $user) {
            if ($user->getId() === $this->user->getId()) {
                if (
                    $entity->getLinkMultipleColumn('users', 'status', $user->getId()) ===
                    Meeting::ATTENDEE_STATUS_ACCEPTED
                ) {
                    continue;
                }
            }

            if ($user->get('emailAddress') && !array_key_exists($user->get('emailAddress'), $emailHash)) {
                $invitationManager->sendInvitation($entity, $user, 'users');
                $emailHash[$user->get('emailAddress')] = true;
            }
        }

        $contacts = $this->entityManager
            ->getRDBRepository($entity->getEntityType())
            ->getRelation($entity, 'contacts')
            ->find();

        foreach ($contacts as $contact) {
            if ($contact->get('emailAddress') && !array_key_exists($contact->get('emailAddress'), $emailHash)) {
                $invitationManager->sendInvitation($entity, $contact, 'contacts');
                $emailHash[$contact->get('emailAddress')] = true;
            }
        }

        $leads = $this->entityManager
            ->getRDBRepository($entity->getEntityType())
            ->getRelation($entity, 'leads')
            ->find();

        foreach ($leads as $lead) {
            if ($lead->get('emailAddress') && !array_key_exists($lead->get('emailAddress'), $emailHash)) {
                $invitationManager->sendInvitation($entity, $lead, 'leads');
                $emailHash[$lead->get('emailAddress')] = true;
            }
        }

        return null;
    }
}
