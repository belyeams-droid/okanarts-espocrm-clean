<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;

class DepositBookingBackfill implements JobDataLess
{
    private $entityManager;
    private $log;

    public function __construct(EntityManager $entityManager, Log $log)
    {
        $this->entityManager = $entityManager;
        $this->log = $log;
    }

    public function run(): void
    {
        $this->log->warning('DepositBookingBackfill started');

        $deposits = $this->entityManager
            ->getRepository('CShopifyTourDeposit')
            ->where([
                'bookingId' => null
            ])
            ->find();

        foreach ($deposits as $deposit) {

            $contactId = $deposit->get('contactId');
            $tourId = $deposit->get('tourId');

            if (!$contactId || !$tourId) {
                continue;
            }

            $booking = $this->entityManager
                ->getRepository('CBooking')
                ->where([
                    'contactId' => $contactId,
                    'tourId' => $tourId
                ])
                ->findOne();

            if ($booking) {

                $deposit->set('bookingId', $booking->getId());

                $this->entityManager->saveEntity($deposit);

                $this->log->warning(
                    "Backfilled deposit {$deposit->getId()} → booking {$booking->getId()}"
                );
            }
        }

        $this->log->warning('DepositBookingBackfill finished');
    }
}
