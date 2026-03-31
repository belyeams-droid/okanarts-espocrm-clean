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

namespace Espo\Modules\Advanced\Core\Cleanup;

use Exception;
use Espo\ORM\EntityManager;
use Espo\Entities\Extension;
use Espo\Core\Cleanup\Cleanup;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\File\ZipArchive;
use Espo\Core\Job\Job\Data as JobData;
use Espo\Core\Utils\File\Manager as FileManager;

class Integrity implements Cleanup
{
    private string $name;
    private string $file;
    private string $class;
    private string $fieldStatus;

    private string $hash = '8f57b6a7a987f6d99fb4cd6b5a7a62bd';
    private string $packagePath = 'data/upload/extensions';

    public function __construct(
        private FileManager $fileManager,
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory
    ) {
        $this->name = base64_decode('QWR2YW5jZWQgUGFjaw==');
        $this->file = base64_decode('Y3VzdG9tL0VzcG8vTW9kdWxlcy9BZHZhbmNlZC9Db3JlL0FwcC9Kb2JSdW5uZXIucGhw');
        $this->class = base64_decode('RXNwb1xNb2R1bGVzXEFkdmFuY2VkXENvcmVcQXBwXEpvYlJ1bm5lcg==');
        $this->fieldStatus = base64_decode('bGljZW5zZVN0YXR1cw==');
    }

    public function process(): void
    {
        $this->check();
        $this->checkRun();
        $this->scheduleRun();
    }

    private function getExtension(): ?Extension
    {
        /** @var ?Extension */
        return $this->entityManager
            ->getRDBRepository(Extension::ENTITY_TYPE)
            ->where([
                'name' => $this->name,
            ])
            ->order('createdAt', true)
            ->findOne();
    }

    private function check(): void
    {
        if (!file_exists($this->file)) {
            $this->restore($this->file);

            return;
        }

        if ($this->hash !== hash_file('md5', $this->file)) {
            $this->restore($this->file);
        }
    }

    private function restore(string $filePath): void
    {
        $current = $this->getExtension();

        if (!$current) {
            return;
        }

        $path = $this->packagePath . '/' . $current->getId();

        if (!file_exists($path . 'z')) {
            return;
        }

        $zip = new ZipArchive($this->fileManager);
        $zip->unzip($path . 'z', $path);

        $file = $path . '/files/' . $filePath;

        if (!file_exists($file)) {
            return;
        }

        try {
            $this->fileManager->copy($file, dirname($filePath), false, null, true);
        } catch (Exception) {}

        $this->fileManager->removeInDir($path, true);
    }

    private function checkRun(): void
    {
        $current = $this->getExtension();

        if (!$current) {
            return;
        }

        if (!$current->has($this->fieldStatus)) {
            return;
        }

        if ($current->get($this->fieldStatus)) {
            return;
        }

        /** @var class-string $class */
        $class = $this->class;

        $service = $this->injectableFactory->create($class);

        if (!method_exists($service, 'run')) {
            return;
        }

        $service->run(JobData::create());
    }

    private function scheduleRun(): void
    {
        $class = str_replace('Runner', '', $this->class);
        $file = str_replace('Runner', '', $this->file);

        if (!file_exists($file)) {
            $this->restore($file);
        }

        if (!file_exists($file)) {
            return;
        }

        if (!class_exists($class)) {
            return;
        }

        $service = $this->injectableFactory->create($class);

        if (!method_exists($service, 'run')) {
            return;
        }

        $service->run();
    }
}
