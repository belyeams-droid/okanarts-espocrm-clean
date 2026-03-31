<?php

namespace Espo\Modules\Advanced\Classes\Acl\BpmnProcess;

use Espo\Core\Acl\DefaultOwnershipChecker;
use Espo\Core\Acl\OwnershipOwnChecker;
use Espo\Core\Acl\OwnershipTeamChecker;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\BpmnProcess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * @implements OwnershipOwnChecker<BpmnProcess>
 * @implements OwnershipTeamChecker<BpmnProcess>
 */
class OwnershipChecker implements OwnershipOwnChecker, OwnershipTeamChecker
{
    private DefaultOwnershipChecker $defaultOwnershipChecker;
    private EntityManager $entityManager;

    public function __construct(
        DefaultOwnershipChecker $defaultOwnershipChecker,
        EntityManager $entityManager
    ) {
        $this->defaultOwnershipChecker = $defaultOwnershipChecker;
        $this->entityManager = $entityManager;
    }

    /**
     * @param BpmnProcess $entity
     */
    public function checkOwn(User $user, Entity $entity): bool
    {
        if (!$entity->getParentProcessId() || $entity->getParentProcessId() === $entity->getId()) {
            return $this->defaultOwnershipChecker->checkOwn($user, $entity);
        }

        $parent = $this->entityManager->getEntityById(BpmnProcess::ENTITY_TYPE, $entity->getParentProcessId());

        if (!$parent instanceof CoreEntity) {
            return false;
        }

        return $this->defaultOwnershipChecker->checkOwn($user, $parent);
    }

    /**
     * @param BpmnProcess $entity
     */
    public function checkTeam(User $user, Entity $entity): bool
    {
        if (!$entity->getParentProcessId() || $entity->getParentProcessId() === $entity->getId()) {
            return $this->defaultOwnershipChecker->checkTeam($user, $entity);
        }

        $parent = $this->entityManager->getEntityById(BpmnProcess::ENTITY_TYPE, $entity->getParentProcessId());

        if (!$parent instanceof CoreEntity) {
            return false;
        }

        return $this->defaultOwnershipChecker->checkTeam($user, $parent);
    }
}
