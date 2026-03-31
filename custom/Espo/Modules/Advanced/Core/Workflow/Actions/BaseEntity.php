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

use DateTimeImmutable;
use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\ORM\Type\FieldType;
use Espo\Core\Utils\DateTime;
use Espo\Entities\Attachment;
use Espo\Modules\Advanced\Core\Workflow\Utils;
use Espo\ORM\Entity;
use Espo\Repositories\Attachment as AttachmentRepository;
use RuntimeException;
use stdClass;

abstract class BaseEntity extends Base
{
    /**
     * Get value of a field by a field name.
     *
     * @return stdClass|string|null
     * @throws Error
     */
    protected function getValue(string $field, Entity $targetEntity): mixed
    {
        $actionData = $this->getActionData();
        $entity = $this->getEntity();

        if (!$targetEntity instanceof CoreEntity) {
            throw new RuntimeException("No Core Entity.");
        }

        if (!isset($actionData->fields->$field)) {
            return null;
        }

        $fieldParams = $actionData->fields->$field;

        /** @var stdClass|null $values */
        $values = null;

        switch ($fieldParams->subjectType) {
            case 'value':
                if (isset($fieldParams->attributes) && $fieldParams->attributes instanceof stdClass) {
                    $values = $fieldParams->attributes;
                }

                break;

            case 'field':
                $values = $this->entityHelper->getFieldValues(
                    fromEntity: $entity,
                    toEntity: $targetEntity,
                    fromField: $fieldParams->field,
                    toField: $field,
                );

                $toShift = isset($fieldParams->shiftDays) || isset($fieldParams->shiftUnit);

                if ($toShift) {
                    $shiftDays = $fieldParams->shiftDays ?? 0;
                    $shiftUnit = $fieldParams->shiftUnit ?? null;
                    $timezone = $this->config->get('timeZone');

                    foreach (get_object_vars($values) as $attribute => $value) {
                        $attributeType = $targetEntity->getAttributeType($attribute) ?? 'datetime';

                        if (!in_array($attributeType, ['date', 'datetime'])) {
                            $attributeType = 'date';
                        }

                        /** @var 'date'|'datetime' $attributeType */

                        $values->$attribute = Utils::shiftDays(
                            shiftDays: $shiftDays,
                            input: $value,
                            type: $attributeType,
                            unit: $shiftUnit,
                            timezone: $timezone,
                        );
                    }
                }

                break;

            case 'today':
                $attributeType = Utils::getAttributeType($targetEntity, $field);
                $shiftUnit = $fieldParams->shiftUnit ?? 'days';
                $timezone = $this->config->get('timeZone');

                if (!in_array($attributeType, ['date', 'datetime'])) {
                    $attributeType = 'datetime';
                }

                /** @var 'date'|'datetime' $attributeType */

                return Utils::shiftDays(
                    shiftDays: $fieldParams->shiftDays,
                    type: $attributeType,
                    unit: $shiftUnit,
                    timezone: $timezone,
                );

            default:
                throw new Error( "Workflow[{$this->getWorkflowId()}]: Unknown fieldName for a field '$field'.");
        }

        $fieldType = $this->entityHelper->getFieldType($targetEntity, $field);

        $actionType = $fieldParams->actionType ?? null;

        if (
            ($actionType === 'add' || $actionType === 'remove') &&
            $values instanceof stdClass &&
            in_array($fieldType, [
                FieldType::LINK_MULTIPLE,
                FieldType::ARRAY,
                FieldType::MULTI_ENUM,
                FieldType::CHECKLIST,
            ])
        ) {
            if ($fieldType === FieldType::LINK_MULTIPLE) {
                $attr = $field . 'Ids';
                $setIds = $targetEntity->getLinkMultipleIdList($field);
            } else {
                $attr = $field;
                $setIds = $targetEntity->get($attr) ?? [];
            }

            $ids = $values->$attr ?? [];

            if ($actionType === 'remove') {
                $values->$attr = array_values(array_unique(array_diff($setIds, $ids)));
            } else {
                $values->$attr = array_values(array_unique(array_merge($setIds, $ids)));
            }
        }

        return $values;
    }

