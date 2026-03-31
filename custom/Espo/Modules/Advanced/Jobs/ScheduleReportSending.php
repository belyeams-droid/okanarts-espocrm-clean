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

namespace Espo\Modules\Advanced\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\Advanced\Tools\Report\SendingService;
use Exception;

class ScheduleReportSending implements JobDataLess
{
    private SendingService $sendingService;
    private Log $log;

    public function __construct(
        SendingService $sendingService,
        Log $log
    ) {
        $this->sendingService = $sendingService;
        $this->log = $log;
    }

    public function run(): void
    {
        try {
            $this->sendingService->scheduleEmailSending();
        }
        catch (Exception $e) {
            $this->log->error('ScheduleReportSending: ' . $e->getMessage());
        }
    }
}
