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

namespace Espo\Modules\Advanced\Core;

use Espo\Core\Exceptions\Error;
use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\ORM\Repository\Option\SaveContext;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Core\Workflow\ConditionManager;
use Espo\Modules\Advanced\Core\Workflow\ActionManager;
use Espo\Modules\Advanced\Entities\Workflow;
use Espo\Modules\Advanced\Entities\WorkflowLogRecord;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

use Exception;
use RuntimeException;
use stdClass;

class WorkflowManager
{
    /** @var ?array<string, array<string, array<string, mixed>[]>> */
    private ?array $data = null;

    private string $cacheFile = 'data/cache/advanced/workflows.php';

    /** @var string[] */
    private array $cacheFields = [
        'conditionsAll',
        'conditionsAny',
        'conditionsFormula',
        'actions',
    ];

    public const AFTER_RECORD_SAVED = 'afterRecordSaved';
    public const AFTER_RECORD_CREATED = 'afterRecordCreated';
    public const AFTER_RECORD_UPDATED = 'afterRecordUpdated';

    /** @var string[] */
    private array $entityListToIgnore;

    public function __construct(
        private ConditionManager $conditionManager,
        private ActionManager $actionManager,
        private Log $log,
        private Config $config,
        private User $user,
        private EntityManager $entityManager,
        private FileManager $fileManager,
        Metadata $metadata,
    ) {
        $this->entityListToIgnore = $metadata->get('entityDefs.Workflow.entityListToIgnore') ?? [];
    }

    /**
     * @return ?array<string, mixed>
     */
    private function getData(string $entityType, string $trigger): ?array
    {
        if (!isset($this->data)) {
            $this->loadWorkflows();
        }

        if (!isset($this->data[$trigger])) {
            return null;
        }

        if (!isset($this->data[$trigger][$entityType])) {
            return null;
        }

        $result = $this->data[$trigger][$entityType];

        if ($result && !is_array($result)) {
            $this->log->error("WorkflowManager: Bad data for workflow.");

            return null;
        }

        return $result;
    }

    /**
     * Run a signal workflow.
     *
     * @param Entity $entity An entity.
     * @param string $signal A signal.
     * @param ?array<string, mixed> $params Signal params.
     * @param array<string, mixed> $options Save options.
     * @throws Error
     * @throws FormulaError
     */
    public function processSignal(Entity $entity, string $signal, ?array $params = null, array $options = []): void
    {
        if (!$entity instanceof CoreEntity) {
            throw new RuntimeException();
        }

        $this->processInternal($entity, '$' . $signal, $options, $params);
    }

