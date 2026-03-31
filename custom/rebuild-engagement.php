<?php

require __DIR__ . '/../bootstrap.php';



$app = new \Espo\Core\Application();
$container = $app->getContainer();

$entityManager = $container->get('entityManager');

$relationshipNarrative = new \Espo\Custom\Services\RelationshipNarrative($entityManager);

$orchestrator = new \Espo\Custom\Services\EngagementOrchestrator(
    $entityManager,
    $relationshipNarrative
);

$contacts = $entityManager
    ->getRepository('Contact')
    ->find();

echo "Processing " . count($contacts) . " contacts...\n";

foreach ($contacts as $contact) {

    $orchestrator->syncForContact($contact->getId());

}

echo "Engagement rebuild complete\n";
