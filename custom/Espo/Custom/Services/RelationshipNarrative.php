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

        /*
        ------------------------------------------------
        FINANCIAL RELATIONSHIP
        ------------------------------------------------
        */

        $spent  = (float) ($contact->get('cTotalSpent') ?? 0);
        $orders = (int)   ($contact->get('cTotalOrders') ?? 0);

        if ($spent > 0 && $orders > 0) {
            $orderWord = $orders === 1 ? 'order' : 'orders';
            $sentences[] = "{$name} has invested $" . number_format($spent, 0) . " in OkanArts experiences across {$orders} {$orderWord}.";
        } elseif ($spent > 0) {
            $sentences[] = "{$name} has invested $" . number_format($spent, 0) . " in OkanArts experiences.";
        }

        /*
        ------------------------------------------------
        LOCATION + TENURE
        ------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT MIN(first_engaged_at) as first_ever
            FROM c_engagement
            WHERE contact_id = :contactId AND deleted = 0
        ");
        $stmt->execute(['contactId' => $contactId]);
        $engagementRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        $firstEngaged  = $engagementRow['first_ever'] ?? null;

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

        /*
        ------------------------------------------------
        BOOKINGS (PAST + UPCOMING)
        ------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT tour_name, departure_date_time, client_status, companions
            FROM c_booking
            WHERE contact_id = :contactId AND deleted = 0
            ORDER BY departure_date_time DESC
        ");
        $stmt->execute(['contactId' => $contactId]);
        $bookings = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $pastBookings     = [];
        $upcomingBookings = [];
        $now = time();

        foreach ($bookings as $b) {
            $departure = $b['departure_date_time'] ? strtotime($b['departure_date_time']) : null;
            if ($departure && $departure > $now) {
                $upcomingBookings[] = $b;
            } else {
                $pastBookings[] = $b;
            }
        }

        foreach ($upcomingBookings as $b) {
            $tourName   = $b['tour_name'];
            $companions = trim((string) ($b['companions'] ?? ''));
            if ($companions) {
                $sentences[] = "{$name} is booked on the upcoming {$tourName} journey, traveling with {$companions}.";
            } else {
                $sentences[] = "{$name} is booked on the upcoming {$tourName} journey.";
            }
        }

        if (count($pastBookings) === 1) {
            $b          = $pastBookings[0];
            $tourName   = $b['tour_name'];
            $companions = trim((string) ($b['companions'] ?? ''));
            if ($companions) {
                $sentences[] = "{$name} has traveled with OkanArts on the {$tourName} journey, accompanied by {$companions}.";
            } else {
                $sentences[] = "{$name} has traveled with OkanArts on the {$tourName} journey.";
            }
        } elseif (count($pastBookings) > 1) {
            $tourNames   = array_column($pastBookings, 'tour_name');
            $count       = count($pastBookings);
            $sentences[] = "{$name} has completed {$count} OkanArts journeys: " . implode(', ', $tourNames) . ".";
        }

        /*
        ------------------------------------------------
        OPEN DEPOSITS (pipeline signal)
        ------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt, SUM(amount) as total
            FROM c_shopify_tour_deposit
            WHERE contact_id = :contactId AND deleted = 0
            AND contract_status IN ('Deposit Received', 'Customer Requires Attention')
        ");
        $stmt->execute(['contactId' => $contactId]);
        $depositRow   = $stmt->fetch(\PDO::FETCH_ASSOC);
        $openDeposits = (int)   ($depositRow['cnt']   ?? 0);
        $depositTotal = (float) ($depositRow['total'] ?? 0);

        if ($openDeposits > 0) {
            $depositWord = $openDeposits === 1 ? 'an open deposit' : "{$openDeposits} open deposits";
            $sentences[] = "{$name} has {$depositWord} totaling $" . number_format($depositTotal, 0) . ", indicating active purchase intent.";
        }

        /*
        ------------------------------------------------
        WAITING LIST
        ------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT t.name as tour_name
            FROM c_waiting_list w
            LEFT JOIN c_tours t ON t.id = w.tours_id
            WHERE w.contact_id = :contactId AND w.deleted = 0
        ");
        $stmt->execute(['contactId' => $contactId]);
        $waitingRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($waitingRows)) {
            $tourNames = array_filter(array_column($waitingRows, 'tour_name'));
            if (!empty($tourNames)) {
                $sentences[] = "{$name} is on the waiting list for: " . implode(', ', $tourNames) . ".";
            }
        }

        /*
        ------------------------------------------------
        CANCELLATIONS
        ------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT t.name as tour_name
            FROM c_cancellations c
            LEFT JOIN c_tours t ON t.id = c.tours_id
            WHERE c.contact_id = :contactId AND c.deleted = 0
        ");
        $stmt->execute(['contactId' => $contactId]);
        $cancellations = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($cancellations)) {
            $tourNames = array_filter(array_column($cancellations, 'tour_name'));
            if (!empty($tourNames)) {
                $sentences[] = "{$name} previously cancelled from: " . implode(', ', $tourNames) . ".";
            }
        }

        /*
        ------------------------------------------------
        EMAIL ENGAGEMENT (specific metrics)
        ------------------------------------------------
        */

        $clicks    = (int)    ($contact->get('cmClickCount') ?? 0);
        $opens     = (int)    ($contact->get('cmOpenCount')  ?? 0);
        $lastClick = $contact->get('cmLastClick');
        $cmStatus  = (string) ($contact->get('cmStatus') ?? '');

        if ($cmStatus === 'Active' && ($clicks > 0 || $opens > 0)) {

            $recencyNote = '';
            if ($lastClick) {
                $daysAgo = floor((time() - strtotime($lastClick)) / 86400);
                if ($daysAgo <= 7) {
                    $recencyNote = ', most recently this week';
                } elseif ($daysAgo <= 30) {
                    $recencyNote = ', most recently within the last month';
                } elseif ($daysAgo <= 90) {
                    $recencyNote = ', most recently within the last 3 months';
                }
            }

            if ($clicks >= 100) {
                $sentences[] = "{$name} is a highly engaged email subscriber with {$clicks} clicks and {$opens} opens{$recencyNote}.";
            } elseif ($clicks >= 20) {
                $sentences[] = "{$name} is an active email subscriber with {$clicks} clicks and {$opens} opens{$recencyNote}.";
            } elseif ($clicks > 0) {
                $sentences[] = "{$name} occasionally engages with OkanArts emails ({$clicks} clicks{$recencyNote}).";
            } else {
                $sentences[] = "{$name} is an active email subscriber.";
            }

        } elseif ($cmStatus === 'Unsubscribed') {
            $sentences[] = "{$name} has unsubscribed from OkanArts emails.";
        }

        /*
        ------------------------------------------------
        ENGAGEMENT EVENTS (deduped + normalised)
        ------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT clicked_url
            FROM c_engagement
            WHERE contact_id = :contactId
            AND clicked_url IS NOT NULL
            AND deleted = 0
        ");
        $stmt->execute(['contactId' => $contactId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $specificTours = [];
        $articles      = [];
        $hasTour       = false;
        $hasSoft       = false;
        $hasArticle    = false;
        $seenUrls      = [];

        foreach ($rows as $row) {
            $url = $row['clicked_url'] ?? null;
            if (!$url) continue;

            $url = strtolower(trim($url));
            $url = preg_replace('#^https?://#', '', $url);
            $url = preg_replace('#^www\.#', '', $url);
            $url = strtok($url, '?');
            $url = rtrim($url, '/');

            if (isset($seenUrls[$url])) continue;
            $seenUrls[$url] = true;

            $slug = $this->extractTourFromUrl($url);
            if ($slug) {
                $specificTours[$slug] = true;
                $hasTour = true;
            }

            if (strpos($url, '/collections/') !== false || strpos($url, '/pages/') !== false) {
                $hasSoft = true;
            }

            if (strpos($url, '/blogs/blog/') !== false) {
                $hasArticle = true;
                $path        = parse_url('https://' . $url, PHP_URL_PATH);
                $articleSlug = basename($path);
                if ($articleSlug && !isset($articles[$articleSlug])) {
                    $articles[$articleSlug] = $this->formatArticleTitle($articleSlug);
                }
            }
        }

        // Tour interest
        if (!empty($specificTours)) {
            $names       = array_map([$this, 'formatTourName'], array_keys($specificTours));
            $sentences[] = "{$name} has shown interest in the following tours: " . implode(', ', $names) . ".";
        }

        // Article interest (up to 3)
        if (!empty($articles)) {
            $titles = array_slice(array_values($articles), 0, 3);
            if (count($titles) === 1) {
                $sentences[] = "{$name} recently read the {$titles[0]} article.";
            } else {
                $last        = array_pop($titles);
                $sentences[] = "{$name} has read articles including " . implode(', ', $titles) . " and {$last}.";
            }
        }

        // Soft interest (only if no specific tour clicks)
        if ($hasSoft && empty($specificTours)) {
            $sentences[] = "{$name} has been browsing OkanArts offerings and collections.";
        }

        /*
        ------------------------------------------------
        CONTENT INTEREST BREAKDOWN
        ------------------------------------------------
        */

        $toursClicks    = (int) ($contact->get('cmToursClicks')    ?? 0);
        $workshopClicks = (int) ($contact->get('cmWorkshopClicks') ?? 0);
        $articleClicks  = (int) ($contact->get('cmArticleClicks')  ?? 0);
        $eventClicks    = (int) ($contact->get('cmEventClicks')    ?? 0);

        $interests = [];
        if ($toursClicks    > 0) $interests[] = "tours ({$toursClicks})";
        if ($workshopClicks > 0) $interests[] = "workshops ({$workshopClicks})";
        if ($articleClicks  > 0) $interests[] = "articles ({$articleClicks})";
        if ($eventClicks    > 0) $interests[] = "events ({$eventClicks})";

        if (!empty($interests)) {
            $sentences[] = "{$name}'s email click interests: " . implode(', ', $interests) . ".";
        }

        /*
        ------------------------------------------------
        ENGAGEMENT SCORE
        ------------------------------------------------
        */

        $score = 0;

        // Spend
        if ($spent >= 10000)     $score += 30;
        elseif ($spent >= 2000)  $score += 20;
        elseif ($spent >= 500)   $score += 10;
        elseif ($spent > 0)      $score += 5;

        // Orders
        if ($orders >= 4)        $score += 15;
        elseif ($orders >= 2)    $score += 10;
        elseif ($orders >= 1)    $score += 5;

        // Email clicks
        if ($clicks >= 100)      $score += 20;
        elseif ($clicks >= 50)   $score += 15;
        elseif ($clicks >= 10)   $score += 10;
        elseif ($clicks > 0)     $score += 5;

        // URL engagement
        if ($hasTour)            $score += 15;
        if ($hasSoft)            $score += 5;
        if ($hasArticle)         $score += 5;

        // Booked / traveled
        if (!empty($pastBookings))     $score += 20;
        if (!empty($upcomingBookings)) $score += 10;

        // Pipeline signals
        if ($openDeposits > 0)   $score += 10;
        if (!empty($waitingRows)) $score += 5;

        $score = min(100, $score);

        /*
        ------------------------------------------------
        INSIGHT
        ------------------------------------------------
        */

        if ($score >= 70)        $insight = 'High Intent';
        elseif ($score >= 40)    $insight = 'Warming Up';
        elseif ($score >= 15)    $insight = 'Exploring';
        else                     $insight = 'General Contact';

        /*
        ------------------------------------------------
        FALLBACK
        ------------------------------------------------
        */

        if (empty($sentences)) {
            $sentences[] = "{$name} is part of the OkanArts community.";
        }

        /*
        ------------------------------------------------
        SAVE
        ------------------------------------------------
        */

        $narrative = implode(' ', $sentences);

        $contact->set([
            'relationshipNarrative' => $narrative,
            'relationshipInsight'   => $insight,
            'engagementScore'       => $score,
        ]);

        $this->em->getRepository('Contact')->save($contact);

        error_log("RelationshipNarrative V18 | Contact {$contactId} | Score {$score}");
    }
}
