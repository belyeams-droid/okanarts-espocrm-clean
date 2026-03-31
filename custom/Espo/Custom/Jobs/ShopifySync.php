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
        $customers = $this->getShopifyCustomers();

        foreach ($customers as $customer) {

            $isNew = false;
            $contact = $this->resolveContact($customer, $isNew);

            if (!empty($customer['id'])) {
                $contact->set('cShopifyId', (string) $customer['id']);
            }

            if (!empty($customer['email'])) {
                $email = strtolower(trim($customer['email']));
                $contact->set('emailAddress', $email);
            }

            if (!empty($customer['first_name'])) {
                $contact->set('firstName', $customer['first_name']);
            }

            if (!empty($customer['last_name'])) {
                $contact->set('lastName', $customer['last_name']);
            }

            if (!empty($customer['phone'])) {
                $contact->set('phoneNumber', $customer['phone']);
            }

            $this->entityManager->saveEntity($contact, ['silent' => true]);
        }
    }

    private function resolveContact(array $customer, &$isNew = false)
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
        $isNew = true;

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
        // Replace with real Shopify API logic
        return [];
    }
}
