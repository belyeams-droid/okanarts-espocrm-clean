<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\ORM\EntityManager;
use Espo\Custom\Services\RelationshipNarrative;

class RebuildRelationshipNarratives extends Base
{
    private EntityManager $em;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    public function run(): void
    {
        $limit = 50;
        $lastId = null;

        echo "Starting job...\n";

        while (true) {

            // 🔥 always use fresh PDO from current EM
            $pdo = $this->em->getPDO();

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

            $rows = $stmt->fetchAll();

            $count = count($rows);
            echo "Fetched: {$count}\n";

            if (!$count) {
                echo "Done.\n";
                break;
            }

            foreach ($rows as $row) {

                $contactId = $row['id'];
                echo "Processing: {$contactId}\n";

                // 🔥 recreate service EACH iteration
                $service = new RelationshipNarrative($this->em);
                $service->generateForContact($contactId);

                // 🔥 detach reference immediately
                unset($service);
            }

            $lastId = end($rows)['id'];

            // 🔥 force PHP memory cleanup
            gc_collect_cycles();
        }
    }
}
