<?php

namespace Espo\Modules\Advanced\Classes\AssignmentNotificators;

use Espo\Core\Notification\AssignmentNotificator;
use Espo\Core\Notification\AssignmentNotificator\Params;
use Espo\Entities\Notification;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\BpmnUserTask as BpmnUserTaskEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * @implements AssignmentNotificator<BpmnUserTaskEntity>
 */
class BpmnUserTask implements AssignmentNotificator
{
    private EntityManager $entityManager;
    private User $user;

    public function __construct(EntityManager $entityManager, User $user)
    {
        $this->entityManager = $entityManager;
        $this->user = $user;
    }

    public function process(Entity $entity, Params $params): void
    {
        if (!$entity->get('assignedUserId')) {
            return;
        }

        if (!$entity->isAttributeChanged('assignedUserId')) {
            return;
        }

        $assignedUserId = $entity->get('assignedUserId');

        $isNotSelfAssignment = $entity->isNew() ?
            $assignedUserId !== $entity->get('createdById') :
            $assignedUserId !== $entity->get('modifiedById');

        if (!$isNotSelfAssignment) {
            return;
        }

        $notification = $this->entityManager->getNewEntity(Notification::ENTITY_TYPE);

        $notification->set([
            'type' => Notification::TYPE_ASSIGN,
            'userId' => $assignedUserId,
            'data' => [
                'entityType' => $entity->getEntityType(),
                'entityId' => $entity->getId(),
                'entityName' => $entity->get('name'),
                'isNew' => $entity->isNew(),
                'userId' => $this->user->getId(),
                'userName' => $this->user->get('name')
            ],
        ]);

        $this->entityManager->saveEntity($notification);
    }
}
