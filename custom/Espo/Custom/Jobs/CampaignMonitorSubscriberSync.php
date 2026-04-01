<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\ORM\EntityManager;

class CampaignMonitorSubscriberSync extends Base
{
    protected EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function run(): void
    {
        echo "Starting Campaign Monitor Subscriber Sync\n";

        // -----------------------------
        // DRY RUN TOGGLE
        // -----------------------------
        $dryRun = false; // ← set to false to enable real writes

        $env = parse_ini_file('/var/www/html/custom/campaign-monitor.env');

        $apiKey  = $env['CM_API_KEY']  ?? null;
        $listId  = $env['CM_LIST_ID']  ?? null;

        if (!$apiKey || !$listId) {
            echo "Campaign Monitor credentials missing\n";
            return;
        }

        $page = 1;

        while (true) {

            $url = "https://api.createsend.com/api/v3.2/lists/$listId/active.json?page=$page&pagesize=100";

            $ch = curl_init($url);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ':x');
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);

            if (!isset($data['Results']) || empty($data['Results'])) {
                break;
            }

            foreach ($data['Results'] as $subscriber) {

                $email = strtolower(trim($subscriber['EmailAddress'] ?? ''));
                $state = $subscriber['State'] ?? 'Active';

                // -----------------------------------------
                // GENERATE DETERMINISTIC CM ID
                // -----------------------------------------
                $cmId = $email ? md5($email) : '';

                if (!$cmId && !$email) {
                    continue;
                }

                $contact = null;

                // -----------------------------------------
                // 1. PRIMARY: Match by Campaign Monitor ID
                // -----------------------------------------
                if ($cmId) {
                    $contact = $this->entityManager
                        ->getRepository('Contact')
                        ->where(['c_cm_subscriber_id' => $cmId])
                        ->findOne();
                }

                // -----------------------------------------
                // 2. FALLBACK: Match by Email
                // -----------------------------------------
                if (!$contact && $email) {
                    $contact = $this->entityManager
                        ->getRepository('Contact')
                        ->where(['emailAddress' => $email])
                        ->findOne();
                }

                // -----------------------------------------
                // 3. CREATE if not found
                // -----------------------------------------
                $isNew = false;

                if (!$contact) {
                    $contact = $this->entityManager->getNewEntity('Contact');
                    $isNew = true;
                }

                // -----------------------------------------
                // 4. SET IDENTIFIERS FIRST
                // -----------------------------------------
                if ($cmId) {
                    $contact->set('c_cm_subscriber_id', $cmId);
                }

                if ($email) {
                    $contact->set('emailAddress', $email);
                }

                // -----------------------------------------
                // 5. BUSINESS FIELDS
                // -----------------------------------------
                $contact->set('cmStatus', $state);

                // -----------------------------------------
                // 6. SAVE OR DRY RUN
                // -----------------------------------------
                $action = $isNew ? 'CREATE' : 'UPDATE';

                if ($dryRun) {
                    echo "[DRY RUN][$action] "
                        . ($contact->get('id') ?? 'NEW')
                        . " | email: $email"
                        . " | cm_id: $cmId"
                        . " | status: $state\n";
                } else {
                    $this->entityManager->saveEntity($contact, ['silent' => true]);
                }
            }

            echo "Synced subscriber page $page\n";

            $page++;
        }

        echo "Campaign Monitor Subscriber Sync Complete\n";
    }
}
