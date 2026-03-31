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

namespace Espo\Modules\Advanced\Core\Workflow\Formula\Functions\ReportGroup;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Formula\EvaluatedArgumentList;
use Espo\Core\Formula\Exceptions\BadArgumentType;
use Espo\Core\Formula\Exceptions\Error as FormulaError;
use Espo\Core\Formula\Exceptions\TooFewArguments;
use Espo\Core\Formula\Func;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Tools\Report\ExportService;
use Espo\ORM\EntityManager;

class Export implements Func
{
    public function __construct(
        private ExportService $service,
        private EntityManager $entityManager,
    ) {}

    public function process(EvaluatedArgumentList $arguments): string
    {
        if (count($arguments) < 1) {
            throw TooFewArguments::create(1);
        }

        $reportId = $arguments[0] ?? null;
        $userId = $arguments[1] ?? null;

        if (!is_string($reportId)) {
            throw BadArgumentType::create(1, 'string');
        }

        if ($userId !== null && !is_string($userId)) {
            throw BadArgumentType::create(1, 'string');
        }

        $report = $this->entityManager->getRDBRepositoryByClass(Report::class)->getById($reportId);

        $user = $userId ? $this->entityManager->getRDBRepositoryByClass(User::class)->getById($userId) : null;

        if (!$report) {
            throw new FormulaError("Report '$reportId' not found.");
        }

        if ($userId && !$user) {
            throw new FormulaError("User '$userId' not found.");
        }

        try {
            $attachment = $this->service->prepareExportAttachment($report, $user);
        } catch (BadRequest|NotFound|Forbidden|Error $e) {
            throw new FormulaError($e->getMessage(), $e->getCode(), $e);
        }

        return $attachment->getId();
    }
}
