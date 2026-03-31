<?php

define('ENTRY_POINT', 'cli');

require 'bootstrap.php';

use Espo\Core\Application;
use Espo\Custom\Services\RelationshipNarrative;

echo "Starting narrative rebuild...\n";

/*
----------------------------------------
Initialize Espo Application
----------------------------------------
*/

$app = new Application();
$app->setupSystemUser();

$container = $app->getContainer();

/*
----------------------------------------
Get EntityManager
----------------------------------------
*/

$em = $container->get('entityManager');

/*
----------------------------------------
Create Services (manual for CLI)
----------------------------------------
*/

$narrativeService = new RelationshipNarrative($em);

/*
----------------------------------------
Fetch Contact IDs
----------------------------------------
*/

$pdo = $em->getPDO();

$stmt = $pdo->query("
    SELECT id
    FROM contact
    WHERE deleted = 0
");

$count = 0;

/*
----------------------------------------
Process Contacts
----------------------------------------
*/

while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

    try {

        $id = $row['id'];

        $narrativeService->generateForContact($id);

        echo "Updated $id\n";

        $count++;

        if ($count % 500 === 0) {

            echo "Processed $count contacts\n";

            /*
            Free memory during large runs
            */
            $em->clear();
        }

    } catch (\Throwable $e) {

        echo "Error processing contact {$row['id']} : "
            . $e->getMessage() . "\n";
    }
}

echo "Finished rebuilding narratives for $count contacts\n";
