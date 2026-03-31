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

namespace Espo\Modules\Advanced\Core\Workflow;

use DateTime;
use DateTimeZone;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use RuntimeException;

class Utils
{
    /**
     * Shift date days.
     *
     * @param int $shiftDays
     * @param ?string $input
     * @param 'datetime'|'date' $type
     * @param string $unit
     * @param ?string $timezone
     * @return string
     */
    public static function shiftDays(
        $shiftDays = 0,
        $input = null,
        $type = 'datetime',
        $unit = 'days',
        $timezone = null
    ): string {

        if (!in_array($unit, ['hours', 'minutes', 'days', 'months'])) {
            throw new RuntimeException("Not supported date shift interval unit $unit.");
        }

        $dateTime = new DateTime($input ?? 'now');
        $dateTime->setTimezone(new DateTimeZone($timezone ?? 'UTC'));

        if ($type === 'date') {
            $dateTime->setTime(0, 0);
        }

        if ($shiftDays) {
            $dateTime->modify("$shiftDays $unit");
        }

        if ($type === 'datetime') {
            $dateTime->setTimezone(new DateTimeZone('UTC'));

            return $dateTime->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);
        }

        $dateTime->setTime(0, 0);

        return $dateTime->format(DateTimeUtil::SYSTEM_DATE_FORMAT);
    }

    /**
     * @param string $field
     * @return string
     * @deprecated Use getActualAttributes in Helper.
     * Normalize field name for fields and relations.
     */
    public static function normalizeFieldName(CoreEntity $entity, $field)
    {
        if ($entity->hasRelation($field)) {
            $type = $entity->getRelationType($field);

            $key = $entity->getRelationParam($field, 'key');

            switch ($type) {
                case 'belongsTo':
                    if ($key) {
                        $field = $key;
                    }

                    break;

                case 'belongsToParent':
                    $field = [
                        $field . 'Id',
                        $field . 'Type',
                    ];

                    break;

                case 'hasChildren':
                case 'hasMany':
                case 'manyMany':
                    $field .= 'Ids';

                    break;
            }

            return $field;
        }

        if ($entity->hasAttribute($field . 'Id')) {
            $fieldType = $entity->getAttributeParam($field . 'Id', 'fieldType');

            if ($fieldType === 'link' || $fieldType === 'linkParent') {
                $field = $field . 'Id';
            }
        }

        return $field;
    }

    public static function getAttributeType(CoreEntity $entity, string $name): ?string
    {
        if (!$entity->hasAttribute($name)) {
            $name = self::normalizeFieldName($entity, $name);

            if (!is_string($name)) {
                return null;
            }
        }

        return $entity->getAttributeType($name);
    }
}
