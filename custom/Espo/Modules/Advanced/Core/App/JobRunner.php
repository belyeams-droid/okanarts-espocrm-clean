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

namespace Espo\Modules\Advanced\Core\App;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Config;
use Espo\Entities\Extension;
use Espo\Core\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

class JobRunner implements Job
{
    /** @var string */
    private $name;

    /** @var string */
    private $fieldStatus;

    /** @var string */
    private $fieldMessage;

    public function __construct(
        private Config $config,
        private EntityManager $entityManager
    ) {
        $this->name = base64_decode('QWR2YW5jZWQgUGFjaw==');
        $this->fieldStatus = base64_decode('bGljZW5zZVN0YXR1cw==');
        $this->fieldMessage = base64_decode('bGljZW5zZVN0YXR1c01lc3NhZ2U=');
    }

    public function run(Data $data): void
    {
        /** @var ?Extension $current */
        $current = $this->entityManager
            ->getRDBRepository(Extension::ENTITY_TYPE)
            ->where([
                'name' => $this->name,
            ])
            ->order('createdAt', true)
            ->findOne();

        $responseData = $this->validate($this->getData($current));

        $status = $responseData['status'] ?? null;
        $statusMessage = $responseData['statusMessage'] ?? null;

        if (!$status) {
            return;
        }

        if (!$current) {
            return;
        }

        if (!$current->has($this->fieldStatus)) {
            return;
        }

        if (
            $current->get($this->fieldStatus) == $status &&
            $current->get($this->fieldMessage) == $statusMessage
        ) {
            return;
        }

        $current->set([
            $this->fieldStatus => $status,
            $this->fieldMessage => $statusMessage,
        ]);

        $this->entityManager->saveEntity($current, [
            'skipAll' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getData(?Extension $current): array
    {
        $query = SelectBuilder::create()
            ->from(Extension::ENTITY_TYPE)
            ->select(['createdAt'])
            ->withDeleted()
            ->build();

        /** @var ?Extension $first */
        $first = $this->entityManager
            ->getRDBRepository(Extension::ENTITY_TYPE)
            ->clone($query)
            ->where(['name' => $this->name])
            ->order('createdAt')
            ->findOne();

        return [
            'id' => base64_decode('MzNiZmIzNDg3NzBhMmQ3OWExMzQ4ZmIwMjYwNDZiN2Y='),
            'name' => $this->name,
            'version' => $current?->get('version'),
            'updatedAt' => $current?->get('createdAt'),
            'installedAt' => $first?->get('createdAt'),
            'site' => $this->config->get('siteUrl'),
            'instanceId' => $this->config->get('instanceId'),
            'espoVersion' => $this->config->get('version'),
            'applicationName' => $this->config->get('applicationName'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return ?array<string, mixed>
     */
    private function validate(array $data): ?array
    {
        if (!function_exists('curl_version')) {
            return null;
        }

        $ch = curl_init();

        /**
         * @var string $payload
         * @phpstan-ignore-next-line
         */
        $payload = json_encode($data);

        /** @phpstan-ignore-next-line argument.type */
        curl_setopt($ch, CURLOPT_URL, base64_decode('aHR0cHM6Ly9zLmVzcG9jcm0uY29tL2xpY2Vuc2Uv'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
        ]);

        /** @var string $result */
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($result, true);
        }

        return null;
    }
}
