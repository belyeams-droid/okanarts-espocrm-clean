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

namespace Espo\Modules\Advanced\Core\Bpmn\Elements;

use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Log;
use Espo\Core\WebSocket\Submission;
use Espo\Modules\Advanced\Core\Bpmn\BpmnManager;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\Modules\Advanced\Entities\BpmnFlowNode;
use Espo\Core\Exceptions\Error;
use Espo\Core\Container;
use Espo\Core\Utils\Metadata;
use Espo\ORM\EntityManager;
use Espo\Core\ORM\Entity;
use RuntimeException;
use stdClass;

abstract class Base
{
    /**
     * // Do not rename the parameters. Mapped by name.
     */
    public function __construct(
        protected Container $container,
        protected BpmnManager $manager,
        protected Entity $target,
        protected BpmnFlowNode $flowNode,
        protected BpmnProcess $process,
    ) {}

    protected function getContainer(): Container
    {
        return $this->container;
    }

    protected function getLog(): Log
    {
        return $this->container->getByClass(Log::class);
    }

    protected function getEntityManager(): EntityManager
    {
        return $this->container->getByClass(EntityManager::class);
    }

    protected function getMetadata(): Metadata
    {
        return $this->container->getByClass(Metadata::class);
    }

    protected function getFormulaManager(): FormulaManager
    {
        return $this->getContainer()->getByClass(FormulaManager::class);
    }

    private function getWebSocketSubmission(): Submission
    {
        // Important: The service was not bound prior v9.0.0.
        /** @var Submission */
        return $this->getContainer()->get('webSocketSubmission');
    }

    private function getConfig(): Config
    {
        return $this->getContainer()->getByClass(Config::class);
    }

    protected function getProcess(): BpmnProcess
    {
        return $this->process;
    }

    protected function getFlowNode(): BpmnFlowNode
    {
        return $this->flowNode;
    }

    protected function getTarget(): Entity
    {
        return $this->target;
    }

    protected function getManager(): BpmnManager
    {
        return $this->manager;
    }

    protected function refresh(): void
    {
        $this->refreshFlowNode();
        $this->refreshProcess();
        $this->refreshTarget();
    }

    protected function refreshFlowNode(): void
    {
        if (!$this->flowNode->hasId()) {
            return;
        }

        $flowNode = $this->getEntityManager()->getEntityById(BpmnFlowNode::ENTITY_TYPE, $this->flowNode->getId());

        if ($flowNode) {
            $this->flowNode->set($flowNode->getValueMap());
            $this->flowNode->setAsFetched();
        }
    }

    protected function refreshProcess(): void
    {
        if (!$this->process->hasId()) {
            return;
        }

        $process = $this->getEntityManager()->getEntityById(BpmnProcess::ENTITY_TYPE, $this->process->getId());

        if ($process) {
            $this->process->set($process->getValueMap());
            $this->process->setAsFetched();
        }
    }

    protected function saveProcess(): void
    {
        $this->getEntityManager()->saveEntity($this->getProcess(), ['silent' => true]);
    }

    protected function saveFlowNode(): void
    {
        $this->getEntityManager()->saveEntity($this->getFlowNode());

        $this->submitWebSocket();
    }

    private function submitWebSocket(): void
    {
        if (!$this->getConfig()->get('useWebSocket')) {
            return;
        }

        if (!$this->getProcess()->hasId()) {
            return;
        }

        $entityType = $this->getProcess()->getEntityType();
        $id = $this->getProcess()->getId();

        $topic = "recordUpdate.$entityType.$id";

        $this->getWebSocketSubmission()->submit($topic);
    }

    protected function refreshTarget(): void
    {
        if (!$this->target->hasId()) {
            return;
        }

        $target = $this->getEntityManager()->getEntityById($this->target->getEntityType(), $this->target->getId());

        if ($target) {
            $this->target->set($target->getValueMap());
            $this->target->setAsFetched();
        }
    }

    public function isProcessable(): bool
    {
        return true;
    }

    public function beforeProcess(): void
    {}

    /**
     * @throws Error
     */
    abstract public function process(): void;

    /**
     * @throws Error
     */
    public function proceedPending(): void
    {
        $flowNode = $this->getFlowNode();

        throw new Error("BPM Flow: Can't proceed element ". $flowNode->getElementType() . " " .
            $flowNode->get('elementId') . " in flowchart " . $flowNode->getFlowchartId() . ".");
    }

