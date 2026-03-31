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

namespace Espo\Modules\Advanced\Classes\ServiceActions\Person;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\EmailAddress;
use Espo\Modules\Advanced\Tools\Workflow\Action\RunAction\ServiceAction;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Repositories\EmailAddress as EmailAddressRepository;

/**
 * @implements ServiceAction<CoreEntity>
 */
class OptOut implements ServiceAction
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @inheritDoc
     * @noinspection PhpHierarchyChecksInspection
     * @noinspection PhpUndefinedClassInspection
     * @noinspection PhpSignatureMismatchDuringInheritanceInspection
     */
    public function run(Entity $entity, mixed $data): mixed
    {
        $targetListId = $data->targetListId ?? null;

        if ($targetListId) {
            $this->entityManager
                ->getRDBRepository($entity->getEntityType())
                ->getRelation($entity, 'targetLists')
                ->updateColumnsById($targetListId, ['optedOut' => true]);

            return null;
        }

        $emailAddress = $entity->get('emailAddress');

        if (!$emailAddress) {
            return null;
        }

        /** @var EmailAddressRepository $emailAddressRepository */
        $emailAddressRepository = $this->entityManager->getRepository(EmailAddress::ENTITY_TYPE);

        $addressEntity = $emailAddressRepository->getByAddress($emailAddress);

        if ($addressEntity) {
            $addressEntity->set('optOut', true);
            $this->entityManager->saveEntity($addressEntity);
        }

        return null;
    }
}
