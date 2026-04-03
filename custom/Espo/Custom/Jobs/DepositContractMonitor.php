<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;

class DepositContractMonitor implements JobDataLess
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
        $repo = $this->entityManager->getRepository('CShopifyTourDeposit');

        // 🔥 ONLY RELEVANT RECORDS
        $deposits = $repo->where([
            'deleted' => false,
            'contractStatus!=' => 'Booking Created'
        ])->find();

        foreach ($deposits as $deposit) {

            $status = $deposit->get('contractStatus');
            $bookingId = $deposit->get('bookingId');
            $sentAt = $deposit->get('contractSentAt');

            /*
            BOOKING CREATED
            */

            if ($bookingId && $status !== 'Booking Created') {

                $deposit->set('contractStatus', 'Booking Created');

                $this->entityManager->saveEntity($deposit);

                $this->log->warning("Deposit {$deposit->getId()} marked Booking Created");

                continue;
            }

            /*
            CONTRACT SENT
            */

            if ($status === 'Deposit Received' && $sentAt) {

                $deposit->set('contractStatus', 'Contract Sent');

                $this->entityManager->saveEntity($deposit);

                $this->log->warning("Deposit {$deposit->getId()} marked Contract Sent");

                continue;
            }

            /*
            CUSTOMER REQUIRES ATTENTION
            */

            if ($status === 'Contract Sent' && $sentAt && !$bookingId) {

                $sentTime = strtotime($sentAt);

                if ((time() - $sentTime) > (5 * 86400)) {

                    $deposit->set('contractStatus', 'Customer Requires Attention');

                    $this->entityManager->saveEntity($deposit);

                    $this->log->warning("Deposit {$deposit->getId()} requires attention");
                }
            }
        }
    }
}
