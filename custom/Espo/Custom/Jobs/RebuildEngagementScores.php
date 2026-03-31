<?php

namespace Espo\Custom\Jobs;

use Espo\Core\Jobs\Base;
use Espo\ORM\EntityManager;
use Espo\Custom\Services\EngagementOrchestrator;

class RebuildEngagementScores extends Base
{
    protected EntityManager $entityManager;
    protected EngagementOrchestrator $orchestrator;

    public function __construct(
        EntityManager $entityManager,
        EngagementOrchestrator $orchestrator
    ) {
        $this->entityManager = $entityManager;
        $this->orchestrator = $orchestrator;
    }

    public function run(): void
    {
        echo "Rebuilding engagement scores...\n";

        $contacts = $this->entityManager
            ->getRepository('Contact')
            ->find();

        foreach ($contacts as $contact) {

            $this->orchestrator->syncForContact($contact->getId());

        }

        echo "Engagement score rebuild complete.\n";
    }
}

