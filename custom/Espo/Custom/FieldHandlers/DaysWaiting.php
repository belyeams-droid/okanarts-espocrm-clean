<?php

namespace Espo\Custom\FieldHandlers;

use Espo\ORM\Entity;
use Espo\Core\Field\FieldHandler;

class DaysWaiting extends FieldHandler
{
    public function process(Entity $entity)
    {
        $orderDate = $entity->get('orderDate');

        if (!$orderDate) {
            return null;
        }

        $order = new \DateTime($orderDate);
        $today = new \DateTime();

        return $today->diff($order)->days;
    }
}
