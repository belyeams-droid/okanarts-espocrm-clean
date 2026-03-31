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

namespace Espo\Modules\Advanced\Core\ORM;

use Espo\Core\FieldProcessing\ListLoadProcessor;
use Espo\Core\FieldProcessing\Loader\Params as LoaderParams;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\ORM\EntityFactory;
use Espo\ORM\Collection;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

use PDOStatement;
use PDO;
use IteratorAggregate;
use stdClass;
use Traversable;

/**
 * @implements IteratorAggregate<int, Entity>
 * @implements Collection<Entity>
 */
class SthCollection implements IteratorAggregate, Collection
{
    /**
     * @param array<string, array<string, mixed>> $attributeDefs Additional attributes.
     * @param string[] $linkMultipleFieldList
     * @param object{entityType: string, name: string}[] $foreignLinkFieldDataList
     */
    public function __construct(
        private PDOStatement $sth,
        private string $entityType,
        private EntityManager $entityManager,
        private $attributeDefs,
        private $linkMultipleFieldList,
        private $foreignLinkFieldDataList,
        private CustomEntityFactory $customEntityFactory,
        private ListLoadProcessor $listLoadProcessor,
    ) {}

    public function getIterator(): Traversable
    {
        return (function () {
            while ($row = $this->sth->fetch(PDO::FETCH_ASSOC)) {
                $rowData = [];

                foreach ($row as $key => $value) {
                    /** @var string $attribute */
                    $attribute = str_replace('.', '_', $key);

                    $rowData[$attribute] = $value;
                }

                $select = array_keys($rowData);

                foreach ($this->linkMultipleFieldList as $it) {
                    $select[] = $it . 'Ids';
                    $select[] = $it . 'Names';
                }

                $entity = $this->prepareEntity();

                $entity->set($rowData);
                $entity->setAsFetched();

                $fieldLoaderParams = LoaderParams::create()->withSelect($select);

                $this->listLoadProcessor->process($entity, $fieldLoaderParams);

                $this->loadNoLoadLinkMultiple($entity);

                foreach ($this->foreignLinkFieldDataList as $item) {
                    $foreignId = $entity->get($item->name . 'Id');

                    if (!$foreignId) {
                        continue;
                    }

                    $foreignEntity = $this->entityManager
                        ->getRDBRepository($item->entityType)
                        ->where(['id' => $foreignId])
                        ->select(['name'])
                        ->findOne();

                    if (!$foreignEntity) {
                        continue;
                    }

                    $entity->set($item->name . 'Name', $foreignEntity->get('name'));
                }

                $entity->setAsFetched();

                yield $entity;
            }
        })();
    }

    /**
     * @return stdClass[]
     */
    public function getValueMapList(): array
    {
        $list = [];

        foreach ($this as $entity) {
            $list[] = $entity->getValueMap();
        }

        return $list;
    }

    private function prepareEntity(): Entity
    {
        $factory = $this->entityManager->getEntityFactory();

        /** @noinspection PhpConditionAlreadyCheckedInspection */
        if (
            $factory instanceof EntityFactory &&
            /** @phpstan-ignore-next-line function.alreadyNarrowedType */
            method_exists($factory, 'createWithAdditionalAttributes')
        ) {
            /** @noinspection PhpInternalEntityUsedInspection */
            return $factory->createWithAdditionalAttributes($this->entityType, $this->attributeDefs);
        }

        // Before Espo v9.1.7.
        return $this->customEntityFactory->create($this->entityType, $this->attributeDefs);
    }

    private function loadNoLoadLinkMultiple(Entity $entity): void
    {
        $entityDefs = $this->entityManager->getDefs()->getEntity($this->entityType);

        if (!$entity instanceof CoreEntity) {
            return;
        }

        foreach ($this->linkMultipleFieldList as $field) {
            $fieldDefs = $entityDefs->tryGetField($field);

            if (!$fieldDefs) {
                continue;
            }

            if (!$fieldDefs->getParam('noLoad')) {
                continue;
            }

            if (!$entity->hasLinkMultipleField($field)) {
                continue;
            }

            /** @noinspection PhpInternalEntityUsedInspection */
            $entity->loadLinkMultipleField($field);
        }
    }
}
