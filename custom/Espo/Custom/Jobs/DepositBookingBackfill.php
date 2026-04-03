<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;

class DepositBookingBackfill implements JobDataLess
{
    private $entityManager;
    private $log;

    private string $stateFile = 'data/deposit_booking_backfill.state';

    public function __construct(EntityManager $entityManager, Log $log)
    {
        $this->entityManager = $entityManager;
        $this->log = $log;
    }

    public function run(): void
    {
        $this->log->warning('DepositBookingBackfill started');

        $pdo = $this->entityManager->getPDO();
        $limit = 50;

        $lastId = null;

        if (file_exists($this->stateFile)) {
            $lastId = trim(file_get_contents($this->stateFile)) ?: null;
            $this->log->warning("Resuming from ID: {$lastId}");
        }

        if ($lastId) {
            $stmt = $pdo->prepare("
                SELECT id
                FROM c_shopify_tour_deposit
                WHERE booking_id IS NULL
                AND deleted = 0
                AND id > :lastId
                ORDER BY id
                LIMIT {$limit}
            ");
            $stmt->execute(['lastId' => $lastId]);
        } else {
            $stmt = $pdo->query("
                SELECT id
                FROM c_shopify_tour_deposit
                WHERE booking_id IS NULL
                AND deleted = 0
                ORDER BY id
                LIMIT {$limit}
            ");
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $count = count($rows);

        $this->log->warning("Fetched: {$count}");

        if (!$count) {
            $this->log->warning('All deposits processed. Resetting state.');
            @unlink($this->stateFile);
            return;
        }

        foreach ($rows as $row) {

            $depositId = $row['id'];

            $deposit = $this->entityManager
                ->getRepository('CShopifyTourDeposit')
                ->where(['id' => $depositId])
                ->findOne();

            if (!$deposit) {
                continue;
            }

            $contactId = $deposit->get('contactId');
            $tourId    = $deposit->get('tourId');
            $tourCode  = $deposit->get('tourCode');

            if (!$contactId) {
                continue;
            }

            $booking = null;

            if ($tourCode) {

                $list = $this->entityManager
                    ->getRepository('CBooking')
                    ->where([
                        'contactId' => $contactId,
                        'tourCode'  => $tourCode
                    ])
                    ->order('createdAt', 'DESC')
                    ->findOne();

                if ($list) {
                    $booking = $list;
                }
            }

            if (!$booking && $tourId) {

                $list = $this->entityManager
                    ->getRepository('CBooking')
                    ->where([
                        'contactId' => $contactId,
                        'toursId'   => $tourId
                    ])
                    ->order('createdAt', 'DESC')
                    ->findOne();

                if ($list) {
                    $booking = $list;
                }
            }

            // -----------------------------------------
            // LINK + DIRTY FLAG
            // -----------------------------------------
            if ($booking) {

                $deposit->set('bookingId', $booking->getId());
                $this->entityManager->saveEntity($deposit);

                $this->log->warning(
                    "Linked deposit {$depositId} → booking {$booking->getId()}"
                );

                // 🔥 DIRTY FLAG TRIGGER (ONLY WHEN LINK CREATED)
                $contact = $this->entityManager
                    ->getRepository('Contact')
                    ->where(['id' => $contactId])
                    ->findOne();

                if ($contact && !$contact->get('needsNarrativeRebuild')) {
                    $contact->set('needsNarrativeRebuild', true);
                    $this->entityManager->saveEntity($contact, ['silent' => true]);
                }
            }

            file_put_contents($this->stateFile, $depositId);
        }

        $this->log->warning('DepositBookingBackfill batch complete');

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
}
