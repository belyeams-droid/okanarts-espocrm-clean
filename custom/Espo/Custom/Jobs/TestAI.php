<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\ORM\EntityManager;
use Espo\Custom\Services\EngagementOrchestrator;

class TestAI extends Base
{
    public function run(): void
    {
        /** @var EntityManager $em */
        $em = $this->getEntityManager();

        $orchestrator = new EngagementOrchestrator(
            $em,
            $this->getContainer()->get('serviceFactory')->create('RelationshipNarrative')
        );

        // 🔥 GET 2 RANDOM CONTACTS
        $pdo = $em->getPDO();

        $stmt = $pdo->query("
            SELECT id
            FROM contact
            WHERE deleted = 0
            ORDER BY RAND()
            LIMIT 2
        ");

        $contacts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        echo "Processing " . count($contacts) . " random contacts...\n";

        foreach ($contacts as $row) {
            $contactId = $row['id'];

            echo "Processing contact: {$contactId}\n";

            try {
                $orchestrator->syncForContact($contactId);
            } catch (\Throwable $e) {
                echo "Error for {$contactId}: " . $e->getMessage() . "\n";
            }
        }

        echo "Done.\n";
    }
}
