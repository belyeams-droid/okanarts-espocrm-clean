<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\Custom\Services\QueryInterpreter;

class ExportEngagementCsv extends Base
{
    public function run($data): void
    {
        $em = $this->getEntityManager();

        $queryText = "show japan prospects";

        $qi = new QueryInterpreter();
        $filters = $qi->interpret($queryText);

        $file = 'data/export-engagement.csv';
        $fp = fopen($file, 'w');

        fputcsv($fp, [
            'Contact ID',
            'Name',
            'Status',
            'Score',
            'Orders',
            'Spent',
            'Interest',
            'Intent',
            'Action',
            'Priority'
        ]);

        $pdo = $em->getPDO();
        $limit = 200;
        $lastId = null;

        while (true) {

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

            if (!count($rows)) {
                break;
            }

            foreach ($rows as $row) {

                $contact = $em->getRepository('Contact')
                    ->where(['id' => $row['id']])
                    ->findOne();

                if (!$contact) {
                    continue;
                }

                $status = $this->determineStatus($contact);
                $score = $this->calculateScore($contact);

                $context = [
                    'name' => $contact->get('name'),
                    'status' => $status,
                    'score' => $score,
                    'orders' => (int) ($contact->get('cTotalOrders') ?? 0),
                    'spent' => (float) ($contact->get('cTotalSpent') ?? 0),
                    'interest' => $this->inferInterest($contact)
                ];

                // Filters
                if (!empty($filters['interest']) && $context['interest'] !== $filters['interest']) continue;
                if (!empty($filters['status']) && $context['status'] !== $filters['status']) continue;
                if (!empty($filters['minScore']) && $context['score'] < $filters['minScore']) continue;
                if (!empty($filters['noOrders']) && $context['orders'] > 0) continue;

                $decision = $this->ruleBasedDecision($context);

                if ($decision === null) {
                    $decision = [
                        'intent' => 'review',
                        'action' => 'Manual review required',
                        'priority' => 'low'
                    ];
                }

                if (($decision['priority'] ?? '') !== 'high') {
                    continue;
                }

                fputcsv($fp, [
                    $contact->getId(),
                    $context['name'],
                    $status,
                    $score,
                    $context['orders'],
                    $context['spent'],
                    $context['interest'],
                    $decision['intent'] ?? '',
                    $decision['action'] ?? '',
                    $decision['priority'] ?? ''
                ]);

                unset($contact);
            }

            $lastId = end($rows)['id'];

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        fclose($fp);

        echo "Export complete: $file\n";
    }

    private function determineStatus($contact): string
    {
        $tours = $contact->get('cToursAttended') ?? [];
        $orders = (int) ($contact->get('cTotalOrders') ?? 0);

        if (!empty($tours)) return 'Converted';
        if ($orders > 0) return 'Considering';

        return 'Aware';
    }

    private function calculateScore($contact): int
    {
        $score = 0;

        $orders = (int) ($contact->get('cTotalOrders') ?? 0);
        $spent = (float) ($contact->get('cTotalSpent') ?? 0);

        if ($orders > 0) $score += 15;
        if ($orders > 5) $score += 10;
        if ($spent > 1000) $score += 10;

        return $score;
    }

    private function inferInterest($contact): ?string
    {
        $text = strtolower((string) ($contact->get('relationshipNarrative') ?? ''));

        if (str_contains($text, 'japan') || str_contains($text, 'jp tour')) {
            return 'Japan';
        }

        if (str_contains($text, 'blue')) {
            return 'Blue';
        }

        return null;
    }

    private function ruleBasedDecision(array $context): ?array
    {
        if ($context['status'] === 'Aware' && $context['score'] === 0) {
            return [
                'intent' => 'engagement',
                'action' => 'Send welcome email',
                'priority' => 'medium'
            ];
        }

        if ($context['interest'] === 'Japan') {
            return [
                'intent' => 'convert',
                'action' => 'Send Japan tour email',
                'priority' => 'high'
            ];
        }

        return null;
    }
}
