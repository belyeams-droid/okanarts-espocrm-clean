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

namespace Espo\Modules\Advanced\Controllers;

use Espo\Core\Acl\Table;
use Espo\Core\Api\Request;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Advanced\Entities\BpmnProcess as BpmnProcessEntity;
use Espo\Modules\Advanced\Services\BpmnProcess as BpmnProcessService;

class BpmnProcess extends Record
{
    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function postActionStop(Request $request): bool
    {
        $id = $request->getParsedBody()->id ?? null;

        if (!$id) {
            throw new BadRequest();
        }

        if (!$this->acl->checKScope(BpmnProcessEntity::ENTITY_TYPE, Table::ACTION_EDIT)) {
            throw new Forbidden();
        }

        /** @var BpmnProcessService $service */
        $service = $this->getRecordService();

        $service->stopProcess($id);

        return true;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function postActionReactivate(Request $request): bool
    {
        $id = $request->getParsedBody()->id ?? null;

        if (!$id) {
            throw new BadRequest();
        }

        if (!$this->acl->checKScope(BpmnProcessEntity::ENTITY_TYPE, Table::ACTION_EDIT)) {
            throw new Forbidden();
        }

        /** @var BpmnProcessService $service */
        $service = $this->getRecordService();

        $service->reactivateProcess($id);

        return true;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     * @throws Error
     */
    public function postActionRejectFlowNode(Request $request): bool
    {
        $id = $request->getParsedBody()->id ?? null;

        if (!$id) {
            throw new BadRequest();
        }

        if (!$this->acl->checKScope(BpmnProcessEntity::ENTITY_TYPE, Table::ACTION_EDIT)) {
            throw new Forbidden();
        }

        /** @var BpmnProcessService $service */
        $service = $this->getRecordService();

        $service->rejectFlowNode($id);

        return true;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function postActionStartFlowFromElement(Request $request): bool
    {
        $processId = $request->getParsedBody()->processId ?? null;
        $elementId = $request->getParsedBody()->elementId ?? null;

        if (!$processId) {
            throw new BadRequest();
        }

        if (!$elementId) {
            throw new BadRequest();
        }

        if (!$this->acl->checKScope(BpmnProcessEntity::ENTITY_TYPE, Table::ACTION_EDIT)) {
            throw new Forbidden();
        }

        /** @var BpmnProcessService $service */
        $service = $this->getRecordService();

        $service->startFlowFromElement($processId, $elementId);

        return true;
    }
}
