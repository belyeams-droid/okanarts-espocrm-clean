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
            $tourId    = $deposit->get('tourId');
            $tourCode  = $deposit->get('tourCode');

            if (!$contactId) {
                continue;
            }

            $booking = null;

            // -----------------------------------------
            // 1. PRIMARY: Match by contactId + tourCode
            // -----------------------------------------
            if ($tourCode) {

                $list = $this->entityManager
                    ->getRepository('CBooking')
                    ->where([
                        'contactId' => $contactId,
                        'tourCode'  => $tourCode
                    ])
                    ->order('createdAt', 'DESC')
                    ->find();

                if ($list && count($list) > 0) {
                    $booking = $list[0];
                }
            }

            // -----------------------------------------
            // 2. FALLBACK: Legacy match (contactId + toursId)
            // -----------------------------------------
            if (!$booking && $tourId) {

                $list = $this->entityManager
                    ->getRepository('CBooking')
                    ->where([
                        'contactId' => $contactId,
                        'toursId'   => $tourId
                    ])
                    ->order('createdAt', 'DESC')
                    ->find();

                if ($list && count($list) > 0) {
                    $booking = $list[0];
                }
            }

            // -----------------------------------------
            // 3. LINK IF FOUND
            // -----------------------------------------
            if ($booking) {

                $deposit->set('bookingId', $booking->getId());

                $this->entityManager->saveEntity($deposit);

                $this->log->warning(
                    "Linked deposit {$deposit->getId()} → booking {$booking->getId()}"
                );
            }
        }

        $this->log->warning('DepositBookingBackfill finished');
    }
}
