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

namespace Espo\Modules\Advanced\Core\Bpmn;

use Espo\Core\Exceptions\Error;
use Espo\Core\Field\DateTime as DateTimeField;
use Espo\Core\InjectableFactory;
use Espo\Core\Job\Job\Data;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Job\QueueName;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Log;
use Espo\Modules\Advanced\Core\Bpmn\Elements\Base;
use Espo\Modules\Advanced\Entities\BpmnFlowchart;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Modules\Advanced\Entities\BpmnSignalListener;
use Espo\Modules\Advanced\Tools\Bpmn\Jobs\ProcessRootProcessFlows;
use Espo\ORM\Collection;
use Espo\ORM\Entity;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\EntityManager;
use Espo\Core\Container;
use Espo\Core\Utils\Config;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\Join;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;
use Espo\ORM\Query\UpdateBuilder;
use Espo\ORM\Repository\Option\SaveOption;

use DateTime;
use Exception;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Throwable;

class BpmnManager
{
    /** @var string[] */
    private array $conditionalElementTypeList = [
        'eventStartConditional',
        'eventStartConditionalEventSubProcess',
        'eventIntermediateConditionalBoundary',
        'eventIntermediateConditionalCatch',
    ];

    private const PROCEED_PENDING_MAX_SIZE = 20000;
    private const PARALLEL_ITERATION_MAX_SIZE = 100;
    private const PENDING_DEFER_PERIOD = '6 hours';
    private const PENDING_DEFER_INTERVAL_PERIOD = '10 minutes';
    private const PROCESS_UNLOCK_PERIOD = '3 hours';

    public function __construct(
        private Container $container,
        private EntityManager $entityManager,
        private Config $config,
        private Log $log,
        private JobSchedulerFactory $jobSchedulerFactory,
    ) {}

    /**
     * @throws Error
     */
    public function startCreatedProcess(BpmnProcess $process, ?BpmnFlowchart $flowchart = null): void
    {
        if ($process->getStatus() !== BpmnProcess::STATUS_CREATED) {
            throw new Error("BPM: Could not start process with status " . $process->getStatus() . ".");
        }

        if (!$flowchart) {
            $flowchartId = $process->getFlowchartId();

            if (!$flowchartId) {
                throw new Error("BPM: Could not start process w/o flowchartId specified.");
            }
        }

        $startElementId = $process->getStartElementId();

        $targetId = $process->getTargetId();
        $targetType = $process->getTargetType();

        if (!$targetId || !$targetType) {
            throw new Error("BPM: Could not start process w/o targetId or targetType.");
        }

        if (!$flowchart) {
            $flowchart = $this->entityManager
                ->getRDBRepositoryByClass(BpmnFlowchart::class)
                ->getById($flowchartId);
        }

        $target = $this->entityManager->getEntityById($targetType, $targetId);

        if (!$flowchart) {
            throw new Error("BPM: Could not find flowchart.");
        }

        if (!$target) {
            throw new Error("BPM: Could not find flowchart.");
        }

        $this->startProcess(
            target: $target,
            flowchart: $flowchart,
            startElementId: $startElementId,
            process: $process,
        );
    }

    /**
     * @throws Error
     */
    public function startProcess(
        Entity $target,
        BpmnFlowchart $flowchart,
        ?string $startElementId = null,
        ?BpmnProcess $process = null,
        ?string $workflowId = null,
        ?stdClass $signalParams = null
    ): void {

        if (!$target instanceof CoreEntity) {
            throw new RuntimeException();
        }

        $flowchartId = $flowchart->hasId() ? $flowchart->getId() : null;

        $this->log->debug("BPM: startProcess, flowchart $flowchartId, target {$target->getId()}.");

        $elementsDataHash = $flowchart->getElementsDataHash();

        if ($startElementId) {
            $this->checkFlowchartItemPropriety($elementsDataHash, $startElementId);

            $startItem = $elementsDataHash->$startElementId;

            if (!in_array($startItem->type, [
                'eventStart',
                'eventStartConditional',
                'eventStartTimer',
                'eventStartError',
                'eventStartEscalation',
                'eventStartSignal',
                'eventStartCompensation',
            ])) {
                throw new Error("BPM: startProcess, Bad start event type.");
            }
        }

        $isSubProcess = false;

        if ($process && $process->isSubProcess()) {
            $isSubProcess = true;
        }

        if (!$isSubProcess && $flowchartId) {
            $whereClause = [
                'targetId' => $target->getId(),
                'targetType' => $flowchart->getTargetType(),
                'status' => [
                    BpmnProcess::STATUS_STARTED,
                    BpmnProcess::STATUS_PAUSED,
                ],
                'flowchartId' => $flowchartId,
            ];

            $existingProcess = $this->entityManager
                ->getRDBRepository(BpmnProcess::ENTITY_TYPE)
                ->where($whereClause)
                ->findOne();

            if ($existingProcess) {
                throw new Error(
                    "Process for flowchart " . $flowchartId .
                    " can't be run because process is already running.");
            }
        }

        $variables = (object) [];
        $createdEntitiesData = (object) [];

        if ($process) {
            $variables = $process->getVariables() ?? (object) [];

            if ($process->get('createdEntitiesData')) {
                $createdEntitiesData = $process->get('createdEntitiesData');
            }
        }

        if ($signalParams) {
            $variables->__signalParams = $signalParams;
        }

        if (!$process) {
            $process = $this->entityManager->getRDBRepositoryByClass(BpmnProcess::class)->getNew();

            $process->set([
                'name' => $flowchart->getName(),
                'assignedUserId' => $flowchart->getAssignedUserId(),
                'teamsIds' => $flowchart->getTeamIdList(),
                'workflowId' => $workflowId,
            ]);
        }

        $process->set([
            'name' => $flowchart->getName(),
            'flowchartId' => $flowchartId,
            'targetId' => $target->getId(),
            'targetType' => $flowchart->getTargetType(),
            'flowchartData' => $flowchart->getData(),
            'flowchartElementsDataHash' => $elementsDataHash,
            'assignedUserId' => $flowchart->getAssignedUserId(),
            'teamsIds' => $flowchart->getTeamIdList(),
            'status' => BpmnProcess::STATUS_STARTED,
            'createdEntitiesData' => $createdEntitiesData,
            'startElementId' => $startElementId,
            'variables' => $variables,
        ]);

        $this->entityManager->saveEntity($process, [
            'createdById' => 'system',
            'skipModifiedBy' => true,
            'skipStartProcessFlow' => true,
        ]);

        if ($startElementId) {
            $flowNode = $this->prepareFlow($target, $process, $startElementId);

            if ($flowNode) {
                $this->prepareEventSubProcesses($target, $process);
                $this->processPreparedFlowNode($target, $flowNode, $process);
            }

            return;
        }

        $startElementIdList = $this->getProcessElementWithoutIncomingFlowIdList($process);

        /** @var BpmnFlowNode[] $flowNodeList */
        $flowNodeList = [];

        foreach ($startElementIdList as $elementId) {
            $flowNode = $this->prepareFlow($target, $process, $elementId);

            if (!$flowNode) {
                continue;
            }

            $flowNodeList[] = $flowNode;
        }

        if (!count($flowNodeList)) {
            $this->endProcess($process);
        } else {
            $this->prepareEventSubProcesses($target, $process);
        }

        foreach ($flowNodeList as $flowNode) {
            $this->processPreparedFlowNode($target, $flowNode, $process);

            $this->entityManager->refreshEntity($process);
        }
    }

    /**
     * @throws Error
     */
    public function prepareEventSubProcesses(Entity $target, BpmnProcess $process): void
    {
        $standByFlowNodeList = [];

        foreach ($this->getProcessElementEventSubProcessIdList($process) as $id) {
            $flowNode = $this->prepareStandbyFlow($target, $process, $id);

            if ($flowNode) {
                $standByFlowNodeList[] = $flowNode;
            }
        }

        foreach ($standByFlowNodeList as $flowNode) {
            $this->processPreparedFlowNode($target, $flowNode, $process);
        }
    }

