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

use Espo\Core\Acl;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Advanced\Controllers\WorkflowLogRecord;
use Espo\Modules\Advanced\Core\WorkflowManager;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\DynamicLogic\ConditionCheckerFactory;
use Espo\Tools\DynamicLogic\Exceptions\BadCondition;
use Espo\Tools\DynamicLogic\Item as LogicItem;
use RuntimeException;
use stdClass;

class Service
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private WorkflowManager $workflowManager,
        private Log $log,
        private InjectableFactory $injectableFactory,
        private ServiceContainer $serviceContainer,
    ) {}

    /**
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function runManual(string $id, string $targetId): TriggerResult
    {
        $workflow = $this->getManualWorkflow($id);
        $entity = $this->getEntityForManualWorkflow($workflow, $targetId);

        $this->processManualWorkflowAccess($workflow, $entity);
        $this->processCheckManualWorkflowConditions($workflow, $entity);

        try {
            $result = $this->triggerWorkflow($entity, $workflow->getId(), true);
        } catch (FormulaError $e) {
            throw new Error("Formula error.", 500, $e);
        }

        if (!$result) {
            throw new RuntimeException("No result.");
        }

        return $result;
    }

    /**
     * @throws FormulaError
     * @throws Error
     */
    public function triggerWorkflow(Entity $entity, string $workflowId, bool $mandatory = false): ?TriggerResult
    {
        /** @var ?Workflow $workflow */
        $workflow = $this->entityManager->getEntityById(Workflow::ENTITY_TYPE, $workflowId);

        if (!$workflow) {
            throw new Error("Workflow $workflowId does not exist.");
        }

        if (!$workflow->isActive()) {
            if (!$mandatory) {
                $this->log->debug("Workflow $workflowId not triggerred as it's not active.");

                return null;
            }

            throw new Error("Workflow $workflowId is not active.");
        }

        if (!$this->workflowManager->checkConditions($workflow, $entity)) {
            $this->log->debug("Workflow $workflowId not triggerred as conditions are not met.");

            return null;
        }

        $workflowLogRecord = $this->entityManager->getNewEntity(WorkflowLogRecord::ENTITY_TYPE);

        $workflowLogRecord->set([
            'workflowId' => $workflowId,
            'targetId' => $entity->getId(),
            'targetType' => $entity->getEntityType()
        ]);

        $this->entityManager->saveEntity($workflowLogRecord);

        $alertObject = new stdClass();
        $variables = ['__alert' => $alertObject];

        $this->workflowManager->runActions($workflow, $entity, $variables);

        return $this->prepareTriggerResult($alertObject);
    }

    /**
     * @throws Forbidden
     * @throws Error
     */
    private function processCheckManualWorkflowConditions(Workflow $workflow, CoreEntity $entity): void
    {
        $conditionGroup = $workflow->getManualDynamicLogicConditionGroup();

        if (
            !$conditionGroup ||
            !class_exists("Espo\\Tools\\DynamicLogic\\ConditionCheckerFactory")
        ) {
            return;
        }

        $conditionCheckerFactory = $this->injectableFactory->create(ConditionCheckerFactory::class);

        $checker = $conditionCheckerFactory->create($entity);

        try {
            $item = LogicItem::fromGroupDefinition($conditionGroup);

            $isTrue = $checker->check($item);
        } catch (BadCondition $e) {
            throw new Error($e->getMessage(), 500, $e);
        }

        if (!$isTrue) {
            throw new Forbidden("Workflow conditions are not met.");
        }
    }

    /**
     * @throws NotFound
     */
    private function getEntityForManualWorkflow(Workflow $workflow, string $targetId): CoreEntity
    {
        $targetEntityType = $workflow->getTargetEntityType();

        $entity = $this->entityManager->getRDBRepository($targetEntityType)->getById($targetId);

        if (!$entity) {
            throw new NotFound();
        }

        $this->serviceContainer->get($targetEntityType)->loadAdditionalFields($entity);

        if (!$entity instanceof CoreEntity) {
            throw new RuntimeException();
        }

        return $entity;
    }

    /**
     * @throws Forbidden
     * @throws NotFound
     */
    private function getManualWorkflow(string $id): Workflow
    {
        $workflow = $this->entityManager->getRDBRepositoryByClass(Workflow::class)->getById($id);

        if (!$workflow) {
            throw new NotFound("Workflow $id not found.");
        }

        if ($workflow->getType() !== Workflow::TYPE_MANUAL) {
            throw new Forbidden();
        }

        return $workflow;
    }

    /**
     * @throws Forbidden
     */
    private function processManualWorkflowAccess(Workflow $workflow, CoreEntity $entity): void
    {
        if ($this->user->isPortal()) {
            throw new Forbidden();
        }

        $accessRequired = $workflow->getManualAccessRequired();

        if ($accessRequired === Workflow::MANUAL_ACCESS_ADMIN) {
            if (!$this->user->isAdmin()) {
                throw new Forbidden("No admin access.");
            }
        } else if ($accessRequired === Workflow::MANUAL_ACCESS_READ) {
            if (!$this->acl->checkEntityRead($entity)) {
                throw new Forbidden("No read access.");
            }
        } else if (!$this->acl->checkEntityEdit($entity)) {
            throw new Forbidden("No edit access.");
        }

        if (!$this->user->isAdmin()) {
            $teamIdList = $workflow->getLinkMultipleIdList('manualTeams');

            if (array_intersect($teamIdList, $this->user->getTeamIdList()) === []) {
                throw new Forbidden("User is not from allowed team.");
            }
        }
    }

    private function prepareTriggerResult(stdClass $alertObject): TriggerResult
    {
        $alert = null;

        if (property_exists($alertObject, 'message') && is_string($alertObject->message)) {
            $alert = new Alert(
                message: $alertObject->message,
                type: $alertObject->type ?? null,
                autoClose: $alertObject->autoClose ?? false,
            );
        }

        return new TriggerResult(
            alert: $alert,
        );
    }
}
