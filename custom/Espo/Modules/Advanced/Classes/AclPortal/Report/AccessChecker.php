<?php

namespace Espo\Modules\Advanced\Classes\AclPortal\Report;

use Espo\Core\Acl\AccessEntityCREDChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Portal\Acl\DefaultAccessChecker;
use Espo\Core\Portal\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\ORM\Entity;

/**
 * @implements AccessEntityCREDChecker<Report>
 */
class AccessChecker implements AccessEntityCREDChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(DefaultAccessChecker $defaultAccessChecker)
    {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    /**
     * @param Report $entity
     */
    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        if (!$this->defaultAccessChecker->checkEntityRead($user, $entity, $data)) {
            return false;
        }

        if (!$user->getPortalId()) {
            $portalIdList = $user->getLinkMultipleIdList('portals');

            return count(
                array_intersect($portalIdList, $entity->getPortals()->getIdList())
            ) > 0;
        }

        return in_array($user->getPortalId(), $entity->getPortals()->getIdList());
    }
}
