<?php

namespace Espo\Custom\Services;

use Espo\ORM\EntityManager;
use Espo\Custom\Services\RelationshipNarrative;

class EngagementOrchestrator
{
    private EntityManager $em;
    private RelationshipNarrative $relationshipNarrative;

    public function __construct(
        EntityManager $em,
        RelationshipNarrative $relationshipNarrative
    ) {
        $this->em = $em;
        $this->relationshipNarrative = $relationshipNarrative;
    }

    public function syncForContact(string $contactId): void
    {
        $contact = $this->em->getEntityById('Contact', $contactId);

        if (!$contact) {
            return;
        }

        $status = $this->determineStatus($contact);
        $score = $this->calculateScore($contact);

        $engagement = $this->ensureEngagement($contactId);

        $engagement->set('engagementStatus', $status);
        $engagement->set('engagementScore', $score);

        $this->em->saveEntity($engagement, [
            'skipAll' => true
        ]);

        /*
        ----------------------------------------
        SYNC SCORE TO CONTACT (for query engine)
        ----------------------------------------
        */
        $contact->set('engagement_score', $score);
        $this->em->saveEntity($contact, [
            'skipAll' => true
        ]);

        /*
        ----------------------------------------
        Generate Narrative (stored only)
        ----------------------------------------
        */
        $this->relationshipNarrative->generateForContact($contactId);

        /*
        ----------------------------------------
        BUILD CONTEXT (LEAN)
        ----------------------------------------
        */

        $context = [
            'name' => $contact->get('name'),
            'status' => $status,
            'score' => $score,
            'orders' => (int) ($contact->get('cTotalOrders') ?? 0),
            'spent' => (float) ($contact->get('cTotalSpent') ?? 0),
            'tourClicks' => (int) ($contact->get('cm_tours_clicks') ?? 0),
            'lastClick' => $contact->get('cm_last_click'),
            'interest' => $this->inferInterest($contact),
        ];

        /*
        ----------------------------------------
        RULE ENGINE FIRST (ZERO AI)
        ----------------------------------------
        */

        $decision = $this->ruleBasedDecision($context);

        /*
        ----------------------------------------
        FALLBACK TO AI
        ----------------------------------------
        */

        if ($decision === null) {
            $decision = $this->askClaude($context);

            file_put_contents(
                'data/logs/ai-usage.log',
                date('c') . " AI_USED\n",
                FILE_APPEND
            );
        }

        /*
        ----------------------------------------
        LOG
        ----------------------------------------
        */

        file_put_contents(
            'data/logs/ai.log',
            date('c') . ' ' . json_encode([
                'contactId' => $contactId,
                'context' => $context,
                'decision' => $decision
            ]) . PHP_EOL,
            FILE_APPEND
        );

        /*
        ----------------------------------------
        HIGH VALUE → TASK
        ----------------------------------------
        */

        if (
            isset($decision['intent']) &&
            str_contains($decision['intent'], 'convert')
        ) {
            file_put_contents(
                'data/logs/ai-high-value.log',
                date('c') . ' ' . json_encode([
                    'contactId' => $contactId,
                    'name' => $context['name'],
                    'intent' => $decision['intent'],
                    'action' => $decision['action'] ?? ''
                ]) . PHP_EOL,
                FILE_APPEND
            );

            $this->createFollowUpTask(
                $contactId,
                $context['name'],
                $decision
            );
        }
    }

    private function ruleBasedDecision(array $context): ?array
    {
        $status = $context['status'] ?? null;
        $score = $context['score'] ?? 0;
        $orders = $context['orders'] ?? 0;
        $interest = $context['interest'] ?? null;

        if ($status === 'Aware' && $score === 0 && $orders === 0) {
            return [
                'intent' => 'engagement',
                'action' => 'Send welcome email',
                'priority' => 'medium'
            ];
        }

        if ($interest === 'Japan' && $orders === 0) {
            return [
                'intent' => 'convert',
                'action' => 'Send Japan tour email with offer',
                'priority' => 'high'
            ];
        }

        if ($orders > 2) {
            return [
                'intent' => 'upsell',
                'action' => 'Invite to premium tour or workshop',
                'priority' => 'high'
            ];
        }

        if ($status === 'Considering' && $score < 20) {
            return [
                'intent' => 'nurture',
                'action' => 'Send targeted tour recommendation email',
                'priority' => 'medium'
            ];
        }

        return null;
    }

