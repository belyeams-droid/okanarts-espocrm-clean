<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\ORM\EntityManager;

class CampaignMonitorActivitySync extends Base
{
    protected EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function run(): void
    {
        echo "Starting Campaign Monitor Activity Sync\n";

        $env = parse_ini_file('/var/www/html/custom/campaign-monitor.env');

        $apiKey   = $env['CM_API_KEY'];
        $clientId = $env['CM_CLIENT_ID'];

        $campaigns = $this->getRecentCampaigns($apiKey, $clientId);

        foreach ($campaigns as $campaign) {

            $campaignId = $campaign['CampaignID'];

            if ($this->campaignAlreadyProcessed($campaignId)) {
                continue;
            }

            echo "Processing campaign: $campaignId\n";

            $this->processOpens($apiKey, $campaignId);
            $this->processClicks($apiKey, $campaignId);

            $this->markCampaignProcessed($campaignId);
        }

        echo "Campaign Monitor Activity Sync Complete\n";
    }

    private function getRecentCampaigns($apiKey, $clientId)
    {
        $url = "https://api.createsend.com/api/v3.2/clients/$clientId/campaigns.json?sentfromdate=2021-01-01";
        return $this->fetch($url, $apiKey);
    }

    private function processOpens($apiKey, $campaignId)
    {
        $page = 1;
        $seenEmails = [];

        while (true) {

            $url = "https://api.createsend.com/api/v3.2/campaigns/$campaignId/opens.json?page=$page&pagesize=1000";
            $data = $this->fetch($url, $apiKey);

            if (empty($data['Results'])) break;

            foreach ($data['Results'] as $open) {

                $email = strtolower(trim($open['EmailAddress']));
                $date  = $open['Date'];

                if (isset($seenEmails[$email])) continue;
                $seenEmails[$email] = true;

                $contact = $this->findContactByEmail($email);
                if (!$contact) continue;

                $contact->set('cmOpenCount', ($contact->get('cmOpenCount') ?? 0) + 1);

                if (
                    !$contact->get('cmLastOpen') ||
                    strtotime($date) > strtotime($contact->get('cmLastOpen'))
                ) {
                    $contact->set('cmLastOpen', $date);
                }

                $this->entityManager->saveEntity($contact);
            }

            $page++;
        }
    }

    private function processClicks($apiKey, $campaignId)
    {
        $page = 1;

        while (true) {

            $url = "https://api.createsend.com/api/v3.2/campaigns/$campaignId/clicks.json?page=$page&pagesize=1000";
            $data = $this->fetch($url, $apiKey);

            if (empty($data['Results'])) break;

            foreach ($data['Results'] as $click) {

                $email = strtolower(trim($click['EmailAddress']));
                $date  = $click['Date'];

                $urlClicked =
                    $click['URL']
                    ?? $click['Url']
                    ?? $click['url']
                    ?? $click['Link']
                    ?? $click['Href']
                    ?? '';

                $urlClicked = strtolower(trim($urlClicked));

                if (!$urlClicked || !filter_var($urlClicked, FILTER_VALIDATE_URL)) {
                    continue;
                }

                $contact = $this->findContactByEmail($email);

                if (!$contact) {
                    continue; // keep behavior simple and safe
                }

                // ✅ NEW: STORE RAW CLICK EVENT (SOURCE OF TRUTH)
                $clickedAt = date('Y-m-d H:i:s', strtotime($date));

                $this->storeClickEvent(
                    $contact->getId(),
                    $campaignId,
                    $urlClicked,
                    $clickedAt
                );

                // ✅ EXISTING: engagement record (kept)
                $engagement = $this->entityManager->getEntity('CEngagement');

                $engagement->set('contactId', $contact->getId());
                $engagement->set('clickedUrl', $urlClicked);
                $engagement->set('firstEngagedAt', $date);
                $engagement->set('lastActivityAt', $date);

                $this->entityManager->saveEntity($engagement);

                // ✅ EXISTING: counters (kept)
                $contact->set('cmClickCount', ($contact->get('cmClickCount') ?? 0) + 1);

                if (
                    !$contact->get('cmLastClick') ||
                    strtotime($date) > strtotime($contact->get('cmLastClick'))
                ) {
                    $contact->set('cmLastClick', $date);
                }

                $this->entityManager->saveEntity($contact);
            }

            $page++;
        }
    }

    // ✅ NEW CORE FUNCTION (RAW EVENT STORAGE)
    private function storeClickEvent($contactId, $campaignId, $url, $clickedAt)
    {
        $pdo = $this->entityManager->getPDO();

        $urlHash = md5($url);

        // dedupe check
        $stmt = $pdo->prepare("
            SELECT id FROM cm_click_event
            WHERE contact_id = :contact_id
            AND url_hash = :url_hash
            AND clicked_at = :clicked_at
            LIMIT 1
        ");

        $stmt->execute([
            'contact_id' => $contactId,
            'url_hash' => $urlHash,
            'clicked_at' => $clickedAt
        ]);

        if ($stmt->fetch()) {
            return;
        }

        $id = \Espo\Core\Utils\Util::generateId();

        $insert = $pdo->prepare("
            INSERT INTO cm_click_event (
                id,
                contact_id,
                campaign_id,
                url,
                url_hash,
                clicked_at
            ) VALUES (
                :id,
                :contact_id,
                :campaign_id,
                :url,
                :url_hash,
                :clicked_at
            )
        ");

        $insert->execute([
            'id' => $id,
            'contact_id' => $contactId,
            'campaign_id' => $campaignId,
            'url' => $url,
            'url_hash' => $urlHash,
            'clicked_at' => $clickedAt
        ]);
    }

    private function fetch($url, $apiKey)
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ':x');

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?? [];
    }

    protected function findContactByEmail(string $email)
    {
        $pdo = $this->entityManager->getPDO();

        $stmt = $pdo->prepare("
            SELECT c.id
            FROM contact c
            JOIN entity_email_address eea ON eea.entity_id = c.id
            JOIN email_address ea ON ea.id = eea.email_address_id
            WHERE ea.name = :email
            AND eea.deleted = 0
            LIMIT 1
        ");

        $stmt->execute(['email' => $email]);

        $row = $stmt->fetch();

        return $row
            ? $this->entityManager->getEntityById('Contact', $row['id'])
            : null;
    }

    private function campaignAlreadyProcessed($campaignId): bool
    {
        $pdo = $this->entityManager->getPDO();

        $stmt = $pdo->prepare("
            SELECT campaign_id
            FROM campaign_monitor_processed
            WHERE campaign_id = :id
        ");

        $stmt->execute(['id' => $campaignId]);

        return (bool) $stmt->fetch();
    }

    private function markCampaignProcessed($campaignId): void
    {
        $pdo = $this->entityManager->getPDO();

        $stmt = $pdo->prepare("
            INSERT INTO campaign_monitor_processed (campaign_id, processed_at)
            VALUES (:id, NOW())
        ");

        $stmt->execute(['id' => $campaignId]);
    }
}
