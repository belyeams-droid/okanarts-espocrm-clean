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

namespace Espo\Modules\Advanced\Core\Workflow\Formula\Functions\WorkflowGroup;

use Espo\Core\Formula\EvaluatedArgumentList;
use Espo\Core\Formula\Exceptions\BadArgumentType;
use Espo\Core\Formula\Exceptions\TooFewArguments;
use Espo\Core\Formula\FuncVariablesAware;
use Espo\Core\Formula\Variables;
use stdClass;

class AlertType implements FuncVariablesAware
{
    public function process(EvaluatedArgumentList $arguments, Variables $variables): mixed
    {
        if (count($arguments) < 1) {
            throw TooFewArguments::create(1);
        }

        $message = $arguments[0] ?? null;
        $autoClose = $arguments[1] ?? null;
        $type = $arguments[2] ?? null;

        if (!is_string($message)) {
            throw BadArgumentType::create(1, 'string');
        }

        if (!is_bool($autoClose) && $autoClose !== null) {
            throw BadArgumentType::create(2, 'bool');
        }

        if (!is_string($type) && $type !== null) {
            throw BadArgumentType::create(3, 'string');
        }

        $alert = $variables->get('__alert');

        if (!$alert instanceof stdClass) {
            return null;
        }

        $alert->message = $message;
        $alert->autoClose = $autoClose;
        $alert->type = $type;

        return null;
    }
}