    /**
     * Get data to fill.
     *
     * @param array<string, mixed>|stdClass|null $fields
     * @return array<string, mixed>
     * @throws Error
     */
    protected function getDataToFill(Entity $entity, $fields): array
    {
        $data = [];

        if (empty($fields)) {
            return $data;
        }

        if (!$entity instanceof CoreEntity) {
            return $data;
        }

        $metadataFields = $this->metadata->get(['entityDefs', $entity->getEntityType(), 'fields']);
        $metadataFieldList = array_keys($metadataFields);

        if ($fields instanceof stdClass) {
            $fields = get_object_vars($fields);
        }

        foreach ($fields as $field => $fieldParams) {
            $fieldType = $this->entityHelper->getFieldType($entity, $field);

            if ($fieldType === FieldType::ATTACHMENT_MULTIPLE) {
                $data = $this->getDataToFillAttachmentMultiple($field, $entity, $data);

                continue;
            }

            if (
                $fieldType === FieldType::FILE ||
                $fieldType === FieldType::IMAGE
            ) {
                $data = $this->getDataToFillFile($field, $entity, $data);

                continue;
            }

            if (
                $entity->hasRelation($field) ||
                $entity->hasAttribute($field) ||
                in_array($field, $metadataFieldList)
            ) {
                $fieldValue = $this->getValue($field, $entity);

                if (is_object($fieldValue)) {
                    $data = array_merge($data, get_object_vars($fieldValue));

                    continue;
                }

                $data[$field] = $fieldValue;
            }
        }

        foreach ($fields as $field => $fieldParams) {
            $fieldType = $this->entityHelper->getFieldType($entity, $field);

            if ($fieldType === 'duration') {
                $this->fillDataDuration($field, $entity, $data);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws Error
     */
    private function getDataToFillAttachmentMultiple(string $field, CoreEntity $entity, array $data): array
    {
        if (!$entity->hasLinkMultipleField($field)) {
            return $data;
        }

        $attachmentData = $this->getValue($field, $entity);

        if (!$attachmentData instanceof stdClass) {
            return $data;
        }

        $copiedIdList = [];
        $idListFieldName = $field . 'Ids';

        /** @var AttachmentRepository $repository */
        $repository = $this->entityManager->getRepository(Attachment::ENTITY_TYPE);

        if (is_array($attachmentData->$idListFieldName)) {
            foreach ($attachmentData->$idListFieldName as $attachmentId) {
                $attachment = $this->entityManager
                    ->getRDBRepositoryByClass(Attachment::class)
                    ->getById($attachmentId);

                if (!$attachment) {
                    continue;
                }

                $attachment = $repository->getCopiedAttachment($attachment);
                $attachment->setTargetField($field);

                $this->entityManager->saveEntity($attachment);

                $copiedIdList[] = $attachment->getId();
            }
        }

        $attachmentData->$idListFieldName = $copiedIdList;

        return array_merge($data, get_object_vars($attachmentData));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws Error
     */
    private function getDataToFillFile(string $field, CoreEntity $entity, array $data): array
    {
        $idAttr = $field . 'Id';
        $nameAttr = $field . 'Name';

        $inputData = $this->getValue($field, $entity) ?? (object) [];

        $id = $inputData->$idAttr ?? null;

        if (!$id) {
            return array_merge($data, [
                $idAttr => null,
                $nameAttr => null,
            ]);
        }

        /** @var AttachmentRepository $repository */
        $repository = $this->entityManager->getRepository(Attachment::ENTITY_TYPE);

        $attachment = $this->entityManager
            ->getRDBRepositoryByClass(Attachment::class)
            ->getById($id);

        if (!$attachment) {
            return array_merge($data, [
                $idAttr => null,
                $nameAttr => null,
            ]);
        }

        $copy = $repository->getCopiedAttachment($attachment);
        $copy->setTargetField($field);

        $this->entityManager->saveEntity($copy);

        return array_merge($data, [
            $idAttr => $copy->getId(),
            $nameAttr => $copy->getName(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fillDataDuration(string $field, CoreEntity $entity, array &$data): void
    {
        $entityType = $entity->getEntityType();

        $duration = $data[$field];

        if (!is_int($duration)) {
            return;
        }

        $startField = $this->metadata->get("entityDefs.$entityType.fields.$field.start");
        $endField = $this->metadata->get("entityDefs.$entityType.fields.$field.end");

        $startDateAttribute = $startField . 'Date';
        $endDateAttribute = $endField . 'Date';

        $start = $data[$startField] ?? null;
        $startDate =$data[$startDateAttribute] ?? null;

        if ($start) {
            $dateEnd = (new DateTimeImmutable($start))
                ->modify("+$duration seconds")
                ->format(DateTime::SYSTEM_DATE_TIME_FORMAT);

            /** @phpstan-ignore-next-line parameterByRef.type */
            $data[$endField] = $dateEnd;
        }

        if ($startDate) {
            $days = floor($duration / (3600 * 24));

            $dateEndDate = (new DateTimeImmutable($startDate))
                ->modify("+$days days")
                ->format(DateTime::SYSTEM_DATE_FORMAT);

            /** @phpstan-ignore-next-line parameterByRef.type */
            $data[$endDateAttribute] = $dateEndDate;
        }
    }
}
