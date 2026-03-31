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

namespace Espo\Modules\Advanced\Core\Bpmn\Utils;

use Espo\Core\Exceptions\Error;
use Espo\Core\Container;
use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Core\Formula\Manager as FormulaManager;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\Advanced\Core\Workflow\Conditions\Base;
use Espo\ORM\Entity;

use RuntimeException;
use stdClass;

class ConditionManager
{
    private ?stdClass $createdEntitiesData = null;

    private const TYPE_AND = 'and';
    private const TYPE_OR = 'or';

    /** @var string[]  */
    private array $requiredOptionList = [
        'comparison',
        'fieldToCompare',
    ];

    public function __construct(
        private Container $container,
        private InjectableFactory $injectableFactory,
    ) {}

    /**
     * @param ?stdClass[] $conditionsAll
     * @param ?stdClass[] $conditionsAny
     * @throws Error
     * @throws FormulaError
     */
    public function check(
        Entity $entity,
        ?array $conditionsAll = null,
        ?array $conditionsAny = null,
        ?string $conditionsFormula = null,
        ?stdClass $variables = null,
    ): bool {

        if (!$entity instanceof CoreEntity) {
            throw new RuntimeException();
        }

        $result = true;

        if (!is_null($conditionsAll)) {
            $result &= $this->checkConditionsAll($entity, $conditionsAll);
        }

        if (!is_null($conditionsAny)) {
            $result &= $this->checkConditionsAny($entity, $conditionsAny);
        }

        if (!empty($conditionsFormula)) {
            $result &= $this->checkConditionsFormula($entity, $conditionsFormula, $variables);
        }

        return (bool) $result;
    }

    /**
     * @param stdClass[] $items
     * @throws Error
     */
    public function checkConditionsAll(CoreEntity $entity, array $items): bool
    {
        foreach ($items as $item) {
            if (!$this->processCheck($entity, $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param stdClass[] $items
     * @throws Error
     */
    public function checkConditionsAny(CoreEntity $entity, array $items): bool
    {
        if ($items === []) {
            return true;
        }

        foreach ($items as $item) {
            if ($this->processCheck($entity, $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws FormulaError
     */
    public function checkConditionsFormula(Entity $entity, ?string $formula, ?stdClass $variables = null): bool
    {
        if (empty($formula)) {
            return true;
        }

        $formula = trim($formula, " \t\n\r");

        if (str_ends_with($formula, ';')) {
            $formula = substr($formula, 0, -1);
        }

        if (empty($formula)) {
            return true;
        }

        $o = (object) [];

        $o->__targetEntity = $entity;

        if ($variables) {
            foreach (get_object_vars($variables) as $name => $value) {
                $o->$name = $value;
            }
        }

        if ($this->createdEntitiesData) {
            $o->__createdEntitiesData = $this->createdEntitiesData;
        }

        return $this->getFormulaManager()->run($formula, $entity, $o);
    }

    /**
     * @throws Error
     */
    private function processCheck(CoreEntity $entity, stdClass $item): bool
    {
        if (!$this->validate($item)) {
            return false;
        }

        $type = $item->type ?? null;

        if ($type === self::TYPE_AND || $type === self::TYPE_OR) {
            /** @var stdClass[] $value */
            $value = $item->value ?? [];

            if ($type === self::TYPE_OR) {
                return $this->checkConditionsAny($entity, $value);
            }

            return $this->checkConditionsAll($entity, $value);
        }

        $impl = $this->getConditionImplementation($item->comparison);

        return $impl->process($entity, $item, $this->createdEntitiesData);
    }

    /**
     * @throws Error
     */
    private function getConditionImplementation(string $name): Base
    {
        $name = ucfirst($name);
        $name = str_replace("\\", "", $name);

        $className = 'Espo\\Modules\\Advanced\\Core\\Workflow\\Conditions\\' . $name;

        if (!class_exists($className)) {
            $className .= 'Type';

            if (!class_exists($className)) {
                throw new Error('ConditionManager: Class ' . $className . ' does not exist.');
            }
        }

        /** @var class-string<Base> $className */

        return $this->injectableFactory->create($className);
    }

    public function setCreatedEntitiesData(stdClass $createdEntitiesData): void
    {
        $this->createdEntitiesData = $createdEntitiesData;
    }

    private function validate(stdClass $item): bool
    {
        if (
            isset($item->type) &&
            in_array($item->type, [self::TYPE_OR, self::TYPE_AND])
        ) {
            if (!isset($item->value) || !is_array($item->value)) {
                return false;
            }

            return true;
        }

        foreach ($this->requiredOptionList as $optionName) {
            if (!property_exists($item, $optionName)) {
                return false;
            }
        }

        return true;
    }

    private function getContainer(): Container
    {
        return $this->container;
    }

    private function getFormulaManager(): FormulaManager
    {
        return $this->getContainer()->getByClass(FormulaManager::class);
    }
}
