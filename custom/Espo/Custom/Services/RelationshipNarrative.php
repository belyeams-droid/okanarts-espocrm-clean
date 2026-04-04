<?php

namespace Espo\Custom\Services;

use Espo\ORM\EntityManager;

class RelationshipNarrative
{
    private EntityManager $em;

    public function __construct(EntityManager $entityManager)
    {
        $this->em = $entityManager;
    }

    private function extractTourFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!$path) {
            return null;
        }

        if (strpos($path, '/products/') === false) {
            return null;
        }

        $slug = basename($path);
        $slug = strtok($slug, '?');
        $slug = preg_replace('/^deposit-/', '', $slug);

        return $slug ?: null;
    }

    private function formatTourName(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }

    private function formatArticleTitle(string $slug): string
    {
        $slug = preg_replace('/-\d+$/', '', $slug);
        return ucwords(str_replace('-', ' ', $slug));
    }

    private function resolveName($contact): string
    {
        $preferred = trim((string) ($contact->get('cPreferredName') ?? ''));
        if ($preferred) return ucfirst(strtolower($preferred));

        $first = trim((string) ($contact->get('firstName') ?? ''));
        if ($first) return ucfirst(strtolower($first));

        return 'This contact';
    }

    private function formatLocation($contact): ?string
    {
        $city    = trim((string) ($contact->get('addressCity') ?? ''));
        $state   = trim((string) ($contact->get('addressState') ?? ''));
        $country = trim((string) ($contact->get('addressCountry') ?? ''));

        if ($city && $state)    return "{$city}, {$state}";
        if ($city && $country)  return "{$city}, {$country}";
        if ($country)           return $country;

        return null;
    }

    public function generateForContact(string $contactId): void
    {
        $contact = $this->em->getEntityById('Contact', $contactId);

        if (!$contact) {
            error_log("RelationshipNarrative: Contact {$contactId} not found");
            return;
        }

        $name     = $this->resolveName($contact);
        $pdo      = $this->em->getPDO();
        $sentences = [];

        // ---------------- FINANCIAL ----------------

        $spent  = (float) ($contact->get('cTotalSpent') ?? 0);
        $orders = (int)   ($contact->get('cTotalOrders') ?? 0);

        if ($spent > 0 && $orders > 0) {
            $orderWord = $orders === 1 ? 'order' : 'orders';
            $sentences[] = "{$name} has invested $" . number_format($spent, 0) . " in OkanArts experiences across {$orders} {$orderWord}.";
        } elseif ($spent > 0) {
            $sentences[] = "{$name} has invested $" . number_format($spent, 0) . " in OkanArts experiences.";
        }

        // ---------------- LOCATION ----------------

        $stmt = $pdo->prepare("
            SELECT MIN(first_engaged_at) as first_ever
            FROM c_engagement
            WHERE contact_id = :contactId AND deleted = 0
        ");
        $stmt->execute(['contactId' => $contactId]);
        $firstEngaged  = $stmt->fetch(\PDO::FETCH_ASSOC)['first_ever'] ?? null;

        $location = $this->formatLocation($contact);

        if ($location && $firstEngaged) {
            $year = date('Y', strtotime($firstEngaged));
            array_unshift($sentences, "{$name} is based in {$location} and has been part of the OkanArts community since {$year}.");
        } elseif ($location) {
            array_unshift($sentences, "{$name} is based in {$location}.");
        } elseif ($firstEngaged) {
            $year = date('Y', strtotime($firstEngaged));
            array_unshift($sentences, "{$name} has been part of the OkanArts community since {$year}.");
        }

        // ---------------- BOOKINGS ----------------

        $stmt = $pdo->prepare("
            SELECT tour_name, departure_date_time, companions
            FROM c_booking
            WHERE contact_id = :contactId AND deleted = 0
            ORDER BY departure_date_time DESC
        ");
        $stmt->execute(['contactId' => $contactId]);
        $bookings = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $past = [];
        $future = [];
        $now = time();

        foreach ($bookings as $b) {
            $departure = $b['departure_date_time'] ? strtotime($b['departure_date_time']) : null;
            ($departure && $departure > $now) ? $future[] = $b : $past[] = $b;
        }

        foreach ($future as $b) {
            $sentences[] = "{$name} is booked on the upcoming {$b['tour_name']} journey.";
        }

        if (count($past) === 1) {
            $sentences[] = "{$name} has traveled with OkanArts on the {$past[0]['tour_name']} journey.";
        } elseif (count($past) > 1) {
            $names = array_column($past, 'tour_name');
            $sentences[] = "{$name} has completed " . count($past) . " journeys: " . implode(', ', $names) . ".";
        }

        // ---------------- DEPOSITS ----------------

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt, SUM(amount) as total
            FROM c_shopify_tour_deposit
            WHERE contact_id = :contactId AND deleted = 0
        ");
        $stmt->execute(['contactId' => $contactId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (($row['cnt'] ?? 0) > 0) {
            $sentences[] = "{$name} has open deposits totaling $" . number_format($row['total'], 0) . ".";
        }

        // ---------------- SCORE ----------------

        $score = min(100, $spent > 2000 ? 80 : 40);
        $insight = $score >= 70 ? 'High Intent' : 'Warming Up';

        if (empty($sentences)) {
            $sentences[] = "{$name} is part of the OkanArts community.";
        }

        $contact->set([
            'relationshipNarrative' => implode(' ', $sentences),
            'relationshipInsight'   => $insight,
            'engagementScore'       => $score,
        ]);

        // 🔥 FIX: silent save prevents infinite loop
        $this->em->saveEntity($contact, ['silent' => true]);

        error_log("RelationshipNarrative V19 | Contact {$contactId} | Score {$score}");
    }
}