    private function createFollowUpTask(string $contactId, string $name, array $decision): void
    {
        $task = $this->em->getEntity('Task');

        $task->set([
            'name' => 'AI Follow-up: ' . $name,
            'status' => 'Not Started',
            'priority' => 'Normal',
            'description' =>
                "Intent: " . ($decision['intent'] ?? '') . "\n\n" .
                "Action: " . ($decision['action'] ?? ''),
            'parentType' => 'Contact',
            'parentId' => $contactId
        ]);

        $this->em->saveEntity($task);
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

        $tours = $contact->get('cToursAttended') ?? [];
        $workshops = $contact->get('cWorkshopsAttended') ?? [];

        $cmStatus = $contact->get('cm_status');
        $cmOpenCount = (int) ($contact->get('cm_open_count') ?? 0);
        $cmClickCount = (int) ($contact->get('cm_click_count') ?? 0);

        $cmToursClicks = (int) ($contact->get('cm_tours_clicks') ?? 0);
        $cmWorkshopClicks = (int) ($contact->get('cm_workshop_clicks') ?? 0);
        $cmArticleClicks = (int) ($contact->get('cm_article_clicks') ?? 0);
        $cmEventClicks = (int) ($contact->get('cm_event_clicks') ?? 0);

        $cmLastClick = $contact->get('cm_last_click');

        if ($orders > 0) $score += 15;
        if ($orders > 5) $score += 10;
        if ($spent > 1000) $score += 10;

        if (!empty($tours)) $score += 25;
        if (count($tours) > 1) $score += 10;

        if (!empty($workshops)) $score += 10;

        if ($cmStatus === 'Active') $score += 5;
        if ($cmOpenCount > 10) $score += 5;
        if ($cmClickCount > 5) $score += 5;

        $score += $cmToursClicks * 2;
        $score += $cmWorkshopClicks * 2;
        $score += $cmArticleClicks;
        $score += $cmEventClicks * 2;

        if ($cmLastClick) {
            $days = (time() - strtotime($cmLastClick)) / 86400;
            if ($days < 30) $score += 10;
        }

        return $score;
    }

    private function inferInterest($contact): ?string
    {
        $tours = strtolower((string) ($contact->get('cLastToursViewed') ?? ''));
        $articles = strtolower((string) ($contact->get('cLastArticlesRead') ?? ''));

        $text = $tours . ' ' . $articles;

        if (str_contains($text, 'japan') || str_contains($text, 'kyoto')) return 'Japan';
        if (str_contains($text, 'blue')) return 'Blue';
        if (str_contains($text, 'textile')) return 'Textile';
        if (str_contains($text, 'workshop')) return 'Workshop';

        return null;
    }

    private function ensureEngagement(string $contactId)
    {
        $repo = $this->em->getRepository('CEngagement');

        $engagement = $repo->where(['contactId' => $contactId])->findOne();

        if (!$engagement) {
            $engagement = $this->em->getEntity('CEngagement');
            $engagement->set('contactId', $contactId);
            $this->em->saveEntity($engagement);
        }

        return $engagement;
    }

    private function askClaude(array $context): array
    {
        $apiKey = getenv('ANTHROPIC_API_KEY');

        if (!$apiKey) {
            return [
                'intent' => 'unknown',
                'action' => 'Send welcome email',
                'priority' => 'low'
            ];
        }

        $leanContext = [
            'status' => $context['status'] ?? null,
            'score' => $context['score'] ?? 0,
            'orders' => $context['orders'] ?? 0,
            'spent' => $context['spent'] ?? 0,
            'interest' => $context['interest'] ?? null
        ];

        $prompt = "Return JSON: intent,action,priority.
Rules:
- action max 10 words
- one action only
- no explanation
Data:" . json_encode($leanContext);

        $ch = curl_init('https://api.anthropic.com/v1/messages');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                "model" => "claude-haiku-4-5-20251001",
                "max_tokens" => 60,
                "messages" => [
                    [
                        "role" => "user",
                        "content" => [
                            [
                                "type" => "text",
                                "text" => $prompt
                            ]
                        ]
                    ]
                ]
            ])
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (!isset($data['content'][0]['text'])) {
            return [
                'intent' => 'unknown',
                'action' => 'Send welcome email',
                'priority' => 'low'
            ];
        }

        $text = $data['content'][0]['text'];

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $json = $matches[0];
        } else {
            $json = $text;
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [
                'intent' => 'unknown',
                'action' => 'Send welcome email',
                'priority' => 'low'
            ];
        }

        $action = $decoded['action'] ?? '';
        $action = preg_split('/,| and /i', $action)[0];
        $words = explode(' ', $action);
        $action = implode(' ', array_slice($words, 0, 10));

        if (strlen($action) < 5) {
            $action = 'Send welcome email';
        }

        return [
            'intent' => strtolower($decoded['intent'] ?? 'unknown'),
            'action' => $action,
            'priority' => strtolower($decoded['priority'] ?? 'low')
        ];
    }
}
