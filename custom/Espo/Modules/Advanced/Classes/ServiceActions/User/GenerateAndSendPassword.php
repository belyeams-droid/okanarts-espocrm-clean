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

namespace Espo\Modules\Advanced\Classes\ServiceActions\User;

use Espo\Core\InjectableFactory;
use Espo\Core\Record\ServiceContainer;
use Espo\Entities\User;
use Espo\Modules\Advanced\Tools\Workflow\Action\RunAction\ServiceAction;
use Espo\ORM\Entity;
use Espo\Tools\UserSecurity\Password\Service;

/**
 * @implements ServiceAction<User>
 */
class GenerateAndSendPassword implements ServiceAction
{
    private InjectableFactory $injectableFactory;
    private ServiceContainer $serviceContainer;

    public function __construct(
        InjectableFactory $injectableFactory,
        ServiceContainer $serviceContainer
    ) {
        $this->injectableFactory = $injectableFactory;
        $this->serviceContainer = $serviceContainer;
    }

    /**
     * @inheritDoc
     * @noinspection PhpHierarchyChecksInspection
     * @noinspection PhpUndefinedClassInspection
     * @noinspection PhpSignatureMismatchDuringInheritanceInspection
     */
    public function run(Entity $entity, mixed $data): mixed
    {
        if (class_exists("Espo\\Tools\\UserSecurity\\Password\\Service")) {
            /** @var Service $service */
            $service = $this->injectableFactory->create("Espo\\Tools\\UserSecurity\\Password\\Service");

            // @todo Support non-admin users.

            $service->generateAndSendNewPasswordForUser($entity->getId());

            return null;
        }

        $service = $this->serviceContainer->get(User::ENTITY_TYPE);

        if (method_exists($service, 'generateNewPasswordForUser')) {
            $service->generateNewPasswordForUser($entity->getId(), true);
        }

        return null;
    }
}