    /**
     * Run a workflow.
     *
     * @param Entity $entity An entity.
     * @param string $trigger A trigger.
     * @param array<string, mixed> $options Save options.
     * @throws Error
     * @throws FormulaError
     */
    public function process(Entity $entity, string $trigger, array $options = []): void
    {
        if (!$entity instanceof CoreEntity) {
            throw new RuntimeException();
        }

        $this->processInternal($entity, $trigger, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed>|null $signalParams
     * @throws Error
     * @throws FormulaError
     */
    private function processInternal(
        CoreEntity $entity,
        string $trigger,
        array $options = [],
        ?array $signalParams = null
    ): void {

        $entityType = $entity->getEntityType();

        if (in_array($entityType, $this->entityListToIgnore)) {
            return;
        }

        $data = $this->getData($entityType, $trigger);

        if (!$data) {
            return;
        }

        $this->log->debug("WorkflowManager: Start workflow [$trigger] for [$entityType, {$entity->getId()}].");

        $conditionManager = $this->conditionManager;
        $actionManager = $this->actionManager;

        $variables = [];

        if ($signalParams) {
            $variables['__signalParams'] = (object) $signalParams;
        }

        foreach ($data as $workflowData) {
            $workflowId = $workflowData['id'] ?? null;

            if (!$workflowId) {
                continue;
            }

            $this->log->debug("Start workflow rule [$workflowId].");

            if ($workflowData['portalOnly']) {
                if (!$this->user->getPortalId()) {
                    continue;
                }

                if (!empty($workflowData['portalId'])) {
                    if ($this->user->getPortalId() !== $workflowData['portalId']) {
                        continue;
                    }
                }
            }

            if (!empty($options['workflowId']) && $options['workflowId'] === $workflowId) {
                continue;
            }

            $conditionManager->setInitData($workflowId, $entity);

            $result = true;

            if (isset($workflowData['conditionsAll'])) {
                $result &= $conditionManager->checkConditionsAll($workflowData['conditionsAll']);
            }

            if (isset($workflowData['conditionsAny'])) {
                $result &= $conditionManager->checkConditionsAny($workflowData['conditionsAny']);
            }

            if (!empty($workflowData['conditionsFormula'])) {
                $result &= $conditionManager->checkConditionsFormula($workflowData['conditionsFormula'], $variables);
            }

            $this->log->debug("Condition result [$result] for workflow rule [$workflowId].");

            if ($result) {
                $workflowLogRecord = $this->entityManager->getNewEntity(WorkflowLogRecord::ENTITY_TYPE);

                $workflowLogRecord->set([
                    'workflowId' => $workflowId,
                    'targetId' => $entity->getId(),
                    'targetType' => $entity->getEntityType(),
                ]);

                $this->entityManager->saveEntity($workflowLogRecord);
            }

            if ($result && isset($workflowData['actions'])) {
                /** @var stdClass[] $actions */
                $actions = $workflowData['actions'];

                $this->log->debug("Start actions for workflow rule [$workflowId].");

                $actionManager->setInitData($workflowId, $entity);

                try {
                    $actionManager->runActions($actions, $variables, $options);

                    $this->afterActions($actions, $options);
                } catch (Exception $e) {
                    $this->log->notice("Failed action execution for workflow [$workflowId]. {$e->getMessage()}");
                }

                $this->log->debug("End running actions for workflow rule [$workflowId].");
            }

            $this->log->debug("End workflow rule [$workflowId].");
        }

        $this->log->debug("End workflow [$trigger] for [$entityType, {$entity->get('id')}].");
    }

    /**
     * @throws FormulaError
     * @throws Error
     */
    public function checkConditions(Workflow $workflow, Entity $entity): bool
    {
        if (!$entity instanceof CoreEntity) {
            throw new RuntimeException();
        }

        $result = true;

        $conditionsAll = $workflow->get('conditionsAll');
        $conditionsAny = $workflow->get('conditionsAny');
        $conditionsFormula = $workflow->get('conditionsFormula');

        $conditionManager = $this->conditionManager;

        $conditionManager->setInitData($workflow->getId(), $entity);

        if (isset($conditionsAll)) {
            $result &= $conditionManager->checkConditionsAll($conditionsAll);
        }
        if (isset($conditionsAny)) {
            $result &= $conditionManager->checkConditionsAny($conditionsAny);
        }

        if ($conditionsFormula) {
            $result &= $conditionManager->checkConditionsFormula($conditionsFormula);
        }

        return (bool) $result;
    }

    /**
     * @param array<string, mixed> $variables Formula variables to pass.
     * @throws Error
     */
    public function runActions(Workflow $workflow, Entity $entity, array $variables = []): void
    {
        if (!$entity instanceof CoreEntity) {
            throw new RuntimeException();
        }

        $actions = $workflow->get('actions');

        $actionManager = $this->actionManager;

        $actionManager->setInitData($workflow->getId(), $entity);
        $actionManager->runActions($actions, $variables);
    }

    public function loadWorkflows(bool $reload = false): void
    {
        if (
            !$reload &&
            $this->config->get('useCache') &&
            $this->fileManager->exists($this->cacheFile)
        ) {
            $this->data = $this->fileManager->getPhpContents($this->cacheFile);

            return;
        }

        $this->data = $this->getWorkflowData();

        if ($this->config->get('useCache')) {
            $this->fileManager->putPhpContents($this->cacheFile, $this->data, true);
        }
    }

    /**
     * Get all workflows from database and save into cache.
     *
     * @return array<string, array<string, array<string, mixed>[]>>
     */
    private function getWorkflowData(): array
    {
        $data = [];

        /** @var iterable<Workflow> $workflowList */
        $workflowList = $this->entityManager
            ->getRDBRepository(Workflow::ENTITY_TYPE)
            ->where(['isActive' => true])
            ->order('processOrder')
            ->order('id')
            ->find();

        foreach ($workflowList as $workflow) {
            $rowData = [];

            $rowData['id'] = $workflow->getId();

            foreach ($this->cacheFields as $fieldName) {
                if ($workflow->get($fieldName) === null) {
                    continue;
                }

                $fieldValue = $workflow->get($fieldName);

                if (!empty($fieldValue)) {
                    $rowData[$fieldName] = $fieldValue;
                }
            }

            $rowData['portalOnly'] = (bool) $workflow->get('portalOnly');

            if ($rowData['portalOnly']) {
                $rowData['portalId'] = $workflow->get('portalId');
            }

            $entityType = $workflow->getTargetEntityType();

            $trigger = $workflow->getType() === Workflow::TYPE_SIGNAL ?
                '$' . $workflow->getSignalName() :
                $workflow->getType();

            $data[$trigger][$entityType] ??= [];

            $data[$trigger][$entityType][] = $rowData;
        }

        return $data;
    }

    /**
     * @param stdClass[] $actions
     * @param array<string, mixed> $options
     */
    private function afterActions(array $actions, array $options): void
    {
        if (
            $this->toUpdateLinks($actions) &&
            class_exists("Espo\\Core\\ORM\\Repository\\Option\\SaveContext") &&
            isset($options[SaveContext::NAME])
        ) {
            $context = $options[SaveContext::NAME];

            if ($context instanceof SaveContext) {
                $context->setLinkUpdated();
            }
        }
    }

    /**
     * @param stdClass[] $actions
     */
    private function toUpdateLinks(array $actions): bool
    {
        $hasLinkUpdate = false;

        foreach ($actions as $action) {
            $type = $action->type ?? null;

            if (
                in_array($type, [
                    'createEntity',
                    'createRelatedEntity',
                    'updateRelatedEntity',
                    'startBpmnProcess'
                ])
            ) {
                $hasLinkUpdate = true;

                break;
            }
        }

        return $hasLinkUpdate;
    }
}
