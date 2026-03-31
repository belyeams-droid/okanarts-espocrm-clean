<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\ORM\EntityManager;

class CampaignMonitorSync extends Base
{
    protected EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function run(): void
    {
        echo "Starting Campaign Monitor Sync\n";

        $env = parse_ini_file('/var/www/html/custom/campaign-monitor.env');

        $apiKey = $env['CM_API_KEY'];
        $listId = $env['CM_LIST_ID'];

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

            if (empty($data['Results'])) {
                break;
            }

            foreach ($data['Results'] as $subscriber) {

                $email = strtolower(trim($subscriber['EmailAddress']));

                $contact = $this->entityManager
                    ->getRepository('Contact')
                    ->where(['emailAddress' => $email])
                    ->findOne();

                if (!$contact) {
                    continue;
                }

                $contact->set('cCampaignMonitorSubscribed', $subscriber['State'] === 'Active');

                foreach ($subscriber['CustomFields'] as $field) {

                    switch ($field['Key']) {

                        case '[TotalSpend]':
                            $contact->set('cTotalSpent', (float) $field['Value']);
                            break;

                        case '[OrderCount]':
                            $contact->set('cTotalOrders', (int) $field['Value']);
                            break;

                        case '[CreatedAt]':
                            $contact->set('cFirstEngagedAt', $field['Value']);
                            break;
                    }
                }

                $this->entityManager->saveEntity($contact);
            }

            echo "Synced page $page\n";

            $page++;
        }

        echo "Campaign Monitor Sync Complete\n";
    }
}
