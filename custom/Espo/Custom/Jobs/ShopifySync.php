<?php
/**
 * Modern ShopifySync Job for EspoCRM v8+ / v9+
 * Path: custom/Espo/Custom/Jobs/ShopifySync.php
 */

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Config;
use Exception;

class ShopifySync implements JobDataLess
{
    private $entityManager;
    private $log;
    private $config;

    public function __construct(EntityManager $entityManager, Log $log, Config $config)
    {
        $this->entityManager = $entityManager;
        $this->log = $log;
        $this->config = $config;
    }

    public function run(): void
    {

        $this->log->warning('[ShopifySync] START');
        $this->log->warning('[ShopifySync] FINISH');

        try {

            // === CONFIG ===
            $shopifyStore = $this->config->get('shopifyStore');
            $shopifyApiKey = $this->config->get('shopifyApiKey');
            $shopifyApiVersion = $this->config->get('shopifyApiVersion');

            $stateFile = 'data/shopify_sync_state.json';

            // Load last sync time
            $lastSync = '1970-01-01T00:00:00Z';

            if (file_exists($stateFile)) {
                $state = json_decode(file_get_contents($stateFile), true);
                if (isset($state['last_customer_sync'])) {
                    $lastSync = $state['last_customer_sync'];
                }
            }

            $this->log->info("Using updated_at_min: $lastSync");

            // API URL
            $url = "https://$shopifyStore/admin/api/$shopifyApiVersion/customers.json"
                 . "?updated_at_min=$lastSync"
                 . "&limit=250"
                 . "&status=any";

            $headers = [
                'X-Shopify-Access-Token: ' . $shopifyApiKey,
                'Content-Type: application/json'
            ];

            $customers = [];
            $nextUrl = $url;
            $maxUpdatedAt = $lastSync;

            while ($nextUrl) {

                $ch = curl_init($nextUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                curl_close($ch);

                if ($httpCode !== 200) {
                    throw new Exception("Shopify API error: HTTP $httpCode");
                }

                $data = json_decode($response, true);

                if (!isset($data['customers'])) {
                    break;
                }

                $customers = array_merge($customers, $data['customers']);

                foreach ($data['customers'] as $cust) {
                    if (!empty($cust['updated_at']) && $cust['updated_at'] > $maxUpdatedAt) {
                        $maxUpdatedAt = $cust['updated_at'];
                    }
                }

                // Basic next page (improve with full Link header if >250 customers)
                $nextUrl = null;
            }

            $count = count($customers);
            $this->log->info("Fetched $count customers");

            $created = 0;

            foreach ($customers as $shopifyCust) {

                $email = $shopifyCust['email'] ?? null;

                if (empty($email)) {
                    $this->log->info("Skipping customer without email");
                    continue;
                }

                // Skip nameless customers
                $firstName = $shopifyCust['first_name'] ?? '';
                $lastName  = $shopifyCust['last_name'] ?? '';

                if (empty($firstName) && empty($lastName)) {
                    $this->log->info("Skipping nameless customer (email-only): $email");
                    continue;
                }

                // Prevent duplicates by email
                $existing = $this->entityManager
                    ->getRepository('Contact')
                    ->where(['emailAddress' => $email])
                    ->findOne();


                if ($existing) {

                    $updated = false;

                    // Total Orders
                    $shopifyOrders = isset($shopifyCust['orders_count'])
                        ? (int) $shopifyCust['orders_count']
                        : null;

                    if ($shopifyOrders !== null && $existing->get('cTotalOrders') !== $shopifyOrders) {
                        $existing->set('cTotalOrders', $shopifyOrders);
                        $updated = true;
                    }

                    // Total Spent
                    $shopifyTotalSpent = isset($shopifyCust['total_spent'])
                        ? (float) $shopifyCust['total_spent']
                        : null;

                    if ($shopifyTotalSpent !== null && (float) $existing->get('cTotalSpent') !== $shopifyTotalSpent) {
                        $existing->set('cTotalSpent', $shopifyTotalSpent);
                        $updated = true;
                    }

                    if ($updated) {

                        $this->entityManager->saveEntity($existing, ['silent' => true]);

                        $this->log->info(
                            "Updated Shopify totals for contact: $email " .
                            "(orders={$shopifyOrders}, spent={$shopifyTotalSpent})"
                        );

                    } else {

                        $this->log->info("No Shopify total changes for contact: $email");

                    }

                    continue;
                }


                $contact = $this->entityManager->getEntity('Contact');

                $contact->set([
                    'emailAddress' => $email,
                    'firstName'    => $firstName,
                    'lastName'     => $lastName,
                    'name'         => trim($firstName . ' ' . $lastName),
                    'phoneNumber'  => $shopifyCust['phone'] ?? null,
                ]);

                $this->entityManager->saveEntity($contact, ['silent' => true]);

                $created++;

                $this->log->info("Created new contact: $email");
            }

            $this->log->info("Processed $count contacts, created $created new");


            // Advance bookmark only if new data was processed
            if (!empty($maxUpdatedAt) && $maxUpdatedAt > $lastSync) {

                $newState = ['last_customer_sync' => $maxUpdatedAt];

                if (file_put_contents($stateFile, json_encode($newState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {

                    $this->log->info("Bookmark advanced to: $maxUpdatedAt");

                } else {

                    $this->log->error("Failed to write new bookmark to $stateFile");

                }

            } else {

                $this->log->info("No new data; bookmark unchanged at: $lastSync");

            }

        } catch (Exception $e) {

            $this->log->error("ShopifySync failed: " . $e->getMessage());

        }
    }
}
