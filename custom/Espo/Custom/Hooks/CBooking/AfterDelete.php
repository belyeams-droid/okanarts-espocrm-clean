<?php

namespace Espo\Custom\Hooks\CBooking;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class AfterDelete
{
    protected EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function afterDelete(Entity $entity, array $options = [])
    {
        $tourId = $entity->get('tourId');

        if (!$tourId) {
            return;
        }

        $bookingRepo = $this->entityManager->getRepository('CBooking');

        $count = $bookingRepo->where([
            'tourId' => $tourId
        ])->count();

        $tour = $this->entityManager->getEntityById('CTours', $tourId);

        if ($tour) {
            $tour->set('bookedPlaces', $count);
            $tour->set('availablePlaces', $tour->get('tourCapacity') - $count);
            $this->entityManager->saveEntity($tour);
        }
    }
}
