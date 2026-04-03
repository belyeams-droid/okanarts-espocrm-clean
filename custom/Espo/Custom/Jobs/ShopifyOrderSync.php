<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Exception;

class ShopifyOrderSync implements JobDataLess
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
        try {

            $shopifyStore = $this->config->get('shopifyStore');
            $shopifyApiKey = $this->config->get('shopifyApiKey');
            $apiVersion = $this->config->get('shopifyApiVersion');

            $settings = $this->entityManager
                ->getRepository('CSystemSettings')
                ->findOne();

            $lastSync = null;

            if ($settings) {
                $lastSync = $settings->get('lastShopifySync');
            }

            $params = "limit=50&status=any";

            if ($lastSync) {
                $params .= "&created_at_min=" . urlencode($lastSync);
            }

            $nextUrl = "https://$shopifyStore/admin/api/$apiVersion/orders.json?$params";

            $headers = [
                'X-Shopify-Access-Token: ' . $shopifyApiKey,
                'Content-Type: application/json'
            ];

            while ($nextUrl) {

                $ch = curl_init($nextUrl);

                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_HEADER, true);

                $response = curl_exec($ch);

                if ($response === false) {
                    $this->log->error('ShopifyOrderSync: CURL failed.');
                    return;
                }

                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $header = substr($response, 0, $headerSize);
                $body = substr($response, $headerSize);

                curl_close($ch);

                $data = json_decode($body, true);

                if (!isset($data['orders'])) {
                    $this->log->warning('ShopifyOrderSync: No orders returned.');
                    return;
                }

                foreach ($data['orders'] as $order) {

                    $orderId = $order['id'] ?? null;
                    $email   = $order['email'] ?? null;

                    if (!$orderId) {
                        continue;
                    }

                    foreach ($order['line_items'] as $item) {

                        $title = trim($item['name'] ?? ($item['title'] ?? ''));
                        $sku   = $item['sku'] ?? '';
                        $price = isset($item['price']) ? (float)$item['price'] : 0;
                        $quantity = isset($item['quantity']) ? (int)$item['quantity'] : 1;
                        $lineItemId = $item['id'] ?? null;

                        if (!$title || stripos($title, 'deposit') === false) {
                            continue;
                        }

                        for ($i = 0; $i < $quantity; $i++) {

                            $sequence = $i + 1;
                            $uniqueLineId = $lineItemId . '-' . $sequence;

                            $deposit = $this->entityManager
                                ->getRepository('CShopifyTourDeposit')
                                ->where(['shopifyLineItemId' => $uniqueLineId])
                                ->findOne();

                            if (!$deposit) {
                                $deposit = $this->entityManager->getEntity('CShopifyTourDeposit');
                            }

                            $contactId = null;
                            $tour = null;
                            $tourId = null;
                            $tourCode = null;

                            if ($email) {

                                $pdo = $this->entityManager->getPDO();

                                $stmt = $pdo->prepare("
                                    SELECT ea.id
                                    FROM email_address ea
                                    WHERE LOWER(ea.name) = :email
                                    LIMIT 1
                                ");
                                $stmt->execute(['email' => strtolower(trim($email))]);
                                $emailRow = $stmt->fetch();

                                if ($emailRow) {
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

                                    if ($entityRow) {
                                        $contactId = $entityRow['entity_id'];
                                    }
                                }
                            }

                            if ($contactId) {
                                $tour = $this->entityManager
                                    ->getRepository('CTours')
                                    ->where(['contactId' => $contactId])
                                    ->order('createdAt', 'DESC')
                                    ->findOne();

                                if ($tour) {
                                    $tourId = $tour->getId();
                                    $tourCode = $tour->get('tourCode');

                                    if ($tourCode) {
                                        $tourCode = str_replace('-', '_', trim($tourCode));
                                    }
                                }
                            }

                            if (
                                !$tourCode &&
                                $sku &&
                                preg_match('/^[A-Z]_[A-Z]+_[A-Z0-9]+$/', $sku)
                            ) {
                                $tourCode = strtoupper(trim($sku));
                            }

                            $deposit->set([
                                'name' => 'DEPOSIT — ' . $title,
                                'productTitle' => $title,
                                'shopifySku' => $sku,
                                'amount' => $price,
                                'shopifyOrderId' => $orderId,
                                'shopifyLineItemId' => $uniqueLineId,
                                'shopifyEmail' => $email,
                                'contractStatus' => 'Deposit Received',
                                'contactId' => $contactId,
                                'tourId' => $tourId,
                                'tourCode' => $tourCode
                            ]);

                            $this->entityManager->saveEntity($deposit);

                            // 🔥 FIXED DIRTY FLAG TRIGGER
                            if ($contactId) {
                                $contact = $this->entityManager
                                    ->getRepository('Contact')
                                    ->where(['id' => $contactId])
                                    ->findOne();

                                if ($contact && !$contact->get('needsNarrativeRebuild')) {
                                    $contact->set('needsNarrativeRebuild', true);
                                    $this->entityManager->saveEntity($contact, ['silent' => true]);
                                }
                            }
                        }
                    }
                }

                $nextUrl = null;
            }

        } catch (Exception $e) {
            $this->log->error('ShopifyOrderSync failed: ' . $e->getMessage());
        }
    }
}