    /**
     * @return string[]
     */
    private function getProcessElementEventSubProcessIdList(BpmnProcess $process): array
    {
        $resultElementIdList = [];

        $elementIdList = $process->getElementIdList();

        foreach ($elementIdList as $id) {
            $item = $process->getElementDataById($id);

            if (empty($item->type)) {
                continue;
            }

            if ($item->type === 'eventSubProcess') {
                $resultElementIdList[] = $id;
            }
        }

        return $resultElementIdList;
    }

    /**
     * @return string[]
     */
    private function getProcessElementWithoutIncomingFlowIdList(BpmnProcess $process): array
    {
        $resultElementIdList = [];

        $elementIdList = $process->getElementIdList();

        foreach ($elementIdList as $id) {
            $item = $process->getElementDataById($id);

            if (empty($item->type)) {
                continue;
            }

            if (
                $item->type !== 'eventStart' &&
                (
                    in_array($item->type, ['flow', 'eventIntermediateLinkCatch']) ||
                    str_starts_with($item->type, 'eventStart')
                )
            ) {
                continue;
            }

            if (str_ends_with($item->type, 'EventSubProcess')) {
                continue;
            }

            if (str_ends_with($item->type, 'Boundary')) {
                continue;
            }

            if ($item->type === 'eventSubProcess') {
                continue;
            }

            if (!empty($item->previousElementIdList)) {
                continue;
            }

            if (!empty($item->isForCompensation)) {
                continue;
            }

            $resultElementIdList[] = $id;
        }

        return $resultElementIdList;
    }

    public function prepareStandbyFlow(Entity $target, BpmnProcess $process, string $elementId): ?BpmnFlowNode
    {
        $this->log->debug("BPM: prepareStandbyFlow, process {$process->getId()}, element $elementId.");

        if ($process->getStatus() !== BpmnProcess::STATUS_STARTED) {
            $this->log->info(
                "BPM: Process status " . $process->getId() . " is not 'Started' but ".
                $process->getStatus() . ", hence can't create standby flow."
            );

            return null;
        }

        $item = $process->getElementDataById($elementId);

        $eventStartData = $item->eventStartData ?? (object) [];
        $startEventType = $eventStartData->type ?? null;

        if (!$startEventType) {
            return null;
        }

        if (!$eventStartData->id) {
            return null;
        }

        if (
            in_array($startEventType, [
                'eventStartError',
                'eventStartEscalation',
                'eventStartCompensation',
            ])
        ) {
            return null;
        }

        $elementType = $startEventType . 'EventSubProcess';

        /** @var BpmnFlowNode */
        return $this->entityManager->createEntity(BpmnFlowNode::ENTITY_TYPE, [
            'status' => BpmnFlowNode::STATUS_CREATED,
            'elementType' => $elementType,
            'elementData' => $eventStartData,
            'flowchartId' => $process->getFlowchartId(),
            'processId' => $process->getId(),
            'targetType' => $target->getEntityType(),
            'targetId' => $target->getId(),
            'data' => (object) [
                'subProcessElementId' => $elementId,
                'subProcessTarget' => $item->target ?? null,
                'subProcessIsInterrupting' => $eventStartData->isInterrupting ?? false,
                'subProcessTitle' => $eventStartData->title ?? null,
                'subProcessStartData' => $eventStartData,
            ],
        ]);
    }

    /**
     * @throws Error
     */
    private function checkFlowchartItemPropriety(stdClass $elementsDataHash, string $elementId): void
    {
        if (!$elementId) {
            throw new Error('No start event element.');
        }

        if (!isset($elementsDataHash->$elementId) || !is_object($elementsDataHash->$elementId)) {
            throw new Error('Not existing start event element id.');
        }

        $item = $elementsDataHash->$elementId;

        if (!isset($item->type)) {
            throw new Error('Bad start event element.');
        }
    }

    /**
     * @throws Error
     */
    public function prepareFlow(
        Entity $target,
        BpmnProcess $process,
        string $elementId,
        ?string $previousFlowNodeId = null,
        ?string $previousFlowNodeElementType = null,
        ?string $divergentFlowNodeId = null,
        bool $allowEndedProcess = false
    ): ?BpmnFlowNode {

        $this->log->debug("BPM: prepareFlow, process {$process->getId()}, element $elementId.");

        if (!$allowEndedProcess && $process->getStatus() !== BpmnProcess::STATUS_STARTED) {
            $this->log->info(
                "BPM: Process status ".$process->getId() ." is not 'Started' but ".
                $process->getStatus() . ", hence can't be processed."
            );

            return null;
        }

        $elementsDataHash = $process->get('flowchartElementsDataHash');

        $this->checkFlowchartItemPropriety($elementsDataHash, $elementId);

        if (
            $target->getEntityType() !== $process->getTargetType() ||
            $target->getId() !== $process->getTargetId()
        ) {
            throw new Error("Not matching targets.");
        }

        $item = $elementsDataHash->$elementId;

        $elementType = $item->type;

        /** @var BpmnFlowNode $flowNode */
        $flowNode = $this->entityManager->getNewEntity(BpmnFlowNode::ENTITY_TYPE);

        $flowNode->set([
            'status' => BpmnFlowNode::STATUS_CREATED,
            'elementId' => $elementId,
            'elementType' => $elementType,
            'elementData' => $item,
            'flowchartId' => $process->getFlowchartId(),
            'processId' => $process->getId(),
            'previousFlowNodeElementType' => $previousFlowNodeElementType,
            'previousFlowNodeId' => $previousFlowNodeId,
            'divergentFlowNodeId' => $divergentFlowNodeId,
            'targetType' => $target->getEntityType(),
            'targetId' => $target->getId(),
        ]);

        $this->entityManager->saveEntity($flowNode);

        return $flowNode;
    }

    /**
     * @throws Error
     */
    public function processPreparedFlowNode(Entity $target, BpmnFlowNode $flowNode, BpmnProcess $process): void
    {
        $impl = $this->getFlowNodeImplementation($target, $flowNode, $process);

        $impl->beforeProcess();

        if (!$impl->isProcessable()) {
            $this->log->info("BPM: Can't process not processable node ". $flowNode->getId() .".");

            return;
        }

        $impl->process();
    }

    /**
     * @throws Error
     */
    public function processFlow(
        Entity $target,
        BpmnProcess $process,
        string $elementId,
        ?string $previousFlowNodeId = null,
        ?string $previousFlowNodeElementType = null,
        ?string $divergentFlowNodeId = null
    ): ?BpmnFlowNode {

        $flowNode = $this->prepareFlow(
            $target,
            $process,
            $elementId,
            $previousFlowNodeId,
            $previousFlowNodeElementType,
            $divergentFlowNodeId
        );

        if ($flowNode) {
            $this->processPreparedFlowNode($target, $flowNode, $process);
        }

        return $flowNode;
    }

