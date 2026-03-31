<?php
/**
 * JotFormSync Job - Pulls new submissions from JotForm and syncs to CBooking entity
 * Path: custom/Espo/Custom/Jobs/JotFormSync.php
 */

namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Exception;

class JotformEspoSync implements JobDataLess
{
    private $entityManager;
    private $log;

    public function __construct(EntityManager $entityManager, Log $log)
    {
        $this->entityManager = $entityManager;
        $this->log = $log;
    }

    public function run(): void
    {
        $this->log->info("JotFormSync: Starting submission pull");

        try {
            // === CONFIGURATION ===
            $apiKey   = '799f4be8320e6bf289704e3d6fcf4eff';  // Your API key
            $formId   = '260148371232046';                    // Your form ID

            $stateFile = '/var/www/html/data/jotform_sync_state.json';

            // Load last sync timestamp (fallback to far past)
            $lastSync = '1970-01-01T00:00:00';
            if (file_exists($stateFile)) {
                $state = json_decode(file_get_contents($stateFile), true);
                if (isset($state['last_sync'])) {
                    $lastSync = $state['last_sync'];
                }
            }
            $this->log->info("Using last sync: $lastSync");

            // Properly URL-encode the filter parameter
            $filterValue = urlencode("created_at_gt:$lastSync");
            $url = "https://api.jotform.com/form/$formId/submissions"
                 . "?apiKey=$apiKey"
                 . "&filter=$filterValue"
                 . "&sort=created_at"
                 . "&order=asc"
                 . "&limit=100";

            $this->log->info("Attempting API call to: $url");

            // Curl setup with timeouts for Starlink latency
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_VERBOSE, true);

            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $verbose);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            rewind($verbose);
            $verboseLog = stream_get_contents($verbose);
            fclose($verbose);
            $this->log->info("Curl verbose output: " . $verboseLog);

            curl_close($ch);

            if ($response === false) {
                $this->log->error("Curl failed: " . $curlError);
                throw new Exception("Curl error: " . $curlError);
            }

            if ($httpCode !== 200) {
                $this->log->error("JotForm API error: HTTP $httpCode - Response: $response");
                throw new Exception("JotForm API error: HTTP $httpCode");
            }

            $data = json_decode($response, true);
            if (!isset($data['content'])) {
                $this->log->info("No new submissions found");
                return;
            }

            $submissions = $data['content'];
            $count = count($submissions);
            $this->log->info("Fetched $count new submissions");

            $created = 0;
            $maxCreatedAt = $lastSync;

            foreach ($submissions as $submission) {
                $createdAt = $submission['created_at'];
                $answers = $submission['answers'];

                // === MAPPED FIELDS ===
                $email                     = $answers['158']['answer'] ?? null;                 // Email (new textbox)
                $fullName                  = $answers['24']['answer']['prettyFormat'] ?? null; // Full Name (control_fullname)
                $preferredName             = $answers['27']['answer'] ?? null;                 // Preferred Name
                $cellPhone                 = $answers['28']['answer']['prettyFormat'] ?? null; // Cell Phone While Traveling
                $passportFullName          = $answers['33']['answer'] ?? null;                 // Full Name on Passport
                $passportNumber            = $answers['35']['answer'] ?? null;                 // Passport Number
                $passportExpirationRaw     = $answers['41']['answer']['prettyFormat'] ?? null; // Passport Expiration Date
                $passportCountry           = $answers['42']['answer'] ?? null;                 // Passport Country of Issue

                $emergencyName1            = $answers['72']['answer'] ?? null;                 // Emergency Contact 1 Name
                $emergencyRel1             = $answers['76']['answer'] ?? null;                 // Relationship 1
                $emergencyEmail1           = $answers['77']['answer'] ?? null;                 // Emergency Email 1
                $emergencyPhone1           = $answers['78']['answer'] ?? null;                 // Emergency Phone 1

                $emergencyName2            = $answers['81']['answer'] ?? null;                 // Emergency Contact 2 Name
                $emergencyRel2             = $answers['82']['answer'] ?? null;                 // Relationship 2
                $emergencyEmail2           = $answers['83']['answer'] ?? null;                 // Emergency Email 2
                $emergencyPhone2           = $answers['84']['answer'] ?? null;                 // Emergency Phone 2

                if (empty($email)) {
                    $this->log->info("Skipping submission without email");
                    continue;
                }

                // Format passport expiration (MM-DD-YYYY → YYYY-MM-DD)
                $passportExpiration = null;
                if ($passportExpirationRaw) {
                    $passportExpiration = date('Y-m-d', strtotime($passportExpirationRaw));
                }

                // Find or create CBooking
                $existing = $this->entityManager->getRepository('CBooking')
                    ->where(['emailAddress' => $email])
                    ->findOne();

                $entity = $existing ?? $this->entityManager->getEntity('CBooking');

                $entity->set([
                    'fullNameonPassport'              => $passportFullName,
                    'passportNumber'                  => $passportNumber,
                    'passportCountry'                 => $passportCountry,
                    'passportExpirationDate'          => $passportExpiration,
                    'firstEmergencyContactName'       => $emergencyName1,
                    'firstEmergencyContactRelationship' => $emergencyRel1,
                    'firstEmergencyContactEmail'      => $emergencyEmail1,
                    'firstEmergencyContactPhone'      => $emergencyPhone1,
                    'secondEmergencyContactName'      => $emergencyName2,
                    'secondEmergencyContactRelationship' => $emergencyRel2,
                    'secondEmergencyContactEmail'     => $emergencyEmail2,
                    'secondEmergencyContactPhone'     => $emergencyPhone2,
                ]);

                // SPECIAL EMAIL SETTER - CRITICAL FOR EMAIL TO SAVE
                if (!empty($email)) {
                    $entity->set('emailAddress', $email);
                }

                $this->entityManager->saveEntity($entity, ['silent' => true]);

                if ($existing) {
                    $this->log->info("Updated existing CBooking for: $email");
                } else {
                    $created++;
                    $this->log->info("Created new CBooking for: $email");
                }

                if ($createdAt > $maxCreatedAt) {
                    $maxCreatedAt = $createdAt;
                }
            }

            $this->log->info("Processed $count submissions, created $created new");

            // Advance bookmark
            if ($maxCreatedAt > $lastSync) {
                file_put_contents($stateFile, json_encode(['last_sync' => $maxCreatedAt], JSON_PRETTY_PRINT));
                $this->log->info("Bookmark advanced to: $maxCreatedAt");
            } else {
                $this->log->info("No new data; bookmark unchanged");
            }

        } catch (Exception $e) {
            $this->log->error("JotFormSync failed: " . $e->getMessage());
        }
    }
}
