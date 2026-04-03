<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\ORM\EntityManager;
use Espo\Custom\Services\EngagementOrchestrator;

class RebuildEngagementScores extends Base
{
    protected EntityManager $entityManager;
    protected EngagementOrchestrator $orchestrator;

    public function __construct(
        EntityManager $entityManager,
        EngagementOrchestrator $orchestrator
    ) {
        $this->entityManager = $entityManager;
        $this->orchestrator = $orchestrator;
    }

    public function run(): void
    {
        echo "Rebuilding engagement scores (stateful)...\n";

        $pdo = $this->entityManager->getPDO();
        $limit = 50;

        // -----------------------------
        // STATE FILE
        // -----------------------------
        $stateFile = 'data/rebuild_engagement_scores.state';

        // -----------------------------
        // Load last processed ID
        // -----------------------------
        $lastId = null;

        if (file_exists($stateFile)) {
            $lastId = trim(file_get_contents($stateFile)) ?: null;
            echo "Resuming from ID: {$lastId}\n";
        }

        // -----------------------------
        // Fetch next batch
        // -----------------------------
        if ($lastId) {
            $stmt = $pdo->prepare("
                SELECT id
                FROM contact
                WHERE deleted = 0
                AND id > :lastId
                ORDER BY id
                LIMIT {$limit}
            ");
            $stmt->execute(['lastId' => $lastId]);
        } else {
            $stmt = $pdo->query("
                SELECT id
                FROM contact
                WHERE deleted = 0
                ORDER BY id
                LIMIT {$limit}
            ");
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $count = count($rows);

        echo "Fetched: {$count}\n";

        if (!$count) {
            echo "All contacts processed. Resetting state.\n";
            @unlink($stateFile);
            return;
        }

        // -----------------------------
        // Process batch
        // -----------------------------
        foreach ($rows as $row) {

            $contactId = $row['id'];
            echo "Processing: {$contactId}\n";

            $this->orchestrator->syncForContact($contactId);

            // Save progress after EACH contact
            file_put_contents($stateFile, $contactId);
        }

        echo "Batch complete.\n";

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
}
