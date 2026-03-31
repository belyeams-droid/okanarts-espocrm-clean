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

namespace Espo\Modules\Advanced\Tools\Report\Api;

use Espo\Core\Acl;
use Espo\Core\Acl\Table as AclTable;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Json;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Tools\Report\PreviewReportProvider;
use Espo\Modules\Advanced\Tools\Report\Service;
use JsonException;
use stdClass;

/**
 * @noinspection PhpUnused
 */
class PostRunGridPreview implements Action
{
    public function __construct(
        private Service $service,
        private Acl $acl,
        private User $user,
        private PreviewReportProvider $previewReportProvider,
    ) {}

    public function process(Request $request): Response
    {
        $this->checkAccess();

        $data = $this->fetchData($request);
        $report = $this->previewReportProvider->get($data);

        if (!in_array($report->getType(), [Report::TYPE_GRID, Report::TYPE_JOINT_GRID])) {
            throw new BadRequest("Bad report type.");
        }

        $where = $request->getParsedBody()->where ?? null;

        $whereItem = null;

        if ($where) {
            $whereItem = WhereItem::fromRawAndGroup(self::normalizeWhere($where));
        }

        // Passing the user is important.
        $result = $this->service->reportRunGridOrJoint($report, $whereItem, $this->user);

        return ResponseComposer::json($result->toRaw());
    }

    /**
     * @throws BadRequest
     */
    private function fetchData(Request $request): stdClass
    {
        $data = $request->getParsedBody()->data ?? null;

        if (!$data instanceof stdClass) {
            throw new BadRequest("No data.");
        }

        return $data;
    }

    /**
     * @throws BadRequest
     */
    private static function normalizeWhere(mixed $where): mixed
    {
        try {
            return Json::decode(Json::encode($where), true);
        } catch (JsonException) {
            throw new BadRequest("Bad where");
        }
    }

    /**
     * @throws Forbidden
     */
    private function checkAccess(): void
    {
        if (!$this->acl->checkScope(Report::ENTITY_TYPE, AclTable::ACTION_CREATE)) {
            throw new Forbidden("No 'create' access.");
        }

        if ($this->user->isPortal()) {
            throw new Forbidden("No access from portal.");
        }
    }
}
