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

namespace Espo\Modules\Advanced\Classes\RecordHooks\BpmnUserTask;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\UpdateHook;
use Espo\Core\Record\UpdateParams;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\BpmnUserTask;
use Espo\ORM\Entity;

/**
 * @implements UpdateHook<BpmnUserTask>
 */
class BeforeUpdate implements UpdateHook
{
    private User $user;

    public function __construct(
        User $user
    ) {
        $this->user = $user;
    }

    public function process(Entity $entity, UpdateParams $params): void
    {
        if (!$this->user->isAdmin()) {
            if ($entity->getFetched('isResolved') && $entity->isAttributeChanged('resolution')) {
                throw new Forbidden("Can't change resolution. Already resolved.");
            }

            if ($entity->getFetched('isCanceled') && $entity->isAttributeChanged('resolution')) {
                throw new Forbidden("Can't change resolution. Already canceled.");
            }
        }
    }
}