    public function processParallel(): void
    {
        $this->unlockProcesses();

        $limit = $this->config->get('bpmnParallelIterationMaxSize', self::PARALLEL_ITERATION_MAX_SIZE);

        // A bit slower than the group-by variant.
        /*$query1 = SelectBuilder::create()
            ->from(BpmnProcess::ENTITY_TYPE, 'rootProcess')
            ->select('id', 'processId')
            ->select('visitTimestamp', 'visitTimestamp')
            ->select('firstFlowNode.number', 'number')
            ->join(
                Join::createWithSubQuery(
                    SelectBuilder::create()
                        ->from(BpmnFlowNode::ENTITY_TYPE)
                        ->select([
                            'number',
                            'process.rootProcessId',
                        ])
                        ->join('process')
                        ->where($this->getPendingNodeWhereClause())
                        ->where(
                            Cond::equal(
                                Expr::column('process.rootProcessId'),
                                Expr::column('rootProcess.id'),
                            )
                        )
                        ->order('number')
                        ->limit(0, 1)
                        ->build(),
                    'firstFlowNode'
                )
                ->withLateral()
                ->withConditions(Expr::value(true))
            )
            ->where([
                'rootProcess.isLocked' => false,
                'rootProcess.status' => [BpmnProcess::STATUS_STARTED],
            ])
            ->order('rootProcess.visitTimestamp')
            ->order('firstFlowNode.number')
            ->limit(0, $limit)
            ->build();*/

        /*$isLockedExpression =
            (
                method_exists(Expr::class, 'anyValue') &&
                // ANY_VALUE is not supported on earlier MariaDB and MySQL versions.
                $this->config->get('database.platform') === 'Postgresql'
            ) ?
            'ANY_VALUE:(rootProcess.isLocked)' :
            'MAX:(rootProcess.isLocked)';*/

        $query1 = SelectBuilder::create()
            ->from(BpmnFlowNode::ENTITY_TYPE)
            ->select('process.rootProcessId', 'processId')
            ->select('MAX:(rootProcess.visitTimestamp)', 'visitTimestamp')
            ->select('MIN:(number)', 'number')
            ->join('process')
            ->join(
                Join::createWithTableTarget(BpmnProcess::ENTITY_TYPE, 'rootProcess')
                    ->withConditions(
                        Cond::equal(
                            Expr::column('process.rootProcessId'),
                            Expr::column('rootProcess.id'),
                        )
                    )
            )
            ->where($this->getPendingNodeWhereClause())
            ->where([
                'rootProcess.isLocked' => false,
                'rootProcess.status' => [BpmnProcess::STATUS_STARTED],
            ])
            ->group('process.rootProcessId')
            ->order('MAX:(rootProcess.visitTimestamp)')
            ->order('MIN:(number)')
            ->limit(0, $limit)
            ->build();

        $query2 = SelectBuilder::create()
            ->from(BpmnSignalListener::ENTITY_TYPE)
            ->select('process.rootProcessId', 'processId')
            ->select('MAX:(rootProcess.visitTimestamp)', 'visitTimestamp')
            ->select('MIN:(number)', 'number')
            ->join('flowNode')
            ->join(
                Join::createWithTableTarget(BpmnProcess::ENTITY_TYPE, 'process')
                    ->withConditions(
                        Cond::equal(
                            Expr::column('flowNode.processId'),
                            Expr::column('process.id'),
                        )
                    )
            )
            ->join(
                Join::createWithTableTarget(BpmnProcess::ENTITY_TYPE, 'rootProcess')
                    ->withConditions(
                        Cond::equal(
                            Expr::column('process.rootProcessId'),
                            Expr::column('rootProcess.id'),
                        )
                    )
            )
            ->where([
                'isTriggered' => true,
                'rootProcess.isLocked' => false,
                'rootProcess.status' => [BpmnProcess::STATUS_STARTED],
            ])
            ->group('process.rootProcessId')
            ->order('MAX:(rootProcess.visitTimestamp)')
            ->order('MIN:(number)')
            ->limit(0, $limit)
            ->build();

        $query = UnionBuilder::create()
            ->query($query1)
            ->query($query2)
            ->order('visitTimestamp')
            ->order('number')
            ->limit(0, $limit)
            ->build();

        $sth = $this->entityManager->getQueryExecutor()->execute($query);

        while ($row = $sth->fetch()) {
            $rootProcessId = $row['processId'] ?? null;

            if (!is_string($rootProcessId)) {
                throw new RuntimeException("Non-string root process ID.");
            }

            $process = $this->entityManager->getRDBRepositoryByClass(BpmnProcess::class)->getNew();

            $process->setMultiple([
                'id' => $rootProcessId,
                'isLocked' => false,
                'visitTimestamp' => $row['visitTimestamp'] ?? null,
            ]);

            $process->setAsFetched();

            $this->prepareRootProcessVisit($process);
        }
    }

    private function unlockProcesses(): void
    {
        $period = $this->config->get('bpmnProcessUnlockPeriod') ?? self::PROCESS_UNLOCK_PERIOD;

        $from = DateTimeField::createNow()->modify('-' . $period);

        $updateQuery = UpdateBuilder::create()
            ->in(BpmnProcess::ENTITY_TYPE)
            ->set([
                'isLocked' => false,
            ])
            ->where([
                'isLocked' => true,
                'visitTimestamp<' => $from->toTimestamp() * 1000,
            ])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($updateQuery);
    }

    private function prepareRootProcessVisit(BpmnProcess $process): void
    {
        $process->setIsLocked(true);
        $process->setVisitTimestampNow();

        $this->entityManager->saveEntity($process, [SaveOption::SKIP_ALL => true]);

        $queue = (new ReflectionClass(QueueName::class))->hasConstant('M0') ?
            'M0' : null;

        $this->jobSchedulerFactory
            ->create()
            ->setClassName(ProcessRootProcessFlows::class)
            ->setData(
                Data::create()
                    ->withTargetId($process->getId())
                    ->withTargetType($process->getEntityType())
            )
            ->setQueue($queue)
            ->schedule();
    }

    /**
     * @return array<string, mixed>
     */
    private function getPendingNodeWhereClause(): array
    {
        return [
            'OR' => [
                [
                    'status' => BpmnFlowNode::STATUS_PENDING,
                    'elementType' => 'eventIntermediateTimerCatch',
                    'proceedAt<=' => date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
                ],
                [
                    'status' => BpmnFlowNode::STATUS_PENDING,
                    'elementType' => 'eventIntermediateConditionalCatch',
                ],
                [
                    'status' => BpmnFlowNode::STATUS_PENDING,
                    'elementType' => 'eventIntermediateMessageCatch',
                ],
                [
                    'status' => BpmnFlowNode::STATUS_PENDING,
                    'elementType' => 'eventIntermediateConditionalBoundary',
                ],
                [
                    'status' => BpmnFlowNode::STATUS_PENDING,
                    'elementType' => 'eventIntermediateTimerBoundary',
                    'proceedAt<=' => date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
                ],
                [
                    'status' => BpmnFlowNode::STATUS_PENDING,
                    'elementType' => 'eventIntermediateMessageBoundary',
                ],
                [
                    'status' => BpmnFlowNode::STATUS_PENDING,
                    'elementType' => 'taskSendMessage',
                ],
                [
                    'status' => BpmnFlowNode::STATUS_STANDBY,
                    'elementType' => 'eventStartTimerEventSubProcess',
                    'proceedAt<=' => date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
                ],
                [
                    'status' => BpmnFlowNode::STATUS_STANDBY,
                    'elementType' => 'eventStartConditionalEventSubProcess',
                ],
                [
                    'status' => BpmnFlowNode::STATUS_PENDING,
                    'elementType' => 'eventIntermediateCompensationThrow',
                ],
                [
                    'status' => BpmnFlowNode::STATUS_PENDING,
                    'elementType' => 'EventEndCompensation',
                ],
            ],
            'isLocked' => false,
        ];
    }

    /**
     * @return iterable<BpmnFlowNode>
     */
    private function getPendingFlowNodes(?string $rootProcessId = null): iterable
    {
        $limit = $this->config->get('bpmnProceedPendingMaxSize', self::PROCEED_PENDING_MAX_SIZE);

        $builder = $this->entityManager
            ->getRDBRepositoryByClass(BpmnFlowNode::class)
            ->sth()
            ->where($this->getPendingNodeWhereClause())
            ->select([
                'id',
                'elementType',
                'isLocked',
                'deferredAt',
                'isDeferred',
                'createdAt',
            ])
            ->order('number', false)
            ->limit(0, $limit);

        if ($rootProcessId) {
            $builder
                ->join('process')
                ->where(['process.rootProcessId' => $rootProcessId]);
        }

        return $builder->find();
    }

    public function processPendingFlows(?string $rootProcessId = null): void
    {
        $this->log->debug("BPM: processPendingFlows " . ($rootProcessId ?? '(all)'));

        $flowNodes = $this->getPendingFlowNodes($rootProcessId);

        foreach ($flowNodes as $flowNode) {
            $this->processPendingFlowsItem($flowNode);
        }

        $this->cleanupSignalListeners($rootProcessId);
        $this->processTriggeredSignals($rootProcessId);
    }

    private function processPendingFlowsItem(BpmnFlowNode $flowNode): void
    {
        try {
            $toProcess = $this->checkPendingFlow($flowNode);
        } catch (Throwable $e) {
            $this->logException($e);

            // @todo Destroy item.

            return;
        }

        if (!$toProcess) {
            return;
        }

        try {
            $this->controlDeferPendingFlow($flowNode);
        } catch (Throwable $e) {
            $this->logException($e);

            // @todo Destroy item.

            return;
        }

        try {
            $this->proceedPendingFlow($flowNode);
        } catch (Throwable $e) {
            $this->logException($e);

            // @todo Destroy item.
        }
    }

