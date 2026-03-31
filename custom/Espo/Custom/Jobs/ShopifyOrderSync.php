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

            /*
            ------------------------------------------------
            LOAD SHOPIFY CONFIG
            ------------------------------------------------
            */

            $shopifyStore = $this->config->get('shopifyStore');
            $shopifyApiKey = $this->config->get('shopifyApiKey');
            $apiVersion = $this->config->get('shopifyApiVersion');

            /*
            ------------------------------------------------
            LOAD LAST SYNC CHECKPOINT
            ------------------------------------------------
            */

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

            $newestOrderTime = $lastSync;

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

                $this->log->warning('ShopifyOrderSync: orders received = ' . count($data['orders']));

                foreach ($data['orders'] as $order) {

                    $orderId = $order['id'] ?? null;
                    $email   = $order['email'] ?? null;

                    if (!$orderId) {
                        continue;
                    }

                    /*
                    TRACK NEWEST ORDER
                    */

                    $orderCreated = $order['created_at'] ?? null;

                    if ($orderCreated && (!$newestOrderTime || $orderCreated > $newestOrderTime)) {
                        $newestOrderTime = $orderCreated;
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

                        $this->log->warning("Deposit detected: $title x$quantity");

                        $createdAt = $order['created_at'] ?? null;
                        $orderDate = null;

                        if ($createdAt) {
                            try {
                                $dt = new \DateTime($createdAt);
                                $orderDate = $dt->format('Y-m-d H:i:s');
                            } catch (\Exception $e) {}
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

                            $depositName = 'DEPOSIT — ' . $title;

                            $deposit->set([
                                'name' => $depositName,
                                'productTitle' => $title,
                                'shopifySku' => $sku,
                                'amount' => $price,
                                'shopifyOrderId' => $orderId,
                                'shopifyLineItemId' => $uniqueLineId,
                                'orderDate' => $orderDate,
                                'shopifyEmail' => $email,
                                'contractStatus' => 'Deposit Received'
                            ]);

                            $this->entityManager->saveEntity($deposit);
                        }
                    }
                }

                $nextUrl = null;

                if (preg_match('/<([^>]+)>; rel="next"/', $header, $matches)) {
                    $nextUrl = $matches[1];
                    $this->log->warning('ShopifyOrderSync: fetching next page');
                }
            }

            /*
            ------------------------------------------------
            SAVE NEW SYNC CHECKPOINT
            ------------------------------------------------
            */

            if ($newestOrderTime) {

                if (!$settings) {
                    $settings = $this->entityManager->getEntity('CSystemSettings');
                }

                /*
                Convert Shopify ISO datetime to MySQL DATETIME
                */

                try {
                    $dt = new \DateTime($newestOrderTime);
                    $mysqlTime = $dt->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    $mysqlTime = $newestOrderTime;
                }

                $settings->set('lastShopifySync', $mysqlTime);

                $this->entityManager->saveEntity($settings);
            }

        } catch (Exception $e) {

            $this->log->error('ShopifyOrderSync failed: ' . $e->getMessage());
        }
    }
}
