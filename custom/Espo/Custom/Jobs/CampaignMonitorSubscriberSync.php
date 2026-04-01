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

        $dryRun = false;

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
                        ->where(['cmSubscriberId' => $cmId])
                        ->findOne();
                }

                // -----------------------------------------
                // 2. FALLBACK: Match by Email (ROBUST)
                // -----------------------------------------
                if (!$contact && $email) {
                    $pdo = $this->entityManager->getPDO();

                    $stmt = $pdo->prepare("
                        SELECT eea.entity_id
                        FROM entity_email_address eea
                        JOIN email_address ea ON ea.id = eea.email_address_id
                        WHERE LOWER(ea.name) = :email
                        AND eea.entity_type = 'Contact'
                        AND eea.deleted = 0
                        LIMIT 1
                    ");

                    $stmt->execute(['email' => $email]);
                    $row = $stmt->fetch();

                    if ($row) {
                        $contact = $this->entityManager
                            ->getEntityById('Contact', $row['entity_id']);
                    }
                }

                // -----------------------------------------
                // 3. CREATE if not found
                // -----------------------------------------
                if (!$contact) {
                    $contact = $this->entityManager->getNewEntity('Contact');
                }

                // -----------------------------------------
                // 4. SET IDENTIFIERS
                // -----------------------------------------
                if ($cmId) {
                    $contact->set('cmSubscriberId', $cmId);
                }

                if ($email) {
                    $contact->set('emailAddressData', [
                        [
                            'emailAddress' => $email,
                            'primary' => true,
                            'optOut' => false,
                            'invalid' => false,
                        ]
                    ]);
                }

                // -----------------------------------------
                // 5. BUSINESS FIELD
                // -----------------------------------------
                $contact->set('cmStatus', $state);

                // -----------------------------------------
                // 6. SAVE
                // -----------------------------------------
                $this->entityManager->saveEntity($contact, ['silent' => true]);
            }

            echo "Synced subscriber page $page\n";

            $page++;
        }

        echo "Campaign Monitor Subscriber Sync Complete\n";
    }
}
