<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\ORM\EntityManager;

class CampaignMonitorInterestAggregator extends Base
{
    protected EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function run(): void
    {
        echo "Starting Interest Aggregation\n";

        $pdo = $this->entityManager->getPDO();

        $stmt = $pdo->query("
            SELECT
                e.contact_id,
                m.topic,
                COUNT(*) as click_count,
                MAX(e.clicked_at) as last_clicked_at
            FROM cm_click_event e
            JOIN cm_url_metadata m ON e.url_hash = m.url_hash
            GROUP BY e.contact_id, m.topic
        ");

        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {

            $id = md5($row['contact_id'] . '_' . $row['topic']);

            $insert = $pdo->prepare("
                INSERT INTO contact_interest_topic (
                    id,
                    contact_id,
                    topic,
                    click_count,
                    last_clicked_at
                ) VALUES (
                    :id,
                    :contact_id,
                    :topic,
                    :click_count,
                    :last_clicked_at
                )
                ON DUPLICATE KEY UPDATE
                    click_count = :click_count,
                    last_clicked_at = :last_clicked_at
            ");

            $insert->execute([
                'id' => $id,
                'contact_id' => $row['contact_id'],
                'topic' => $row['topic'],
                'click_count' => $row['click_count'],
                'last_clicked_at' => $row['last_clicked_at']
            ]);
        }

        echo "Aggregated " . count($rows) . " contact-topic rows\n";
    }
}