    /**
     * @throws Error
     */
    protected function getElementId(): string
    {
        $flowNode = $this->getFlowNode();
        $elementId = $flowNode->getElementId();

        if (!$elementId) {
            throw new Error("BPM Flow: No id for element " . $flowNode->getElementType() .
                " in flowchart " . $flowNode->getFlowchartId() . ".");
        }

        return $elementId;
    }

    protected function isInNormalFlow(): bool
    {
        return true;
    }

    protected function hasNextElementId(): bool
    {
        $flowNode = $this->getFlowNode();

        $item = $flowNode->getElementData();
        $nextElementIdList = $item->nextElementIdList;

        if (!count($nextElementIdList)) {
            return false;
        }

        return true;
    }

    protected function getNextElementId(): ?string
    {
        $flowNode = $this->getFlowNode();

        if (!$this->hasNextElementId()) {
            return null;
        }

        $item = $flowNode->getElementData();
        $nextElementIdList = $item->nextElementIdList;

        return $nextElementIdList[0];
    }

    /**
     * @return mixed
     */
    public function getAttributeValue(string $name)
    {
        $item = $this->getFlowNode()->getElementData();

        if (!property_exists($item, $name)) {
            return null;
        }

        return $item->$name;
    }

    protected function getVariables(): stdClass
    {
        return $this->getProcess()->getVariables() ?? (object) [];
    }

    /**
     * @todo Revise the need.
     */
    protected function getClonedVariables(): stdClass
    {
        return clone $this->getVariables();
    }

    protected function getVariablesForFormula(): stdClass
    {
        $variables = $this->getClonedVariables();

        $variables->__createdEntitiesData = $this->getCreatedEntitiesData();
        $variables->__processEntity = $this->getProcess();
        $variables->__targetEntity = $this->getTarget();

        return $variables;
    }

    protected function addCreatedEntityDataToVariables(stdClass $variables): void
    {
        $variables->__createdEntitiesData = $this->getCreatedEntitiesData();
    }

    protected function sanitizeVariables(stdClass $variables): void
    {
        unset($variables->__createdEntitiesData);
        unset($variables->__processEntity);
        unset($variables->__targetEntity);
    }

