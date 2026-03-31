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

namespace Espo\Modules\Advanced\Core\Workflow\Formula\Functions\ReportGroup;

use Espo\Core\Acl\GlobalRestriction;
use Espo\Core\AclManager;
use Espo\Core\Formula\EvaluatedArgumentList;
use Espo\Core\Formula\Exceptions\BadArgumentType;
use Espo\Core\Formula\Exceptions\BadArgumentValue;
use Espo\Core\Formula\Exceptions\TooFewArguments;
use Espo\Core\Formula\Func;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

class RecordAttribute implements Func
{
    /** @var string[] */
    private array $forbiddenEntityTypeList = [
        User::ENTITY_TYPE,
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private AclManager $aclManager
    ) {}

    /**
     * @inheritDoc
     */
    public function process(EvaluatedArgumentList $arguments): mixed
    {
        if (count($arguments) < 3) {
            throw TooFewArguments::create(3);
        }

        $entityType = $arguments[0];
        $id = $arguments[1];
        $attribute = $arguments[2];

        if (!is_string($entityType)) {
            throw BadArgumentType::create(1, 'string');
        }

        if (!is_string($id)) {
            throw BadArgumentType::create(2, 'string');
        }

        if (!is_string($attribute)) {
            throw BadArgumentType::create(3, 'string');
        }

        if (
            in_array($entityType, $this->forbiddenEntityTypeList) ||
            !$this->metadata->get("scopes.$entityType.object")
        ) {
            throw BadArgumentValue::create(1, "Not allowed entity type '$entityType'.");
        }

        $fieldType = $this->entityManager
            ->getDefs()
            ->getEntity($entityType)
            ->tryGetField($attribute)
            ?->getType();

        if (
            $fieldType === 'password' ||
            in_array($attribute, $this->aclManager->getScopeRestrictedAttributeList($entityType, [
                GlobalRestriction::TYPE_FORBIDDEN,
                GlobalRestriction::TYPE_INTERNAL,
                GlobalRestriction::TYPE_ONLY_ADMIN,
            ]))
        ) {
            throw BadArgumentValue::create(3, "Not allowed attribute '$attribute'.");
        }

        $entity = $this->entityManager->getEntityById($entityType, $id);

        return $entity?->get($attribute);
    }
}
