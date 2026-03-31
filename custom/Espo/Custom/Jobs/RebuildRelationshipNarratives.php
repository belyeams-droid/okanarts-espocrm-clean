<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\ORM\EntityManager;
use Espo\Custom\Services\RelationshipNarrative;

class RebuildRelationshipNarratives extends Base
{
    private EntityManager $em;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    public function run(): void
    {
        $service = new RelationshipNarrative($this->em);

        $contacts = $this->em
            ->getRepository('Contact')
            ->where(['deleted' => false])
            ->find();

        foreach ($contacts as $contact) {
            $service->generateForContact($contact->getId());
        }
    }
}
