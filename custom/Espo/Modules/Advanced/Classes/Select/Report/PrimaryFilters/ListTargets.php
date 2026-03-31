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

namespace Espo\Modules\Advanced\Classes\Select\Report\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Core\Templates\Entities\Company;
use Espo\Core\Templates\Entities\Person;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Crm\Entities\Account;
use Espo\Modules\Crm\Entities\Contact;
use Espo\Modules\Crm\Entities\Lead;
use Espo\Modules\Crm\Entities\TargetList;
use Espo\ORM\Entity;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

class ListTargets implements Filter
{
    private Metadata $metadata;

    public function __construct(Metadata $metadata) {
        $this->metadata = $metadata;
    }

    public function apply(QueryBuilder $queryBuilder): void
    {
        $entityTypeList = [
            Contact::ENTITY_TYPE,
            Lead::ENTITY_TYPE,
            User::ENTITY_TYPE,
            Account::ENTITY_TYPE,
        ];

        foreach ($this->metadata->get(['entityDefs', TargetList::ENTITY_TYPE, 'links']) as $defs) {
            $entityType = $defs['entity'] ?? null;
            $type = $defs['type'] ?? null;

            if ($type !== Entity::HAS_MANY) {
                continue;
            }

            if (!$entityType) {
                continue;
            }

            if (in_array($entityType, $entityTypeList)) {
                continue;
            }

            $templateType = $this->metadata->get(['scopes', $entityType, 'type']);

            if (
                $templateType !== Person::TEMPLATE_TYPE &&
                $templateType !== Company::TEMPLATE_TYPE
            ) {
                continue;
            }

            $entityTypeList[] = $entityType;
        }

        $queryBuilder->where([
            'type' => Report::TYPE_LIST,
            'entityType' => $entityTypeList,
        ]);
    }
}
