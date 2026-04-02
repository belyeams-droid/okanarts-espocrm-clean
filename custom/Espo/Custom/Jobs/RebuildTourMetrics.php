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

            $tourId = $tour->getId();
            $tourCode = $tour->get('tourCode');

            if (!$tourId) {
                continue;
            }

            // -----------------------------------------
            // NORMALIZE (hyphen → underscore)
            // (still used for bookings only)
            // -----------------------------------------
            $normalizedCode = $tourCode
                ? str_replace('-', '_', trim($tourCode))
                : null;

            $pdo = $this->em->getPDO();

            // -----------------------------
            // Deposits (FIXED: use tour_id)
            // -----------------------------
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM c_shopify_tour_deposit
                WHERE tour_id = :tourId
                AND deleted = 0
            ");
            $stmt->execute(['tourId' => $tourId]);
            $deposits = (int) $stmt->fetchColumn();

            // -----------------------------
            // Bookings (still using code)
            // -----------------------------
            $bookings = 0;
            $acceptedBookings = 0;

            if ($normalizedCode) {

                // All bookings
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM c_booking
                    WHERE REPLACE(tour_code, '-', '_') = :code
                    AND deleted = 0
                ");
                $stmt->execute(['code' => $normalizedCode]);
                $bookings = (int) $stmt->fetchColumn();

                // -----------------------------
                // Accepted bookings (FIXED)
                // -----------------------------
                $stmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM c_booking
                    WHERE REPLACE(tour_code, '-', '_') = :code
                    AND contract_lifecycle_state = 'Accepted'
                    AND deleted = 0
                ");
                $stmt->execute(['code' => $normalizedCode]);
                $acceptedBookings = (int) $stmt->fetchColumn();
            }

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
                'bookingAccepted' => $acceptedBookings,
                'contractsOutstanding' => $outstanding
            ]);

            $this->em->saveEntity($tour, ['silent' => true]);
        }
    }
}
