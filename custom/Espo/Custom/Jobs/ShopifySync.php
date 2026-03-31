<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\ORM\EntityManager;

class ShopifySync extends Base
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function run(): void
    {
        // Example: replace with your actual Shopify fetch logic
        $customers = $this->getShopifyCustomers();

        foreach ($customers as $customer) {
            $contact = $this->resolveContact($customer);

            // Always enforce Shopify ID if present
            if (!empty($customer['id'])) {
                $contact->set('cShopifyId', (string) $customer['id']);
            }

            // Normalize email
            if (!empty($customer['email'])) {
                $email = strtolower(trim($customer['email']));
                $contact->set('emailAddress', $email);
            }

            // Map fields
            if (!empty($customer['first_name'])) {
                $contact->set('firstName', $customer['first_name']);
            }

            if (!empty($customer['last_name'])) {
                $contact->set('lastName', $customer['last_name']);
            }

            if (!empty($customer['phone'])) {
                $contact->set('phoneNumber', $customer['phone']);
            }

            // Save safely (idempotent)
            $this->entityManager->saveEntity($contact);
        }
    }

    private function resolveContact(array $customer)
    {
        $contactRepository = $this->entityManager->getRDBRepository('Contact');

        $shopifyId = $customer['id'] ?? null;

        $email = isset($customer['email'])
            ? strtolower(trim($customer['email']))
            : null;

        // 1. Lookup by Shopify ID
        if ($shopifyId) {
            $contact = $contactRepository
                ->where(['cShopifyId' => (string) $shopifyId])
                ->findOne();

            if ($contact) {
                return $contact;
            }
        }

        // 2. Fallback: lookup by email
        if ($email) {
            $contact = $contactRepository
                ->where(['emailAddress' => $email])
                ->findOne();

            if ($contact) {
                return $contact;
            }
        }

        // 3. Create ONLY if no match
        $contact = $this->entityManager->getEntity('Contact');

        if ($shopifyId) {
            $contact->set('cShopifyId', (string) $shopifyId);
        }

        if ($email) {
            $contact->set('emailAddress', $email);
        }

        return $contact;
    }

    private function getShopifyCustomers(): array
    {
        // Placeholder — replace with your actual API logic
        return [];
    }
}
