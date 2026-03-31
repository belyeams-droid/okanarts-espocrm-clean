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

use Espo\ORM\Entity;
use stdClass;

class Helper
{
    /**
     * @return array{
     *     elementsDataHash: stdClass,
     *     eventStartIdList: string[],
     *     eventStartAllIdList: string[]
     * }
     */
    public static function getElementsDataFromFlowchartData(stdClass $data): array
    {
        $elementsDataHash = (object) [];
        $eventStartIdList = [];
        $eventStartAllIdList = [];

        if (isset($data->list) && is_array($data->list)) {
            foreach ($data->list as $item) {
                if (!is_object($item)) {
                    continue;
                }

                $itType = $item->type ?? null;
                $itId = $item->id ?? null;

                if ($itType === 'flow') {
                    continue;
                }

                $nextElementIdList = [];
                $previousElementIdList = [];

                foreach ($data->list as $itemAnother) {
                    if ($itemAnother->type !== 'flow') {
                        continue;
                    }

                    if (!isset($itemAnother->startId) || !isset($itemAnother->endId)) {
                        continue;
                    }

                    if ($itemAnother->startId === $itId) {
                        $nextElementIdList[] = $itemAnother->endId;
                    } else if ($itemAnother->endId === $itId) {
                        $previousElementIdList[] = $itemAnother->startId;
                    }
                }

                usort($nextElementIdList, function ($id1, $id2) use ($data) {
                    $item1 = self::getItemById($data, $id1);
                    $item2 = self::getItemById($data, $id2);

                    if (isset($item1->center) && isset($item2->center)) {
                        if ($item1->center->y > $item2->center->y) {
                            return 1;
                        }

                        if ($item1->center->y == $item2->center->y) {
                            if ($item1->center->x > $item2->center->x) {
                                return 1;
                            }
                        }
                    }

                    return -1;
                });

                $id = $item->id ?? null;

                /** @var stdClass $o */
                $o = clone $item;

                $o->nextElementIdList = $nextElementIdList;
                $o->previousElementIdList = $previousElementIdList;

                if (isset($item->flowList)) {
                    $o->flowList = [];

                    foreach ($item->flowList as $nextFlowData) {
                        $nextFlowDataCloned = clone $nextFlowData;

                        foreach ($data->list as $itemAnother) {
                            if ($itemAnother->id !== $nextFlowData->id) {
                                continue;
                            }

                            $nextFlowDataCloned->elementId = $itemAnother->endId;
                            break;
                        }

                        $o->flowList[] = $nextFlowDataCloned;
                    }
                }

                if (!empty($item->defaultFlowId)) {
                    foreach ($data->list as $itemAnother) {
                        if ($itemAnother->id !== $item->defaultFlowId) {
                            continue;
                        }

                        $o->defaultNextElementId = $itemAnother->endId;

                        break;
                    }
                }

                if ($itType === 'eventStart') {
                    $eventStartIdList[] = $id;
                }

                if (is_string($itType) && str_starts_with($itType, 'eventStart')) {
                    $eventStartAllIdList[] = $id;
                }

                $elementsDataHash->$id = $o;
            }
        }

        return [
            'elementsDataHash' => $elementsDataHash,
            'eventStartIdList' => $eventStartIdList,
            'eventStartAllIdList' => $eventStartAllIdList,
        ];
    }

    private static function getItemById(stdClass $data, string $id): ?stdClass
    {
        foreach ($data->list as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    public static function applyPlaceholders(string $text, Entity $target, stdClass $variables = null): string
    {
        foreach ($target->getAttributeList() as $attribute) {
            $value = $target->get($attribute);

            if ($value === null) {
                continue;
            }

            if (is_numeric($value)) {
                $value = (string) $value;
            }

            if (!is_string($value)) {
                continue;
            }

            $text = str_replace('{$' . $attribute . '}', $value, $text);
        }

        $variables = $variables ?? (object) [];

        foreach (get_object_vars($variables) as $key => $value) {
            if (is_numeric($value)) {
                $value = (string) $value;
            }

            if (!is_string($value)) {
                continue;
            }

            $text = str_replace('{$$' . $key . '}', $value, $text);
        }

        return $text;
    }
}
