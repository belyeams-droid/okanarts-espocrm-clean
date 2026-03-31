<?php

namespace Espo\Custom\Services;

use Espo\ORM\EntityManager;

class ContactResolver
{
    private EntityManager $em;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    public function resolve(?string $email, ?string $shopifyId = null)
    {
        $repo = $this->em->getRepository('Contact');

        // 1. Shopify ID (strongest key)
        if ($shopifyId) {
            $contact = $repo->where(['cShopifyId' => $shopifyId])->findOne();
            if ($contact) return $contact;
        }

        // 2. Email (normalized)
        if ($email) {
            $email = strtolower(trim($email));
            $contact = $repo->where(['emailAddress' => $email])->findOne();
            if ($contact) return $contact;
        }

        // 3. Create new
        return $this->em->getNewEntity('Contact');
    }
}
