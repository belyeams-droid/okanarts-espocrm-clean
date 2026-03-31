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

namespace Espo\Modules\Advanced\Classes\Select\Report\AccessControlFilters;

use Espo\Core\Acl\Table;
use Espo\Core\AclManager;
use Espo\Core\Select\AccessControl\Filter;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

class Mandatory implements Filter
{
    public function __construct(
        private User $user,
        private Metadata $metadata,
        private AclManager $aclManager
    ) {}

    public function apply(QueryBuilder $queryBuilder): void
    {
        if (!$this->user->getPortalId()) {
            $forbiddenEntityTypeList = [];

            $scopes = $this->metadata->get('scopes', []);

            foreach ($scopes as $scope => $defs) {
                if (empty($defs['entity'])) {
                    continue;
                }

                if ($this->aclManager->checkScope($this->user, $scope, Table::ACTION_READ)) {
                    continue;
                }

                $forbiddenEntityTypeList[] = $scope;
            }

            if ($forbiddenEntityTypeList !== []) {
                $queryBuilder->where(['entityType!=' => $forbiddenEntityTypeList]);
            }

            return;
        }

        $queryBuilder
            ->distinct()
            ->leftJoin('portals', 'portalsAccess')
            ->where(['portalsAccess.id' => $this->user->getPortalId()]);
    }
}
