<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\Custom\Services\QueryInterpreter;

class ExportEngagementCsv extends Base
{
    public function run($data): void
    {
        $em = $this->getEntityManager();

        // 🔥 TEMP QUERY (replace later with UI input)
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

        $repo = $em->getRepository('Contact');

        $contacts = $repo
            ->where(['deleted' => false])
            ->limit(2000)
            ->find();

        foreach ($contacts as $contact) {

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

            /*
            ----------------------------------------
            APPLY QUERY FILTERS
            ----------------------------------------
            */

            if (!empty($filters['interest']) && $context['interest'] !== $filters['interest']) {
                continue;
            }

            if (!empty($filters['status']) && $context['status'] !== $filters['status']) {
                continue;
            }

            if (!empty($filters['minScore']) && $context['score'] < $filters['minScore']) {
                continue;
            }

            if (!empty($filters['noOrders']) && $context['orders'] > 0) {
                continue;
            }

            /*
            ----------------------------------------
            DECISION ENGINE
            ----------------------------------------
            */

            $decision = $this->ruleBasedDecision($context);

            if ($decision === null) {
                $decision = [
                    'intent' => 'review',
                    'action' => 'Manual review required',
                    'priority' => 'low'
                ];
            }

            // 🔥 ONLY EXPORT HIGH PRIORITY
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

    /*
    ----------------------------------------
    🔥 FIXED: USE NARRATIVE FOR INTEREST
    ----------------------------------------
    */

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
