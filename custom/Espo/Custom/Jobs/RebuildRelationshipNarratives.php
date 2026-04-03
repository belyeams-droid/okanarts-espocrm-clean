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
        $GLOBALS['log']->error("🔥 JOB ACTUALLY STARTED 🔥");

        $limit = 50;

        $pdo = $this->em->getPDO();

        // 🔥 CLEANUP: remove flags from deleted contacts
        $pdo->exec("
            UPDATE contact
            SET needs_narrative_rebuild = 0
            WHERE deleted = 1
        ");

        $stmt = $pdo->query("
            SELECT id
            FROM contact
            WHERE deleted = 0
            AND needs_narrative_rebuild = 1
            ORDER BY id
            LIMIT {$limit}
        ");

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$rows) {
            $GLOBALS['log']->info("RebuildRelationshipNarratives: no dirty records");
            return;
        }

        $service = new RelationshipNarrative($this->em);

        foreach ($rows as $row) {

            $contactId = $row['id'];

            try {
                $service->generateForContact($contactId);

                $GLOBALS['log']->info("Processed contact {$contactId}");

            } catch (\Throwable $e) {

                $GLOBALS['log']->error(
                    "FAILED CONTACT {$contactId} :: " .
                    $e->getMessage() . " :: " .
                    $e->getFile() . ":" . $e->getLine()
                );
            }

            // 🔥 ALWAYS clear flag (success OR failure)
            $stmtUpdate = $pdo->prepare("
                UPDATE contact
                SET needs_narrative_rebuild = 0
                WHERE id = :id
            ");

            $stmtUpdate->execute(['id' => $contactId]);
        }
    }
}
