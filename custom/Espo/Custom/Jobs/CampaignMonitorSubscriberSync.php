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

        $env = parse_ini_file('/var/www/html/custom/campaign-monitor.env');

        $apiKey  = $env['CM_API_KEY']  ?? null;
        $listId  = $env['CM_LIST_ID']  ?? null;

        if (!$apiKey || !$listId) {
            echo "Campaign Monitor credentials missing\n";
            return;
        }

        $pdo = $this->entityManager->getPDO();

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

                if (!isset($subscriber['EmailAddress'])) {
                    continue;
                }

                $email = strtolower(trim($subscriber['EmailAddress']));
                $state = $subscriber['State'] ?? 'Active';

                // find email in Espo email table
                $stmt = $pdo->prepare("
                    SELECT id 
                    FROM email_address 
                    WHERE LOWER(name) = :email
                    LIMIT 1
                ");

                $stmt->execute(['email' => $email]);
                $emailRow = $stmt->fetch();

                if (!$emailRow) {
                    continue;
                }

                // find contact linked to email
                $stmt = $pdo->prepare("
                    SELECT entity_id 
                    FROM entity_email_address 
                    WHERE email_address_id = :emailId
                    AND entity_type = 'Contact'
                    AND deleted = 0
                    LIMIT 1
                ");

                $stmt->execute(['emailId' => $emailRow['id']]);
                $entityRow = $stmt->fetch();

                if (!$entityRow) {
                    continue;
                }

                $contact = $this->entityManager
                    ->getEntityById('Contact', $entityRow['entity_id']);

                if (!$contact) {
                    continue;
                }

                // store campaign monitor status
                $contact->set('cmStatus', $state);

                $this->entityManager->saveEntity($contact);
            }

            echo "Synced subscriber page $page\n";

            $page++;
        }

        echo "Campaign Monitor Subscriber Sync Complete\n";
    }
}
