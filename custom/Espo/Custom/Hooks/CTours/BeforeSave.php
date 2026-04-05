<?php

namespace Espo\Custom\Hooks\CTours;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class BeforeSave
{
    protected $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function beforeSave(Entity $entity, array $options = [])
    {
        $tourId = $entity->getId();

        if (!$tourId) {
            return;
        }

        $depositCount = $this->entityManager
            ->getRepository('CShopifyTourDeposit')
            ->where([
                'tourId' => $tourId,
                'deleted' => false,
                'contractStatus!=' => 'Cancelled'
            ])
            ->count();

        $entity->set('depositCount', $depositCount);
    }
}