    /**
     * Preventing checking conditional nodes too often.
     *
     * @return bool False if flow checking should be skipped.
     */
    private function checkPendingFlow(BpmnFlowNode $flowNode): bool
    {
        $elementType = $flowNode->getElementType();

        if (!in_array($elementType, $this->conditionalElementTypeList)) {
            return true;
        }

        if (!$flowNode->get('isDeferred')) {
            return true;
        }

        $deferredAt = $flowNode->get('deferredAt');

        if (!$deferredAt) {
            return true;
        }

        $period = $this->config->get('bpmnPendingDeferIntervalPeriod', self::PENDING_DEFER_INTERVAL_PERIOD);

        try {
            $threshold = (new DateTime())->modify('-' . $period);
        } catch (Exception $e) {/** @phpstan-ignore-line */
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $deferredAtDate = DateTime::createFromFormat(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT, $deferredAt);

        if ($deferredAtDate === false || $threshold === false) {
            return true;
        }

        $diff = $deferredAtDate->diff($threshold);

        if (!$diff->invert) {
            return true;
        }

        return false;
    }

    private function controlDeferPendingFlow(BpmnFlowNode $flowNode): void
    {
        $elementType = $flowNode->getElementType();

        if (!in_array($elementType, $this->conditionalElementTypeList)) {
            return;
        }

        $from = $flowNode->get('deferredAt') ?? $flowNode->get('createdAt');

        if (!$from) {
            return;
        }

        $fromDate = DateTime::createFromFormat(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT, $from);

        $period = $flowNode->get('deferredAt') ?
            $this->config->get('bpmnPendingDeferIntervalPeriod', self::PENDING_DEFER_INTERVAL_PERIOD) :
            $this->config->get('bpmnPendingDeferPeriod', self::PENDING_DEFER_PERIOD);

        try {
            $threshold = (new DateTime())->modify('-' . $period);
        } catch (Exception $e) {/** @phpstan-ignore-line */
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        if ($fromDate === false || $threshold === false) {
            return;
        }

        $diff = $fromDate->diff($threshold);

        if (
            $diff->invert &&
            $flowNode->get('deferredAt') &&
            !$flowNode->get('isDeferred')
        ) {
            // If a node was set as not deferred, it should be checked only once.

            $flowNode->set(['isDeferred' => true]);

            $this->entityManager->saveEntity($flowNode);

            return;
        }

        if ($diff->invert) {
            return;
        }

        $flowNode->set([
            'deferredAt' => (new DateTime())->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
            'isDeferred' => true,
        ]);

        $this->entityManager->saveEntity($flowNode);
    }

    public function cleanupSignalListeners(?string $rootProcessId = null): void
    {
        $limit = $this->config->get('bpmnProceedPendingMaxSize', self::PROCEED_PENDING_MAX_SIZE);

        $builder = $this->entityManager
            ->getRDBRepositoryByClass(BpmnSignalListener::class)
            ->select(['id', 'name', 'flowNodeId'])
            ->order('number')
            ->leftJoin(
                BpmnFlowNode::ENTITY_TYPE,
                'flowNode',
                ['flowNode.id:' => 'flowNodeId'],
            )
            ->where([
                'OR' => [
                    'flowNode.deleted' => true,
                    'flowNode.id' => null,
                    'flowNode.status!=' => [
                        BpmnFlowNode::STATUS_STANDBY,
                        BpmnFlowNode::STATUS_CREATED,
                        BpmnFlowNode::STATUS_PENDING,
                    ],
                ],
            ])
            ->limit(0, $limit);

        if ($rootProcessId) {
            $builder
                ->leftJoin(
                    Join::createWithTableTarget(BpmnProcess::ENTITY_TYPE, 'process')
                        ->withConditions(
                            Cond::equal(
                                Expr::column('flowNode.processId'),
                                Expr::column('process.id'),
                            )
                        )
                )
                ->where(['process.rootProcessId' => $rootProcessId]);
        }

        foreach ($builder->find() as $item) {
            $this->log->debug("BPM: Delete not actual signal listener for flow node " . $item->getFlowNodeId());

            $this->entityManager
                ->getRDBRepository(BpmnSignalListener::ENTITY_TYPE)
                ->deleteFromDb($item->getId());
        }
    }

    private function processTriggeredSignals(?string $rootProcessId = null): void
    {
        $limit = $this->config->get('bpmnProceedPendingMaxSize', self::PROCEED_PENDING_MAX_SIZE);

        $this->log->debug("BPM: processTriggeredSignals");

        $builder = $this->entityManager
            ->getRDBRepositoryByClass(BpmnSignalListener::class)
            ->select([
                'id',
                'name',
                'flowNodeId',
            ])
            ->order('number')
            ->where([
                'isTriggered' => true,
            ])
            ->limit(0, $limit);

        if ($rootProcessId) {
            $builder
                ->leftJoin('flowNode')
                ->leftJoin(
                    Join::createWithTableTarget(BpmnProcess::ENTITY_TYPE, 'process')
                         ->withConditions(
                             Cond::equal(
                                 Expr::column('flowNode.processId'),
                                 Expr::column('process.id'),
                             )
                         )
                )
                ->where(['process.rootProcessId' => $rootProcessId]);
        }

        foreach ($builder->find() as $item) {
            $this->entityManager
                ->getRDBRepository(BpmnSignalListener::ENTITY_TYPE)
                ->deleteFromDb($item->getId());

            $flowNodeId = $item->getFlowNodeId();

            if (!$flowNodeId) {
                continue;
            }

            $flowNode = $this->entityManager->getRDBRepositoryByClass(BpmnFlowNode::class)->getById($flowNodeId);

            if (!$flowNode) {
                $this->log->notice("BPM: Flow Node $flowNodeId not found.");

                continue;
            }

            try {
                $this->proceedPendingFlow($flowNode);
            } catch (Throwable $e) {
                $this->logException($e);
            }
        }
    }

    public function setFlowNodeFailed(BpmnFlowNode $flowNode): void
    {
        $flowNode->setStatus(BpmnFlowNode::STATUS_FAILED);

        $flowNode->set([
            'processedAt' => date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
        ]);

        $this->entityManager->saveEntity($flowNode);
    }

    /**
     * @throws Error
     */
    private function checkFlowIsActual(?Entity $target, BpmnFlowNode $flowNode, ?BpmnProcess $process): void
    {
        if (!$process) {
            $this->setFlowNodeFailed($flowNode);

            throw new Error("Could not find process " . $flowNode->getProcessId() . ".");
        }

        if (!$target) {
            $this->setFlowNodeFailed($flowNode);
            $this->interruptProcess($process);

            throw new Error("Could not find target for process " . $process->getId() . ".");
        }

        if ($process->getStatus() === BpmnProcess::STATUS_PAUSED) {
            $this->unlockFlowNode($flowNode);

            throw new Error("Attempted to continue flow of paused process " . $process->getId() . ".");
        }

        if ($flowNode->getElementDataItemValue('isForCompensation')) {
            return;
        }

        if ($process->getStatus() !== BpmnProcess::STATUS_STARTED) {
            $this->setFlowNodeFailed($flowNode);

            throw new Error("Attempted to continue flow of not active process " . $process->getId() . ".");
        }
    }

    /**
     * @throws Error
     */
    private function getAndLockFlowNodeById(string $id): BpmnFlowNode
    {
        $transactionManager = $this->entityManager->getTransactionManager();

        $transactionManager->start();

        /** @var ?BpmnFlowNode $flowNode */
        $flowNode = $this->entityManager
            ->getRDBRepository('BpmnFlowNode')
            ->forUpdate()
            ->where(['id' => $id])
            ->findOne();

        if (!$flowNode) {
            $transactionManager->rollback();

            throw new Error("Can't find Flow Node $id.");
        }

        if ($flowNode->get('isLocked')) {
            $transactionManager->rollback();

            throw new Error("Can't get locked Flow Node $id.");
        }

        $this->lockFlowNode($flowNode);

        $transactionManager->commit();

        return $flowNode;
    }

    private function lockFlowNode(BpmnFlowNode $flowNode): void
    {
        $flowNode->set('isLocked', true);

        $this->entityManager->saveEntity($flowNode);
    }

    private function unlockFlowNode(BpmnFlowNode $flowNode): void
    {
        $flowNode->set('isLocked', false);

        $this->entityManager->saveEntity($flowNode);
    }

    /**
     * @throws Error
     */
    public function proceedPendingFlow(BpmnFlowNode $flowNode): void
    {
        $this->log->debug("BPM: proceedPendingFlow, node {$flowNode->getId()}.");

        $flowNode = $this->getAndLockFlowNodeById($flowNode->getId());

        /** @var ?BpmnProcess $process */
        $process = $this->entityManager
            ->getEntityById(BpmnProcess::ENTITY_TYPE, $flowNode->getProcessId());

        $target = $this->entityManager
            ->getEntityById($flowNode->getTargetType(), $flowNode->getTargetId());

        if (
            $flowNode->getStatus() !== BpmnFlowNode::STATUS_PENDING &&
            $flowNode->getStatus() !== BpmnFlowNode::STATUS_STANDBY
        ) {
            $this->unlockFlowNode($flowNode);

            $this->log->info(
                "BPM: Can not proceed not pending or standby (". $flowNode->getStatus() .") flow node in process " .
                $process->getId() . "."
            );

            return;
        }

        $this->checkFlowIsActual($target, $flowNode, $process);

        $impl = $this->getFlowNodeImplementation($target, $flowNode, $process);
        $impl->proceedPending();

        $this->unlockFlowNode($flowNode);
    }

    /**
     * @throws Error
     */
    public function completeFlow(BpmnFlowNode $flowNode): void
    {
        $this->log->debug("BPM: completeFlow, node {$flowNode->getId()}");

        $flowNode = $this->getAndLockFlowNodeById($flowNode->getId());

        $target = $this->entityManager
            ->getEntityById($flowNode->getTargetType(), $flowNode->getTargetId());

        /** @var ?BpmnProcess $process */
        $process = $this->entityManager
            ->getEntityById(BpmnProcess::ENTITY_TYPE, $flowNode->getProcessId());

        if ($flowNode->getStatus() !== BpmnFlowNode::STATUS_IN_PROCESS) {
            $this->unlockFlowNode($flowNode);

            $message =
                "BPM: Cannot complete flow node with status '{$flowNode->getStatus()}' in process {$process->getId()}.";

            throw new Error($message);
        }

        if ($flowNode->getElementType() !== 'eventSubProcess') {
            $this->checkFlowIsActual($target, $flowNode, $process);
        }

        $this->getFlowNodeImplementation($target, $flowNode, $process)->complete();
        $this->unlockFlowNode($flowNode);

        if (
            $flowNode->getElementType() === 'eventSubProcess' &&
            (
                self::isEventSubProcessFlowNodeInterrupting($flowNode) ||
                self::isEventSubProcessFlowNodeErrorHandler($flowNode)
            ) &&
            $process->getParentProcessFlowNodeId()
        ) {
            // A sub-process interrupted by interrupting event is complete.

            $parentFlowNode = $this->getAndLockFlowNodeById($process->getParentProcessFlowNodeId());

            $parentFlowNode->setStatus(BpmnFlowNode::STATUS_INTERRUPTED);

            $this->saveFlowNode($parentFlowNode);
            $this->unlockFlowNode($parentFlowNode);

            $process = $this->getProcessById($process->getParentProcessId());

            $this->endProcessFlow($parentFlowNode, $process);
        }
    }

    /**
     * @throws Error
     */
    private function failFlow(BpmnFlowNode $flowNode): void
    {
        $id = $flowNode->getId();

        $this->log->debug("BPM: failFlow, node $id");

        $flowNode = $this->getAndLockFlowNodeById($id);
        /** @var ?BpmnProcess $process */
        $process = $this->entityManager->getEntityById(BpmnProcess::ENTITY_TYPE, $flowNode->getProcessId());
        $target = $this->entityManager->getEntityById($flowNode->getTargetType(), $flowNode->getTargetId());

        if (!$this->isFlowNodeIsActual($flowNode)) {
            $this->unlockFlowNode($flowNode);

            throw new Error("Can not proceed not 'In Process' flow node in process $id.");
        }

        $this->checkFlowIsActual($target, $flowNode, $process);
        $this->getFlowNodeImplementation($target, $flowNode, $process)->fail();

        $this->unlockFlowNode($flowNode);
    }

    /**
     * @throws Error
     */
    public function cancelActivityByBoundaryEvent(BpmnFlowNode $flowNode): void
    {
        $this->log->debug("BPM: cancelActivityByBoundaryEvent, node {$flowNode->getId()}");

        $activityFlowNode = $this->getAndLockFlowNodeById($flowNode->getPreviousFlowNodeId());

        /** @var ?BpmnProcess $process */
        $process = $this->entityManager->getEntityById(BpmnProcess::ENTITY_TYPE, $flowNode->getProcessId());
        $target = $this->entityManager->getEntityById($flowNode->getTargetType(), $flowNode->getTargetId());

        if (!$this->isFlowNodeIsActual($activityFlowNode)) {
            $this->unlockFlowNode($activityFlowNode);

            return;
        }

        $this->checkFlowIsActual($target, $activityFlowNode, $process);

        $impl = $this->getFlowNodeImplementation($target, $activityFlowNode, $process);

        $impl->interrupt();

        if (in_array($activityFlowNode->getElementType(), ['callActivity', 'subProcess', 'eventSubProcess'])) {
            $subProcess = $this->entityManager
                ->getRDBRepositoryByClass(BpmnProcess::class)
                ->where(['parentProcessFlowNodeId' => $activityFlowNode->getId()])
                ->findOne();

            if ($subProcess) {
                try {
                    $this->interruptProcess($subProcess);
                } catch (Throwable $e) {
                    $message = "BPM: Fail when tried to interrupt sub-process; " . $e->getMessage();

                    $this->log->error($message, ['exception' => $e]);
                }
            }
        }

        $this->unlockFlowNode($activityFlowNode);
    }

    private function isFlowNodeIsActual(BpmnFlowNode $flowNode): bool
    {
        return !in_array(
            $flowNode->getStatus(),
            [
                BpmnFlowNode::STATUS_FAILED,
                BpmnFlowNode::STATUS_REJECTED,
                BpmnFlowNode::STATUS_PROCESSED,
                BpmnFlowNode::STATUS_INTERRUPTED,
            ]
        );
    }

    /*private function failProcessFlow(Entity $target, BpmnFlowNode $flowNode, BpmnProcess $process): void
    {
        $this->getFlowNodeImplementation($target, $flowNode, $process)->fail();
    }*/

    public function getFlowNodeImplementation(Entity $target, BpmnFlowNode $flowNode, BpmnProcess $process): Base
    {
        $elementType = $flowNode->get('elementType');

        /** @var class-string<Base> $className */
        $className = 'Espo\\Modules\\Advanced\\Core\\Bpmn\\Elements\\' . ucfirst($elementType);

        return $this->container
            ->getByClass(InjectableFactory::class)
            ->createWith($className, [
                'manager' => $this,
                'target' => $target,
                'flowNode' => $flowNode,
                'process' => $process,
            ]);
    }

    private function getActiveFlowCount(BpmnProcess $process): int
    {
        return $this->entityManager
            ->getRDBRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where([
                'status!=' => [
                    BpmnFlowNode::STATUS_PROCESSED,
                    BpmnFlowNode::STATUS_REJECTED,
                    BpmnFlowNode::STATUS_FAILED,
                    BpmnFlowNode::STATUS_INTERRUPTED,
                    BpmnFlowNode::STATUS_STANDBY,
                ],
                'processId' => $process->getId(),
                'elementType!=' => 'eventSubProcess',
            ])
            ->count();
    }

    private function getActiveEventSubProcessCount(BpmnProcess $process): int
    {
        return $this->entityManager
            ->getRDBRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where([
                'status' => [BpmnFlowNode::STATUS_IN_PROCESS],
                'processId' => $process->getId(),
                'elementType' => 'eventSubProcess',
            ])
            ->count();
    }

    /**
     * @throws Error
     */
    public function endProcessFlow(BpmnFlowNode $flowNode, BpmnProcess $process): void
    {
        $this->log->debug("BPM: endProcessFlow, node {$flowNode->getId()}.");

        if ($this->isFlowNodeIsActual($flowNode)) {
            $flowNode->setStatus(BpmnFlowNode::STATUS_REJECTED);

            $this->entityManager->saveEntity($flowNode);
        }

        $this->tryToEndProcess($process);
    }

    /**
     * @throws Error
     */
    public function tryToEndProcess(BpmnProcess $process): void
    {
        $this->log->debug("BPM: tryToEndProcess, process {$process->getId()}.");

        if (!$process->get('deleted')) { // @todo Change to const when v9.0 is min supported.
            $this->entityManager->refreshEntity($process);
        }

        if (
            !$this->getActiveFlowCount($process) &&
            in_array(
                $process->getStatus(),
                [
                    BpmnProcess::STATUS_STARTED,
                    BpmnProcess::STATUS_PAUSED,
                ]
            )
        ) {
            if ($this->getActiveEventSubProcessCount($process)) {
                $this->rejectActiveFlows($process);

                return;
            }

            $this->endProcess($process);
        }
    }

    /**
     * @throws Error
     */
    public function endProcess(BpmnProcess $process, bool $interruptSubProcesses = false): void
    {
        $this->log->debug("BPM: endProcess, process {$process->getId()}.");

        $this->rejectActiveFlows($process);

        if (
            !in_array($process->getStatus(), [
                BpmnProcess::STATUS_STARTED,
                BpmnProcess::STATUS_PAUSED,
            ])
        ) {
            throw new Error('Process ' . $process->getId() . " can't be ended because it's not active.");
        }

        if ($interruptSubProcesses) {
            $this->interruptSubProcesses($process);
        }

        $process->setStatus(BpmnProcess::STATUS_ENDED);

        $process->set([
            'endedAt' => date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
        ]);

        $this->entityManager->saveEntity($process, ['modifiedById' => 'system']);

        if (!$process->hasParentProcess()) {
            return;
        }

        /** @var ?BpmnFlowNode $parentFlowNode */
        $parentFlowNode = $this->entityManager
            ->getEntityById(BpmnFlowNode::ENTITY_TYPE, $process->getParentProcessFlowNodeId());

        if (!$parentFlowNode) {
            return;
        }

        $this->completeFlow($parentFlowNode);
    }

    /**
     * @throws Error
     */
    public function escalate(BpmnProcess $process, ?string $escalationCode = null): void
    {
        $this->log->debug("BPM: escalate, process {$process->getId()}.");

        if (
            !in_array($process->getStatus(), [
                BpmnProcess::STATUS_STARTED,
                BpmnProcess::STATUS_PAUSED,
            ])
        ) {
            throw new Error('Process ' . $process->getId() . " can't have an escalation because it's not active.");
        }

        $escalationEventSubProcessFlowNode = $this->prepareEscalationEventSubProcessFlowNode($process, $escalationCode);

        $targetType = $process->getTargetType();
        $targetId = $process->getTargetId();

        if ($escalationEventSubProcessFlowNode) {
            $this->log->info("BPM: escalation event sub-process found");

            $target = $this->entityManager->getEntityById($targetType, $targetId);

            if (!$target) {
                return;
            }

            $isInterrupting = self::isEventSubProcessFlowNodeInterrupting($escalationEventSubProcessFlowNode);

            if ($isInterrupting) {
                $this->interruptProcessByEventSubProcess($process, $escalationEventSubProcessFlowNode);
            }

            $this->processPreparedFlowNode($target, $escalationEventSubProcessFlowNode, $process);

            return;
        }

        if (!$process->hasParentProcess()) {
            return;
        }

        /** @var ?BpmnProcess $parentProcess */
        $parentProcess = $this->entityManager
            ->getEntityById(BpmnProcess::ENTITY_TYPE, $process->getParentProcessId());

        /** @var ?BpmnFlowNode $parentFlowNode */
        $parentFlowNode = $this->entityManager
            ->getEntityById(BpmnFlowNode::ENTITY_TYPE, $process->getParentProcessFlowNodeId());

        if (!$parentProcess || !$parentFlowNode) {
            return;
        }

        $target = $this->entityManager
            ->getEntityById($parentFlowNode->getTargetType(), $parentFlowNode->getTargetId());

        $boundaryFlowNode = $this->prepareBoundaryEscalationFlowNode(
            $parentFlowNode,
            $parentProcess,
            $escalationCode
        );

        if ($boundaryFlowNode && $target) {
            $this->processPreparedFlowNode($target, $boundaryFlowNode, $parentProcess);
        }
    }

    private static function isEventSubProcessFlowNodeInterrupting(BpmnFlowNode $flowNode): bool
    {
        $data = $flowNode->getElementDataItemValue('eventStartData') ?? (object) [];

        return $data->isInterrupting ?? false;
    }

    private static function isEventSubProcessFlowNodeErrorHandler(BpmnFlowNode $flowNode): bool
    {
        return (bool) $flowNode->getDataItemValue('isErrorHandler');
    }

    public function broadcastSignal(string $signal): void
    {
        $this->log->debug("BPM: broadcastSignal");

        $itemList = $this->entityManager
            ->getRDBRepository('BpmnSignalListener')
            ->select(['id', 'flowNodeId'])
            ->where([
                'name' => $signal,
                'isTriggered' => false,
            ])
            ->order('number')
            ->find();

        foreach ($itemList as $item) {
            $this->entityManager
                ->getRDBRepository('BpmnSignalListener')
                ->deleteFromDb($item->getId());
        }

        foreach ($itemList as $item) {
            $flowNodeId = $item->get('flowNodeId');

            /** @var ?BpmnFlowNode $flowNode */
            $flowNode = $this->entityManager->getEntityById(BpmnFlowNode::ENTITY_TYPE, $flowNodeId);

            if (!$flowNode) {
                $this->log->notice("BPM: broadcastSignal, flow node $flowNodeId not found.");

                continue;
            }

            try {
                $this->proceedPendingFlow($flowNode);
            }
            catch (Throwable $e) {
                $this->logException($e);
            }
        }
    }

    /**
     * @throws Error
     */
    public function prepareBoundaryEscalationFlowNode(
        BpmnFlowNode $flowNode,
        BpmnProcess $process,
        ?string $escalationCode = null
    ): ?BpmnFlowNode {

        $attachedElementIdList = $process->getAttachedToFlowNodeElementIdList($flowNode);

        $found1Id = null;
        $found2Id = null;

        foreach ($attachedElementIdList as $id) {
            $item = $process->getElementDataById($id);

            if (!isset($item->type)) {
                continue;
            }

            if ($item->type === 'eventIntermediateEscalationBoundary') {
                if (!$escalationCode) {
                    if (empty($item->escalationCode)) {
                        $found1Id = $id;

                        break;
                    }
                }
                else {
                    if (empty($item->escalationCode)) {
                        if (!$found2Id) {
                            $found2Id = $id;
                        }
                    }
                    else {
                        if ($item->escalationCode == $escalationCode) {
                            $found1Id = $id;

                            break;
                        }
                    }
                }
            }
        }

        $elementId = $found1Id ?: $found2Id;

        if (!$elementId) {
            return null;
        }

        $target = $this->entityManager
            ->getEntityById($flowNode->getTargetType(), $flowNode->getTargetId());

        if (!$target) {
            return null;
        }

        return $this->prepareFlow(
            $target,
            $process,
            $elementId,
            $flowNode->getId(),
            $flowNode->getElementType()
        );
    }

    /**
     * @throws Error
     */
    public function endProcessWithError(
        BpmnProcess $process,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): void {

        $this->log->debug("BPM: endProcessWithError, process {$process->getId()}.");

        $this->rejectActiveFlows($process);

        if (
            !in_array($process->getStatus(), [
                BpmnProcess::STATUS_STARTED,
                BpmnProcess::STATUS_PAUSED,
            ])
        ) {
            throw new Error('Process ' . $process->getId() . " can't be ended because it's not active.");
        }

        $this->interruptSubProcesses($process);

        $process->setStatus(BpmnProcess::STATUS_ENDED);

        $process->set([
            'endedAt' => date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
        ]);

        $this->entityManager->saveEntity($process, ['modifiedById' => 'system']);

        $this->triggerError($process, $errorCode, $errorMessage);
    }

    /**
     * @throws Error
     */
    private function triggerError(
        BpmnProcess $process,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        bool $skipHandler = false
    ): void {

        $this->log->info("BPM: triggerError");

        $errorEventSubProcessFlowNode = !$skipHandler ?
            $this->prepareErrorEventSubProcessFlowNode($process, $errorCode) :
            null;

        if ($errorEventSubProcessFlowNode) {
            $this->log->info("BPM: error event sub-process found");

            $errorEventSubProcessFlowNode->setDataItemValue('caughtErrorCode', $errorCode);
            $errorEventSubProcessFlowNode->setDataItemValue('caughtErrorMessage', $errorMessage);

            $this->entityManager->saveEntity($errorEventSubProcessFlowNode);

            $target = $this->entityManager->getEntityById($process->getTargetType(), $process->getTargetId());

            if ($target) {
                $this->processPreparedFlowNode($target, $errorEventSubProcessFlowNode, $process);
            }

            return;
        }

        if (!$process->hasParentProcess()) {
            return;
        }

        /** @var ?BpmnProcess $parentProcess */
        $parentProcess = $this->entityManager
            ->getEntityById(BpmnProcess::ENTITY_TYPE, $process->getParentProcessId());

        /** @var ?BpmnFlowNode $parentFlowNode */
        $parentFlowNode = $this->entityManager
            ->getEntityById(BpmnFlowNode::ENTITY_TYPE, $process->getParentProcessFlowNodeId());

        if (!$parentProcess || !$parentFlowNode) {
            return;
        }

        $parentFlowNode->setDataItemValue('errorCode', $errorCode);
        $parentFlowNode->setDataItemValue('errorMessage', $errorMessage);
        $parentFlowNode->setDataItemValue('errorTriggered', true);

        $this->entityManager->saveEntity($parentFlowNode);

        $isInterruptingSubProcess =
            $parentFlowNode->getElementType() === 'eventSubProcess' &&
            (
                self::isEventSubProcessFlowNodeErrorHandler($parentFlowNode) ||
                self::isEventSubProcessFlowNodeInterrupting($parentFlowNode)
            );

        if ($isInterruptingSubProcess) {
            $parentFlowNode->setStatus(BpmnFlowNode::STATUS_PROCESSED);
            $this->entityManager->saveEntity($parentFlowNode);

            $this->triggerError($parentProcess, $errorCode, $errorMessage, true);

            return;
        }

        $this->failFlow($parentFlowNode);
    }

    private function interruptSubProcesses(BpmnProcess $process): void
    {
        $subProcesses = $this->entityManager
            ->getRDBRepositoryByClass(BpmnProcess::class)
            ->where([
                'parentProcessId' => $process->getId(),
                'status' => [
                    BpmnProcess::STATUS_STARTED,
                    BpmnProcess::STATUS_PAUSED,
                ],
            ])
            ->find();

        foreach ($subProcesses as $subProcess) {
            try {
                $this->interruptProcess($subProcess);
            } catch (Throwable $e) {
                $this->log->error($e->getMessage(), ['exception' => $e]);
            }
        }
    }

    /**
     * @throws Error
     */
    public function interruptProcess(BpmnProcess $process): void
    {
        $this->log->debug("BPM: interruptProcess, process {$process->getId()}.");

        /** @var ?BpmnProcess $process */
        $process = $this->entityManager->getEntityById(BpmnProcess::ENTITY_TYPE, $process->getId());

        if (
            !$process ||
            !in_array($process->getStatus(), [
                BpmnProcess::STATUS_STARTED,
                BpmnProcess::STATUS_PAUSED,
            ])
        ) {
            throw new Error("Process {$process->getId()} can't be interrupted because it's not active.");
        }

        $this->rejectActiveFlows($process);

        $process->setStatus(BpmnProcess::STATUS_INTERRUPTED);

        $this->entityManager->saveEntity($process, ['skipModifiedBy' => true]);

        $this->interruptSubProcesses($process);
    }

    /**
     * @throws Error
     */
    public function interruptProcessByEventSubProcess(BpmnProcess $process, BpmnFlowNode $interruptingFlowNode): void
    {
        $this->log->debug("BPM: interruptProcessByEventSubProcess, process {$process->getId()}.");

        /** @var ?BpmnProcess $process */
        $process = $this->entityManager
            ->getEntityById(BpmnProcess::ENTITY_TYPE, $process->getId());

        if (
            !$process ||
            !in_array($process->getStatus(), [
                BpmnProcess::STATUS_STARTED,
                BpmnProcess::STATUS_PAUSED,
            ])
        ) {
            throw new Error('Process ' . $process->getId() . " can't be interrupted because it's not active.");
        }

        $this->rejectActiveFlows($process, $interruptingFlowNode->getId());

        $process->setStatus(BpmnProcess::STATUS_INTERRUPTED);

        $this->entityManager->saveEntity($process, ['skipModifiedBy' => true]);

        $this->interruptSubProcesses($process);
    }

    /**
     * @throws Error
     */
    public function prepareEscalationEventSubProcessFlowNode(
        BpmnProcess $process,
        ?string $escalationCode = null
    ): ?BpmnFlowNode {

        $found1Id = null;
        $found2Id = null;

        foreach ($this->getProcessElementEventSubProcessIdList($process) as $id) {
            $item = $process->getElementDataById($id);

            if (($item->type ?? null) !== 'eventSubProcess') {
                continue;
            }

            $item = $item->eventStartData ?? (object) [];

            if (($item->type ?? null) !== 'eventStartEscalation') {
                continue;
            }

            if (!$escalationCode) {
                if (empty($item->escalationCode)) {
                    $found1Id = $id;
                    break;
                }
            }
            else {
                if (empty($item->escalationCode)) {
                    if (!$found2Id) {
                        $found2Id = $id;
                    }
                } else {
                    if ($item->escalationCode == $escalationCode) {
                        $found1Id = $id;

                        break;
                    }
                }
            }
        }

        $elementId = $found1Id ?: $found2Id;

        if (!$elementId) {
            return null;
        }

        $target = $this->entityManager->getEntityById($process->getTargetType(), $process->getTargetId());

        if (!$target) {
            return null;
        }

        return $this->prepareFlow(
            $target,
            $process,
            $elementId,
            null,
            null,
            null,
            true
        );
    }

    /**
     * @throws Error
     */
    public function prepareErrorEventSubProcessFlowNode(
        BpmnProcess $process,
        ?string $errorCode = null
    ): ?BpmnFlowNode {

        $found1Id = null;
        $found2Id = null;

        foreach ($this->getProcessElementEventSubProcessIdList($process) as $id) {
            $item = $process->getElementDataById($id);

            if (($item->type ?? null) !== 'eventSubProcess') {
                continue;
            }

            $item = $item->eventStartData ?? (object) [];

            if (($item->type ?? null) !== 'eventStartError') {
                continue;
            }

            if (!$errorCode) {
                if (empty($item->errorCode)) {
                    $found1Id = $id;

                    break;
                }
            } else {
                if (empty($item->errorCode)) {
                    if (!$found2Id) {
                        $found2Id = $id;
                    }
                } else {
                    if ($item->errorCode == $errorCode) {
                        $found1Id = $id;

                        break;
                    }
                }
            }
        }

        $elementId = $found1Id ?: $found2Id;

        if (!$elementId) {
            return null;
        }

        $target = $this->entityManager->getEntityById($process->getTargetType(), $process->getTargetId());

        if (!$target) {
            return null;
        }

        $flowNode = $this->prepareFlow(
            $target,
            $process,
            $elementId,
            null,
            null,
            null,
            true
        );

        if (!$flowNode) {
            return null;
        }

        $flowNode->setDataItemValue('isErrorHandler', true);
        $this->entityManager->saveEntity($flowNode);

        return $flowNode;
    }

    /**
     * @throws Error
     */
    public function prepareBoundaryErrorFlowNode(
        BpmnFlowNode $flowNode,
        BpmnProcess  $process,
        ?string $errorCode = null
    ): ?BpmnFlowNode {

        $attachedElementIdList = $process->getAttachedToFlowNodeElementIdList($flowNode);

        $found1Id = null;
        $found2Id = null;

        foreach ($attachedElementIdList as $id) {
            $item = $process->getElementDataById($id);

            if (!isset($item->type)) {
                continue;
            }

            if ($item->type === 'eventIntermediateErrorBoundary') {
                if (!$errorCode) {
                    if (empty($item->errorCode)) {
                        $found1Id = $id;

                        break;
                    }
                } else {
                    if (empty($item->errorCode)) {
                        if (!$found2Id) {
                            $found2Id = $id;
                        }
                    } else {
                        if ($item->errorCode == $errorCode) {
                            $found1Id = $id;

                            break;
                        }
                    }
                }
            }
        }

        $errorElementId = $found1Id ?: $found2Id;

        if (!$errorElementId) {
            return null;
        }

        $target = $this->entityManager->getEntityById($flowNode->getTargetType(), $flowNode->getTargetId());

        if (!$target) {
            return null;
        }

        return $this->prepareFlow(
            $target,
            $process,
            $errorElementId,
            $flowNode->getId(),
            $flowNode->get('elementType')
        );
    }

    public function stopProcess(BpmnProcess $process): void
    {
        $this->log->debug("BPM: stopProcess, process {$process->getId()}.");

        $this->rejectActiveFlows($process);
        $this->interruptSubProcesses($process);

        $process->set([
            'endedAt' => date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
        ]);

        if ($process->getStatus() !== BpmnProcess::STATUS_STOPPED) {
            $process->setStatus(BpmnProcess::STATUS_STOPPED);
        }

        $this->entityManager->saveEntity($process, [
            'modifiedById' => 'system',
            'skipStopProcess' => true,
        ]);
    }

    private function rejectActiveFlows(BpmnProcess $process, ?string $exclusionFlowNodeId = null): void
    {
        $this->log->debug("BPM: rejectActiveFlows, process {$process->getId()}.");

        $where = [
            'status!=' => [
                BpmnFlowNode::STATUS_PROCESSED,
                BpmnFlowNode::STATUS_REJECTED,
                BpmnFlowNode::STATUS_FAILED,
                BpmnFlowNode::STATUS_INTERRUPTED,
            ],
            'processId' => $process->getId(),
            'elementType!=' => 'eventSubProcess',
        ];

        if ($exclusionFlowNodeId) {
            $where['id!='] = $exclusionFlowNodeId;
        }

        $flowNodeList = $this->entityManager
            ->getRDBRepositoryByClass(BpmnFlowNode::class)
            ->where($where)
            ->find();

        foreach ($flowNodeList as $flowNode) {
            if ($flowNode->getStatus() === BpmnFlowNode::STATUS_IN_PROCESS) {
                $flowNode->setStatus(BpmnFlowNode::STATUS_INTERRUPTED);
            } else {
                $flowNode->setStatus(BpmnFlowNode::STATUS_REJECTED);
            }

            $this->entityManager->saveEntity($flowNode);
        }

        $target = $this->entityManager->getEntityById($process->getTargetType(), $process->getTargetId());

        if ($target) {
            foreach ($flowNodeList as $flowNode) {
                if ($flowNode->getStatus() === BpmnFlowNode::STATUS_INTERRUPTED) {
                    $impl = $this->getFlowNodeImplementation($target, $flowNode, $process);

                    $impl->cleanupInterrupted();
                }
            }
        }
    }

    private function logException(Throwable $e): void
    {
        $this->log->error($e->getMessage(), ['exception' => $e]);
    }

    private function saveFlowNode(BpmnFlowNode $flowNode): void
    {
        $this->entityManager->saveEntity($flowNode);
    }

    /**
     * @throws Error
     */
    private function getProcessById(string $id): BpmnProcess
    {
        /** @var ?BpmnProcess $process */
        $process = $this->entityManager->getEntityById(BpmnProcess::ENTITY_TYPE, $id);

        if (!$process) {
            throw new Error("Could not get process $id.");
        }

        return $process;
    }

    /**
     * @return string[]
     * @throws Error
     */
    public function compensate(BpmnProcess $process, ?string $activityId): array
    {
        $builder = $this->entityManager
            ->getRDBRepository(BpmnFlowNode::ENTITY_TYPE)
            ->where([
                'processId' => $process->getId(),
                'status' => BpmnFlowNode::STATUS_PROCESSED,
                'elementType' => [
                    'subProcess',
                    'callActivity',
                    'task',
                    'taskScript',
                    'taskUser',
                    'taskSendMessage',
                ]
            ])
            ->order('number', 'DESC');

        if ($activityId) {
            $builder->where(['elementId' => $activityId]);
        }

        /** @var Collection<BpmnFlowNode> $activityNodes */
        $activityNodes = $builder->find();

        if (iterator_count($activityNodes) === 0) {
            return [];
        }

        $compensationNodeIds = [];
        $compensationNodes = [];

        foreach ($activityNodes as $activityNode) {
            $compensationNode =
                $this->prepareBoundaryCompensation($process, $activityNode) ??
                $this->prepareSubProcessCompensation($activityNode);

            if (!$compensationNode) {
                continue;
            }

            $compensationNodes[] = $compensationNode;
            $compensationNodeIds[] = $compensationNode->getId();
        }

        foreach ($compensationNodes as $node) {
            $itemProcess = $process;

            if ($node->getElementType() === 'eventSubProcess') {
                $itemProcess = $this->entityManager
                    ->getRDBRepositoryByClass(BpmnProcess::class)
                    ->getById($node->getProcessId());

                if (!$itemProcess) {
                    throw new Error("No process.");
                }
            }

            $target = $this->entityManager->getEntityById($node->getTargetType(), $node->getTargetId());

            if (!$target) {
                throw new Error("No target {$node->getTargetType()} {$node->getTargetId()}.");
            }

            $this->processPreparedFlowNode($target, $node, $itemProcess);
        }

        return $compensationNodeIds;
    }

    /**
     * @throws Error
     */
    private function prepareBoundaryCompensation(BpmnProcess $process, BpmnFlowNode $activityNode): ?BpmnFlowNode
    {
        $attachedElementIdList = $process->getAttachedToFlowNodeElementIdList($activityNode);

        if ($activityNode->getElementDataItemValue('isMultiInstance')) {
            return null;
        }

        $compensationElementId = null;

        foreach ($attachedElementIdList as $elementId) {
            $item = $process->getElementDataById($elementId);

            if (($item->type ?? null) === 'eventIntermediateCompensationBoundary') {
                /** @var ?string $compensationElementId */
                $compensationElementId = ($item->nextElementIdList ?? [])[0] ?? null;

                break;
            }
        }

        if (!$compensationElementId) {
            return null;
        }

        $target = $this->entityManager->getEntityById($process->getTargetType(), $process->getTargetId());

        if (!$target) {
            return null;
        }

        $node = $this->prepareFlow(
            $target,
            $process,
            $compensationElementId,
            null,
            null,
            null,
            true
        );

        if (!$node) {
            return null;
        }

        $node->setDataItemValue('compensatedFlowNodeId', $activityNode->getId());
        $this->entityManager->saveEntity($node);

        return $node;
    }

    /**
     * @throws Error
     */
    private function prepareSubProcessCompensation(BpmnFlowNode $activityNode): ?BpmnFlowNode
    {
        if ($activityNode->getElementType() !== 'subProcess') {
            return null;
        }

        /** @var ?BpmnProcess $subProcess */
        $subProcess = $this->entityManager
            ->getRDBRepository(BpmnProcess::ENTITY_TYPE)
            ->where([
                'parentProcessFlowNodeId' => $activityNode->getId()
            ])
            ->findOne();

        if (!$subProcess) {
            return null;
        }

        $elementId = null;

        foreach ($this->getProcessElementEventSubProcessIdList($subProcess) as $id) {
            $item = $subProcess->getElementDataById($id);

            if (($item->type ?? null) !== 'eventSubProcess') {
                continue;
            }

            $startItem = $item->eventStartData ?? (object)[];

            if (($startItem->type ?? null) !== 'eventStartCompensation') {
                continue;
            }

            $elementId = $id;

            break;
        }

        if (!$elementId) {
            return null;
        }

        $target = $this->entityManager->getEntityById($subProcess->getTargetType(), $subProcess->getTargetId());

        if (!$target) {
            return null;
        }

        return $this->prepareFlow(
            target: $target,
            process: $subProcess,
            elementId: $elementId,
            allowEndedProcess: true,
        );
    }
}
