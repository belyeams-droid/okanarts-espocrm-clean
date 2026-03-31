<?php

namespace Espo\Custom\Hooks\CBooking;

use Espo\ORM\EntityManager;

class AfterSave
{
    private EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function afterSave($entity, array $options)
    {
        $contactId = $entity->get('contactId');
        $tourId = $entity->get('tourId');

        if (!$contactId || !$tourId) {
            return;
        }

        /*
        ------------------------------------------------
        FIND MATCHING DEPOSIT
        IMPROVED: only unlinked deposits, oldest first
        ------------------------------------------------
        */

        $deposit = $this->entityManager
            ->getRepository('CShopifyTourDeposit')
            ->where([
                'contactId' => $contactId,
                'tourId' => $tourId,
                'bookingId' => null
            ])
            ->order('orderDate', 'ASC')
            ->findOne();

        if ($deposit) {

            $deposit->set([
                'bookingId' => $entity->getId(),
                'contractStatus' => 'Booking Created'
            ]);

            $this->entityManager->saveEntity($deposit);
        }

        /*
        ------------------------------------------------
        UPDATE TOUR CONTRACT METRICS
        ------------------------------------------------
        */

        $outstanding = $this->entityManager
            ->getRepository('CShopifyTourDeposit')
            ->where([
                'tourId' => $tourId,
                'bookingId' => null
            ])
            ->count();

        $tour = $this->entityManager
            ->getRepository('CTours')
            ->where(['id' => $tourId])
            ->findOne();

        if ($tour) {

            $tour->set('contractsOutstanding', $outstanding);

            $this->entityManager->saveEntity($tour);
        }
    }
}
