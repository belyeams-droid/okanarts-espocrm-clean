<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;

class CleanupJobHistory implements JobDataLess
{
    private $entityManager;
    private $log;

    public function __construct(EntityManager $entityManager, Log $log)
    {
        $this->entityManager = $entityManager;
        $this->log = $log;
    }

    public function run(): void
    {
        $connection = $this->entityManager->getPDO();

        $sql = "
            DELETE FROM job
            WHERE status = 'Success'
            AND created_at < NOW() - INTERVAL 7 DAY
        ";

        $stmt = $connection->prepare($sql);
        $stmt->execute();

        $count = $stmt->rowCount();

        $this->log->info("[CleanupJobHistory] Removed {$count} old successful jobs.");
    }
}