    protected function setProcessed(): void
    {
        $this->getFlowNode()->set([
            'status' => BpmnFlowNode::STATUS_PROCESSED,
            'processedAt' => date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT)
        ]);
        $this->saveFlowNode();
    }

    /**
     * @throws Error
     */
    protected function setInterrupted(): void
    {
        $this->getFlowNode()->set([
            'status' => BpmnFlowNode::STATUS_INTERRUPTED,
        ]);
        $this->saveFlowNode();

        $this->endProcessFlow();
    }

    /**
     * @throws Error
     */
    protected function setFailed(): void
    {
        $this->getFlowNode()->set([
            'status' => BpmnFlowNode::STATUS_FAILED,
            'processedAt' => date(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT),
        ]);
        $this->saveFlowNode();

        $this->endProcessFlow();
    }

    /**
     * @throws Error
     */
    protected function setRejected(): void
    {
        $this->getFlowNode()->set([
            'status' => BpmnFlowNode::STATUS_REJECTED,
        ]);
        $this->saveFlowNode();

        $this->endProcessFlow();
    }

    /**
     * @throws Error
     */
    public function fail(): void
    {
        $this->setFailed();
    }

    /**
     * @throws Error
     */
    public function interrupt(): void
    {
        $this->setInterrupted();
    }

    public function cleanupInterrupted(): void
    {}

    /**
     * @throws Error
     */
    public function complete(): void
    {
        throw new Error("Can't complete " . $this->getFlowNode()->getElementType() . ".");
    }

    /**
     * @param string|false|null $divergentFlowNodeId
     * @throws Error
     */
    protected function prepareNextFlowNode(
        ?string $nextElementId = null,
        $divergentFlowNodeId = false
    ): ?BpmnFlowNode {

        $flowNode = $this->getFlowNode();

        if (!$nextElementId) {
            if (!$this->isInNormalFlow()) {
                return null;
            }

            if (!$this->hasNextElementId()) {
                $this->endProcessFlow();

                return null;
            }

            $nextElementId = $this->getNextElementId();
        }

        if ($divergentFlowNodeId === false) {
            $divergentFlowNodeId = $flowNode->getDivergentFlowNodeId();
        }

        return $this->getManager()->prepareFlow(
            $this->getTarget(),
            $this->getProcess(),
            $nextElementId,
            $flowNode->get('id'),
            $flowNode->getElementType(),
            $divergentFlowNodeId
        );
    }

    /**
     * @param string|false|null $divergentFlowNodeId
     * @throws Error
     */
    protected function processNextElement(
        ?string $nextElementId = null,
        $divergentFlowNodeId = false,
        bool $dontSetProcessed = false
    ): ?BpmnFlowNode {

        $nextFlowNode = $this->prepareNextFlowNode($nextElementId, $divergentFlowNodeId);

        if (!$dontSetProcessed) {
            $this->setProcessed();
        }

        if ($nextFlowNode) {
            $this->getManager()->processPreparedFlowNode(
                $this->getTarget(),
                $nextFlowNode,
                $this->getProcess()
            );
        }

        return $nextFlowNode;
    }

    /**
     * @throws Error
     */
    protected function processPreparedNextFlowNode(BpmnFlowNode $flowNode): void
    {
        $this->getManager()->processPreparedFlowNode($this->getTarget(), $flowNode, $this->getProcess());
    }

    /**
     * @throws Error
     */
    protected function endProcessFlow(): void
    {
        $this->getManager()->endProcessFlow($this->getFlowNode(), $this->getProcess());
    }

    protected function getCreatedEntitiesData(): stdClass
    {
        $createdEntitiesData = $this->getProcess()->get('createdEntitiesData');

        if (!$createdEntitiesData) {
            $createdEntitiesData = (object) [];
        }

        return $createdEntitiesData;
    }

    protected function getCreatedEntity(string $target): ?Entity
    {
        $createdEntitiesData = $this->getCreatedEntitiesData();

        if (str_starts_with($target, 'created:')) {
            $alias = substr($target, 8);
        } else {
            $alias = $target;
        }

        if (!property_exists($createdEntitiesData, $alias)) {
            return null;
        }

        if (empty($createdEntitiesData->$alias->entityId) || empty($createdEntitiesData->$alias->entityType)) {
            return null;
        }

        $entityType = $createdEntitiesData->$alias->entityType;
        $entityId = $createdEntitiesData->$alias->entityId;

        $entity = $this->getEntityManager()->getEntityById($entityType, $entityId);

        if (!$entity) {
            return null;
        }

        if (!$entity instanceof Entity) {
            throw new RuntimeException();
        }

        return $entity;
    }

    /**
     * @throws FormulaError
     * @throws Error
     */
    protected function getSpecificTarget(?string $target): ?Entity
    {
        $entity = $this->getTarget();

        if (!$target || $target == 'targetEntity') {
            return $entity;
        }

        if (str_starts_with($target, 'created:')) {
            return $this->getCreatedEntity($target);
        }

        if (str_starts_with($target, 'record:')) {
            $entityType = substr($target, 7);

            $targetIdExpression = $this->getAttributeValue('targetIdExpression');

            if (!$targetIdExpression) {
                return null;
            }

            if (str_ends_with($targetIdExpression, ';')) {
                $targetIdExpression = substr($targetIdExpression, 0, -1);
            }

            $id = $this->getFormulaManager()->run(
                $targetIdExpression,
                $this->getTarget(),
                $this->getVariablesForFormula()
            );

            if (!$id) {
                return null;
            }

            if (!is_string($id)) {
                throw new Error("BPM: Target-ID evaluated not to string.");
            }

            $entity = $this->getEntityManager()->getEntityById($entityType, $id);

            if (!$entity) {
                return null;
            }

            if (!$entity instanceof Entity) {
                throw new RuntimeException();
            }

            return $entity;
        }

        if (str_starts_with($target, 'link:')) {
            $link = substr($target, 5);

            $linkList = explode('.', $link);

            $pointerEntity = $entity;

            $notFound = false;

            foreach ($linkList as $link) {
                $type = $this->getMetadata()
                    ->get(['entityDefs', $pointerEntity->getEntityType(), 'links', $link, 'type']);

                if (empty($type)) {
                    $notFound = true;

                    break;
                }

                $pointerEntity = $this->getEntityManager()
                    ->getRDBRepository($pointerEntity->getEntityType())
                    ->getRelation($pointerEntity, $link)
                    ->findOne();

                if (!$pointerEntity instanceof Entity) {
                    $notFound = true;

                    break;
                }
            }

            if (!$notFound) {
                if ($pointerEntity && !$pointerEntity instanceof Entity) {
                    throw new RuntimeException();
                }

                return $pointerEntity;
            }
        }

        return null;
    }
}
