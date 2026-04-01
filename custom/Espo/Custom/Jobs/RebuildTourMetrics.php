<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;

class RebuildTourMetrics implements JobDataLess
{
    private EntityManager $em;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    public function run(): void
    {
        $tours = $this->em->getRepository('CTours')->find();

        foreach ($tours as $tour) {

            $tourCode = $tour->get('tourCode');

            if (!$tourCode) {
                continue;
            }

            // -----------------------------------------
            // NORMALIZE (hyphen → underscore)
            // -----------------------------------------
            $tourCode = str_replace('-', '_', trim($tourCode));

            $pdo = $this->em->getPDO();

            // -----------------------------
            // Deposits (TRUE deposits count)
            // -----------------------------
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM c_shopify_tour_deposit
                WHERE tour_code = :code
                AND deleted = 0
            ");
            $stmt->execute(['code' => $tourCode]);
            $deposits = (int) $stmt->fetchColumn();

            // -----------------------------
            // Bookings (normalize in SQL)
            // -----------------------------
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM c_booking
                WHERE REPLACE(tour_code, '-', '_') = :code
                AND deleted = 0
            ");
            $stmt->execute(['code' => $tourCode]);
            $bookings = (int) $stmt->fetchColumn();

            // -----------------------------
            // Capacity math
            // -----------------------------
            $capacity = (int) $tour->get('tourCapacity');
            $available = max(0, $capacity - $deposits);
            $outstanding = max(0, $deposits - $bookings);

            // -----------------------------
            // Save to YOUR fields
            // -----------------------------
            $tour->set([
                'bookedPlaces' => $deposits,
                'availablePlaces' => $available,
                'bookingCount' => $bookings,
                'contractsOutstanding' => $outstanding
            ]);

            $this->em->saveEntity($tour, ['silent' => true]);
        }
    }
}
