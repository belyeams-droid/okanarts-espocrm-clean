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

use Espo\Core\Formula\Exceptions\Error;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\Advanced\Tools\Workflow\Core\SaveContextHelper;
use stdClass;

class ExecuteFormula extends BaseEntity
{
    /**
     * @throws Error
     */
    protected function run(CoreEntity $entity, stdClass $actionData, array $options): bool
    {
        if (empty($actionData->formula)) {
            return true;
        }

        $reloadedEntity = $this->entityManager->getEntityById($entity->getEntityType(), $entity->getId());

        $variables = $this->getFormulaVariables();

        $this->formulaManager->run($actionData->formula, $reloadedEntity, $variables);

        $this->updateVariables($variables);

        if (!$reloadedEntity) {
            // Can be removed.
            return true;
        }

        $isChanged = false;

        $changedMap = (object) [];

        foreach ($reloadedEntity->getAttributeList() as $attribute) {
            if ($reloadedEntity->isAttributeChanged($attribute)) {
                $changedMap->$attribute = $reloadedEntity->get($attribute);

                $isChanged = true;
            }
        }

        if (!$isChanged) {
            return true;
        }

        $saveOptions = [
            'modifiedById' => 'system',
            'skipWorkflow' => !$this->bpmnProcess,
            'workflowId' => $this->getWorkflowId(),
            'context' => SaveContextHelper::createDerived($options),
        ];

        $this->entityManager->saveEntity($reloadedEntity, $saveOptions);

        $entity->set($changedMap);

        return true;
    }
}
