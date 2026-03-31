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

namespace Espo\Modules\Advanced\Tools\Report\GridType;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Metadata;
use Espo\ORM\EntityManager;

use Exception;

class Util
{
    private int $aliasMaxLength = 128;

    private Metadata $metadata;
    private EntityManager $entityManager;
    private Language $language;
    private DateTime $dateTime;

    public function __construct(
        Metadata $metadata,
        EntityManager $entityManager,
        Language $language,
        DateTime $dateTime,
        Config $config
    ) {
        $this->metadata = $metadata;
        $this->entityManager = $entityManager;
        $this->language = $language;
        $this->dateTime = $dateTime;

        if ($config->get('database.platform') === 'Postgresql') {
            $this->aliasMaxLength = 63;
        }
    }

    public function sanitizeSelectAlias(string $alias): string
    {
        $alias = preg_replace('/[^A-Za-z\r\n0-9_:\'" .,\-()]+/', '', $alias) ?? '';

        if (strlen($alias) > $this->aliasMaxLength) {
            $alias = preg_replace('!\s+!', ' ', $alias);
        }

        if (strlen($alias) > $this->aliasMaxLength) {
            $alias = substr($alias, 0, $this->aliasMaxLength);
        }

        return $alias;
    }

    /**
     * @todo Use ColumnData object.
     * @param scalar|string[] $value
     * @return scalar|string[]
     */
    public function getCellDisplayValue($value, object $columnData)
    {
        /** @var ColumnData $columnData */

        $displayValue = $value;

        $fieldType = $columnData->fieldType;

        if ($fieldType === 'link') {
            if ($value && is_string($value)) {
                try {
                    /** @var ?string $foreignEntityType */
                    $foreignEntityType = $this->metadata->get(
                        ['entityDefs', $columnData->entityType, 'links', $columnData->field, 'entity']);

                    if ($foreignEntityType) {
                        $e = $this->entityManager->getEntityById($foreignEntityType, $value);

                        if ($e) {
                            $displayValue = $e->get('name');
                        }
                    }
                } catch (Exception) {}
            }
        } else if ($fieldType === 'enum') {
            $displayValue = is_string($value) ?
                $this->language->translateOption($value, $columnData->field, $columnData->entityType) :
                '';

            $translation = $this->metadata
                ->get(['entityDefs', $columnData->entityType, 'fields', $columnData->field, 'translation']);

            $optionsReference = $this->metadata
                ->get(['entityDefs', $columnData->entityType, 'fields', $columnData->field, 'optionsReference']);

            if (!$translation && $optionsReference) {
                $translation = str_replace('.', '.options.', $optionsReference);
            }

            if ($translation && (is_string($value) || is_int($value))) {
                $translationMap = $this->language->get(explode('.', $translation));

                if (is_array($translationMap) && array_key_exists($value, $translationMap)) {
                    $displayValue = $translationMap[$value];
                }
            }
        } else if ($fieldType === 'datetime' || $fieldType === 'datetimeOptional') {
            if ($value && is_string($value)) {
                $displayValue = $this->dateTime->convertSystemDateTime($value);
            }
        } else if ($fieldType === 'date') {
            if ($value && is_string($value)) {
                $displayValue = $this->dateTime->convertSystemDate($value);
            }
        } else if ($fieldType === 'multiEnum' || $fieldType === 'checklist' || $fieldType === 'array') {
            if (is_array($value)) {
                $displayValue = array_map(
                    function ($item) use ($columnData) {
                        return $this->language->translateOption(
                            $item,
                            $columnData->field,
                            $columnData->entityType
                        );
                    },
                    $value
                );
            }
        }

        if (is_null($displayValue)) {
            $displayValue = $value;
        }

        return $displayValue;
    }

    public function translateGroupName(string $entityType, string $item): string
    {
        if (str_contains($item, ':(')) {
            return '';
        }

        return $this->translateColumnName($entityType, $item);
    }

    public function translateColumnName(string $entityType, string $item): string
    {
        if (str_contains($item, ':(')) {
            return $item;
        }

        $field = $item;
        $function = null;

        if (str_contains($item, ':')) {
            [$function, $field] = explode(':', $item);
        }

        $groupLabel = '';
        $entityTypeLocal = $entityType;

        if (str_contains($field, '.')) {
            [$link, $field] = explode('.', $field);

            $entityTypeLocal = $this->metadata->get(['entityDefs', $entityType, 'links', $link, 'entity']);
            //$groupLabel .= $this->language->translate($link, 'links', $entityType);
            //$groupLabel .= '.';
        }

        if ($this->metadata->get(['entityDefs', $entityTypeLocal, 'fields', $field, 'type']) == 'currencyConverted') {
            $field = str_replace('Converted', '', $field);
        }

        $groupLabel .= $this->language->translateLabel($field, 'fields', $entityTypeLocal);

        if ($function) {
            $functionLabel = $this->language->translateLabel($function, 'functions', 'Report');

            if ($function === 'COUNT' && $field === 'id') {
                return $functionLabel;
            }

            if ($function !== 'SUM') {
                $groupLabel = $functionLabel . ': ' . $groupLabel;
            }
        }

        return $groupLabel;
    }
}
