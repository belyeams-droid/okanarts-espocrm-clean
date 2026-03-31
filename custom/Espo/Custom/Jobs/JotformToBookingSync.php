<?php
/*
ENTERPRISE STABLE VERSION
Design goal:
- deterministic processing
- low state memory
- workflow-driven notifications
- minimal branching logic
*/

// ===== ENTERPRISE VERSION TRACKING =====
// PHASE 1: STABILIZATION
// PHASE 2: UNIVERSAL FOLLOW-UP ENABLED
namespace Espo\Custom\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Config;

class JotformToBookingSync implements JobDataLess
{

    // ===== UNIVERSAL FOLLOW-UP CONFIG =====
    private const UNIVERSAL_FOLLOWUP_FORM_ID = '260444955711257';


    private $entityManager;
    private $log;
    private $fileManager;
    private $config;


/* ============================================================
   ENTERPRISE JOB LOGGER
   Cron logger only records WARNING+
   ============================================================ */
private function jobLog(string $message): void
{
    $this->log->warning('[JotformSync] ' . $message);
}


    public function __construct(
        EntityManager $entityManager,
        Log $log,
        FileManager $fileManager,
        Config $config
    ) {
        $this->entityManager = $entityManager;
        $this->log = $log;
        $this->fileManager = $fileManager;
        $this->config = $config;
    }

    /* ============================================================
       MAIN JOB
       ============================================================ */
public function run(): void
{
    $this->log->warning('[JOTFORM] STEP 1 ENTER RUN');

    $globalApiKey = $this->config->get('jotformApiKey');

    $this->log->warning('[JOTFORM] STEP 2 CONFIG LOADED');





        $startedAt = microtime(true);

        $globalApiKey = $this->config->get('jotformApiKey');
        $stateFilePath = 'data/jotform-sync-state.json';

        $this->log->info('JotformToBookingSync: Starting');

       // ===== CRON LOCK (prevent overlapping runs) =====


$lockPath = __DIR__ . '/../../../../data/jotform-sync.lock';
$lockFile = fopen($lockPath, 'c');



if (!$lockFile) {
    $this->log->error(
        'JotformToBookingSync: failed to create lock file.'
    );
    return;
}

if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
    $this->log->warning(
        'JotformToBookingSync already running. Skipping.'
    );
    return;
}

        $this->processOrphanQueue();
        $state = $this->loadState($stateFilePath);

        $jotformForms = $this->entityManager
            ->getRDBRepository('CJotformForm')
            ->where(['active' => true])
            ->find();



$this->log->warning(
    '[JOTFORM] STEP 3 FORMS COUNT=' . count($jotformForms)
);







        if (empty($jotformForms)) {
            $this->log->warning('No active CJotformForm records found.');
            return;
        }



$this->log->warning('[JOTFORM] BEFORE LOOP');






        foreach ($jotformForms as $jotformForm) {

            $formId = $jotformForm->get('formId');
            $role   = $jotformForm->get('role');
            $tourId = $jotformForm->get('toursId');

            if (!$formId || !$role || !$tourId) {
                $this->log->warning(
                    "CJotformForm {$jotformForm->getId()} misconfigured. Skipping."
                );
                continue;
            }

            $tour = $this->entityManager->getEntity('CTours', $tourId);
            if (!$tour) {
                $this->log->warning(
                    "Tour {$tourId} not found for CJotformForm {$jotformForm->getId()}."
                );
                continue;
            }

            $tourCode = $tour->get('tourCode');
            $formApiKey = $jotformForm->get('apiKey') ?: $globalApiKey;

            if (!$formApiKey) {
                $this->log->warning(
                    "No API key available for JotForm {$formId}. Skipping."
                );
                continue;
            }

            $stateKey = 'form_' . $formId;

            if (!$jotformForm->get('initialized')) {
                $state[$stateKey] = date('Y-m-d H:i:s');
                $jotformForm->set('initialized', true);
                $this->entityManager->saveEntity($jotformForm, ['silent' => true]);

                $this->log->info(
                    "Initialized JotForm {$formId} ({$tourCode}). Bookmark set."
                );
                continue;
            }

            $lastSync = $state[$stateKey] ?? '2000-01-01 00:00:00';

     if ($role === 'followup') {

     // Idempotency guard

    if ((string)$formId !== (string)self::UNIVERSAL_FOLLOWUP_FORM_ID) {
    

    $this->log->info(
            "Skipping legacy follow-up form {$formId}"
        );
        continue;
    }


$this->log->info(
    "FOLLOWUP FETCH START form={$formId} lastSync={$lastSync}"
);

       $submissions = $this->fetchFollowupSubmissions(
        $formApiKey,
        $formId,
        $lastSync
    );


$this->log->info(
    "FOLLOWUP FETCH DONE count=" . count($submissions)
);




/* ======================================
   ENTERPRISE SAFE SORT
   Ensure newest submissions are processed first
   ====================================== */



if (count($submissions) > 1) {
    usort($submissions, function ($a, $b) {
        return strcmp(
            $b['created_at'] ?? '',
            $a['created_at'] ?? ''
        );
    });
}



     $maxCreatedAt = $lastSync;
//   $maxUpdatedAt = $lastSync;

/* ======================================
   PROCESS ONLY NEWEST SUBMISSION
   PER BOOKING
   ====================================== */
$processedBookings = [];

foreach ($submissions as $submission) {

    $answers = $submission['answers'] ?? [];

$bookingCodeTest = trim(
    (string)$this->getAnswer($answers, 'bookingCode')
);


 // ALWAYS advance bookmark first
    $createdAt = $submission['created_at'] ?? null;

    if ($createdAt && $createdAt > $maxCreatedAt) {
        $maxCreatedAt = $createdAt;
    }



    // Skip older submissions for same booking
    if (
        $bookingCodeTest &&
        isset($processedBookings[$bookingCodeTest])
    ) {
        continue;
    }

    $processedBookings[$bookingCodeTest] = true;



$booking = $this->handleFollowupSubmission(
    $submission,
    $answers
);




if ($booking) {

    // 1️⃣ Save updates first
    $this->entityManager->saveEntity($booking, [
        'runWorkflow' => true
    ]);

    // 2️⃣ RELOAD booking from database
    $booking = $this->entityManager
        ->getEntityById('CBooking', $booking->getId());

    // 3️⃣ Build URL from fresh data
    $booking->set(
        'followUpFormUrlLong',
        $this->buildFollowupUrl($booking)
    );

    // 4️⃣ Save URL (no workflows needed)
    $this->entityManager->saveEntity($booking, [
        'runWorkflow' => false
    ]);
}






}


if ($maxCreatedAt > $lastSync) {
    $state[$stateKey] = $maxCreatedAt;
}

/*
    if ($maxUpdatedAt > $lastSync) {
        $state[$stateKey] = $maxUpdatedAt;
    }
*/

    continue;
}

    if ($role === 'initial') {

    $submissions = $this->fetchSubmissionsByCreatedAt(
        $formApiKey,
        $formId,
        $lastSync
    );

    $maxCreatedAt = $lastSync;

    foreach ($submissions as $submission) {

$createdAt = $submission['created_at'] ?? null;

if ($createdAt && $createdAt > $maxCreatedAt) {
    $maxCreatedAt = $createdAt;
}

        $answers = $submission['answers'] ?? [];

        $this->handleInitialSubmission(
            $submission,
            $answers,
            $tour,
            $tourCode
        );
    }

if ($maxCreatedAt > $lastSync) {
    $state[$stateKey] = $maxCreatedAt;
}

    $this->log->info(
        "Processed " . count($submissions) . " submissions for form {$formId}"
    );

    continue;
}

            $this->log->info(
                "Processed " . count($submissions) . " submissions for form {$formId}"
            );
        }

        $this->saveState($stateFilePath, $state);
       //  $this->log->info('JotformToBookingSync: Finished.');
           $duration = round(microtime(true) - $startedAt, 2);
           $this->jobLog("FINISH duration={$duration}s");

    }

    /* ============================================================
       HELPERS
       ============================================================ */
private function updateFollowupLifecycle($booking): void
{
    if ($booking->get('followUpReceivedAt')) {
        $booking->set('followUpLifecycleState', 'Follow-Up Received');
    }
    elseif ($booking->get('followUpSentAt')) {
        $booking->set('followUpLifecycleState', 'Awaiting Response');
    }
    else {
        $booking->set('followUpLifecycleState', 'Not Sent');
    }
}


private function updateContractLifecycle($booking): void
{
    $decision = $booking->get('contractDecision');

    if ($decision === 'accepted') {
        $booking->set('contractLifecycleState', 'Accepted');
    }
    elseif ($decision === 'contact-info') {
        $booking->set('contractLifecycleState', 'Contact for Information');
    }
    else {
        $booking->set('contractLifecycleState', 'Review');
    }
}


private function buildFollowupUrl($booking): string
{
    $base = 'https://form.jotform.com/260444955711257';

$params = [
    'bookingCode' => (string)$booking->get('bookingCode'),
    'preferredName' => (string)$booking->get('preferredName'),
    'tourName' => (string)$booking->get('tourName'),
    'tourCode' => (string)$booking->get('tourCode'),
    'departureCity' => (string)$booking->get('departureCity'),
    'arrivalAirline' => (string)$booking->get('arrivalAirline'),
    'arrivalFlightNumber' => (string)$booking->get('arrivalFlightNumber'),
    'arrivalAirport' => (string)$booking->get('arrivalAirport'),
    'arrivalDateTime' => (string)$booking->get('arrivalDateTime'),
    'departureAirport' => (string)$booking->get('departureAirport'),
    'departureAirline' => (string)$booking->get('departureAirline'),
    'departureFlightNumber' => (string)$booking->get('departureFlightNumber'),
    'departureDateTime' => (string)$booking->get('departureDateTime'),
    'independentTravel' => (string)$booking->get('independentTravel'),
    'guidance' => (string)$booking->get('guidance'),
    'companions' => (string)$booking->get('companions'),
];


         // �Improvement
    $params = array_filter($params, function ($v) {
        return $v !== null && $v !== '';
    });


return $base . '?' . http_build_query(
    $params,
    '',
    '&',
    PHP_QUERY_RFC3986
);





    // return $base . '?' . http_build_query($params);
}


private function getFieldLabel(string $field): string
{
    $defs = $this->config->get('entityDefs.CBooking.fields.' . $field);

    if (is_array($defs) && !empty($defs['label'])) {
        return $defs['label'];
    }

    // fallback: readable format
    return ucfirst(preg_replace('/([a-z])([A-Z])/', '$1 $2', $field));
}





private function markSubmissionProcessed(string $submissionId): void
{
    $stateFile = 'data/jotform-orphan-ignore.json';

    $state = [];

    if ($this->fileManager->exists($stateFile)) {
        $state = json_decode(
            $this->fileManager->getContents($stateFile),
            true
        ) ?? [];
    }

    $state[$submissionId] = date('Y-m-d H:i:s');

    $this->fileManager->putContents(
        $stateFile,
        Json::encode($state)
    );
}

// SELF HEALING: PROCESS ORPHAN QUEUE


private function processOrphanQueue(): void
{
    $path = 'data/jotform-orphans';

    if (!$this->fileManager->exists($path)) {
        return;
    }

    $files = glob('data/jotform-orphans/*.json');

    if (!$files) {
        return;
    }

    foreach ($files as $file) {

        $data = json_decode(
            $this->fileManager->getContents($file),
            true
        );

        if (!$data) {
            continue;
        }

        $this->log->info(
            "Self-heal: retrying orphan submission {$data['submissionId']}"
        );

        $submission = [
            'id' => $data['submissionId']
        ];

        $answers = $data['answers'] ?? [];

        // reuse normal logic
        $this->handleFollowupSubmission($submission, $answers);
        // if booking now exists, remove orphan file
        $bookingCode =
            trim($this->getAnswer($answers, 'bookingCode'));

        $booking = $this->entityManager
            ->getRDBRepository('CBooking')
            ->where([
                'bookingCode' => $bookingCode
            ])
            ->findOne();
if (
    $booking &&
    $booking->get('followUpSubmissionId') === $data['submissionId']
) {

    unlink($file);

    $this->log->info(
        "Self-heal: orphan repaired {$data['submissionId']}"
    );
}


    }
}



private function fetchFollowupSubmissions($apiKey, $formId, $since)
{
    $dt = new \DateTime($since, new \DateTimeZone('UTC'));
    $isoSince = $dt->format('Y-m-d\TH:i:s\Z');

    $filter = urlencode(json_encode([
        'created_at:gt' => $isoSince
    ]));

    return $this->fetch($apiKey, $formId, $filter);
}





/*
private function fetchSubmissionsByUpdatedAt($apiKey, $formId, $since)
{
    // Normalize bookmark to UTC ISO-8601
    $dt = new \DateTime($since, new \DateTimeZone('UTC'));
    $isoSince = $dt->format('Y-m-d\TH:i:s\Z');

    $filter = urlencode(json_encode([
        'updated_at:gt' => $isoSince
    ]));

    return $this->fetch($apiKey, $formId, $filter);
}
*/



    private function normalizeForUrl(string $value): string
    {
        $value = str_replace('-', '-', $value);
        $value = preg_replace('/[^A-Za-z0-9\-]+/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        return trim($value, '-');
    }

    private function normalizeDateTime24($value): ?string
    {
        if (!$value || !is_string($value)) {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d H:i', trim($value));
        return $dt ? $dt->format('Y-m-d H:i:s') : null;
    }

    private function getAnswer(array $answers, string $key): ?string
    {
        if (isset($answers[$key]['answer'])) {
            $answer = $answers[$key]['answer'];
            return is_array($answer)
                ? trim(implode(' ', $answer))
                : trim((string) $answer);
        }

        foreach ($answers as $answerData) {
            if (($answerData['name'] ?? null) === $key) {
                $answer = $answerData['answer'] ?? null;
                return is_array($answer)
                    ? trim(implode(' ', $answer))
                    : trim((string) $answer);
            }
        }

        return null;
    }


//  INITIAL SUBMISSION: CREATE BOOKING

    private function handleInitialSubmission(
        array $submission,
        array $answers,
        $tour,
        string $tourCode
    ): void {
        $email = $answers['188']['answer'] ?? null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->log->warning(
                "Skipping submission {$submission['id']}  invalid or missing email."
            );
            return;
        }

        $data = $this->mapInitialAnswersToBooking($answers);
        $bookingCode = $tourCode . '-' . substr($submission['id'], -6);

        $bookingCodeUrl = $this->normalizeForUrl($bookingCode);

// HARD DUPLICATE CHECK (by bookingCode)
$existing = $this->entityManager
    ->getRDBRepository('CBooking')
    ->where(['bookingCode' => $bookingCode])
    ->findOne();

if ($existing) {
    $this->log->info(
        "Skipping duplicate submission {$submission['id']} (bookingCode {$bookingCode} already exists)"
    );
    return;
}


        $booking = $this->entityManager->getEntity('CBooking');
        $booking->set(array_merge($data, [
            'name'        => trim($data['firstName'] . ' ' . $data['lastName']),
            'bookingCode' => $bookingCode,
            'bookingCodeUrl' => $bookingCodeUrl,
            'tourCode'    => $tourCode,
            'toursId'     => $tour->getId(),
            'jotformSubmissionId' => $submission['id'],
            'contractReceivedAt' => date('Y-m-d H:i:s'),
        ]));

       $this->updateContractLifecycle($booking);

        try {

$this->entityManager->saveEntity($booking);

// Enterprise safety check: detect duplicate active forms
$duplicateCount = $this->entityManager
    ->getRDBRepository('CJotformForm')
    ->where([
        'role'    => 'followup',
        'toursId' => $booking->get('toursId'),
        'active'  => true,
    ])
    ->count();

if ($duplicateCount > 1) {
    $this->log->warning(
        'Multiple active follow-up forms detected for tour ' .
        $booking->get('toursId')
    );
}

// Default to universal follow-up form
$formId = self::UNIVERSAL_FOLLOWUP_FORM_ID;

$url =
    'https://form.jotform.com/' .
    $formId .
    '?bookingCode=' . urlencode($booking->get('bookingCode')) .
    '&preferredName=' . urlencode($booking->get('preferredName') ?: '') .
    '&tourName=' . urlencode($tour->get('name'));

    $booking->set('followUpFormUrlLong', $url); 
    $this->entityManager->saveEntity($booking);

            $this->log->info(
                "Created Booking {$booking->getId()} ({$bookingCode}) " .
                "from submission {$submission['id']}"
            );

        } catch (\Exception $e) {
            $this->log->error(
                "Failed creating Booking from submission {$submission['id']}: " .
                $e->getMessage()
            );
        }
    }

    /* ============================================================
       FOLLOW-UP SUBMISSION  UPDATE BOOKING
       ============================================================ */

private function handleFollowupSubmission(
    array $submission,
    array $answers
): ?object

{
$submissionId = (string)($submission['id'] ?? '');

$bookingCodeRaw = $this->getAnswer($answers, 'bookingCode');

if (!$bookingCodeRaw) {
    $this->log->warning(
        "Follow-up submission {$submissionId} missing bookingCode."
    );
    return null;
}

      $bookingCode = trim($bookingCodeRaw);

$booking = $this->entityManager
    ->getRDBRepository('CBooking')
    ->where([
        'bookingCode' => $bookingCode
    ])
    ->findOne();

/* ======================================
   SELF-HEALING: recover soft-deleted booking
   ====================================== */
if (!$booking) {

    $pdo = $this->entityManager->getPDO();

    $stmt = $pdo->prepare(
        "SELECT id, deleted
         FROM c_booking
         WHERE booking_code = :code
         LIMIT 1"
    );

    $stmt->execute([
        ':code' => $bookingCode
    ]);

    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($row && (int)$row['deleted'] === 1) {

        $this->log->warning(
            "Self-heal: restoring deleted booking {$bookingCode}"
        );

        $pdo->prepare(
            "UPDATE c_booking
             SET deleted = 0
             WHERE id = :id"
        )->execute([
            ':id' => $row['id']
        ]);

        $booking = $this->entityManager
            ->getEntity('CBooking', $row['id']);
    }
}   //  ADD THIS LINE (closes first if (!$booking))


if (!$booking) {

    $this->log->warning(
        "Follow-up orphan permanently skipped: booking not found for code {$bookingCode}"
    );

    /* ======================================
       SELF-HEALING: orphan quarantine
       ====================================== */

    $orphanPath =
        'data/jotform-orphans/' .
        $submission['id'] . '.json';

    $this->fileManager->putContents(
        $orphanPath,
        Json::encode([
            'submissionId' => $submission['id'],
            'bookingCode'  => $bookingCode,
            'timestamp'    => date('Y-m-d H:i:s'),
            'answers'      => $answers,
        ])
    );

    // prevent infinite retries
    $this->markSubmissionProcessed($submission['id']);

    return null;
}

// Idempotency guard




$lastSubmissionId = (string)$booking->get('followUpSubmissionId');

if ($lastSubmissionId &&
    strcmp((string)$submission['id'], $lastSubmissionId) <= 0
) {

    $this->log->info(
        "Skipping older follow-up submission {$submission['id']}"
    );

    return null;
}





/* ======================================
   NORMAL PROCESSING CONTINUES
   ====================================== */



        $updated = false;
        $changes = [];
        $fieldMap = [
            '10' => 'arrivalAirline',
            '11' => 'arrivalFlightNumber',
            '12' => 'arrivalAirport',
            '83' => 'arrivalDateTime',
            '17' => 'departureAirport',
            '18' => 'departureAirline',
            '19' => 'departureFlightNumber',
            '50' => 'departureCity',
            '84' => 'departureDateTime',
            '28' => 'socialMedia',
            '41' => 'independentTravel',
            '42' => 'guidance',
            '44' => 'companions',
        ];




foreach ($fieldMap as $qid => $field) {

    $raw = $this->getAnswer($answers, $qid);

    if ($raw === null || $raw === '' || strtolower(trim($raw)) === 'none') {
        continue;
    }

    if (substr($field, -8) === 'DateTime') {

        $value = $this->normalizeDateTime24($raw);

        if ($value === null) {
            continue;
        }

    } else {
        $value = $raw;
    }

    $oldValue = $booking->get($field);

    if ($oldValue !== $value) {

        $this->log->info(
            "FIELD CHANGED: {$field} | OLD={$oldValue} | NEW={$value}"
        );

        $changes[] = [
            'field' => $this->getFieldLabel($field),
            'old'   => $oldValue,
            'new'   => $value,
        ];

        $booking->set($field, $value);
        $updated = true;
    }
}


// detect first follow-up BEFORE overwriting
$isFirstFollowup = !$booking->get('followUpSubmissionId');

// Always record follow-up receipt
$booking->set('followUpSubmissionId', $submission['id']);
$booking->set('followUpReceivedAt', date('Y-m-d H:i:s'));

$this->updateFollowupLifecycle($booking);

$booking->set(
    'followUpFormUrlLong',
    $this->buildFollowupUrl($booking)
);

$updated = true;

// Save if:
//  - Fields changed
//  - OR this is first time storing submission ID
if ($updated || $isFirstFollowup) {

    $this->log->info(
        "Follow-up processed for booking {$bookingCode} " .
        "(submission {$submission['id']})"
    );



if (!empty($changes)) {
$summary = $this->buildChangeSummary(
    $booking->get('bookingCode'),
    $changes,
    $booking
);
    $booking->set('followUpChangeSummary', $summary);
    $this->log->info("CHANGE SUMMARY:\n" . $summary);

    // ENTERPRISE HOOK (notifications later)
    $this->notifyStaffOfChanges($booking, $summary);
}

// ALWAYS build customer snapshot
$customerHtml = $this->buildCustomerSnapshotHtml($booking);
$booking->set('followUpCustomerSnapshotHtml', $customerHtml);

    // RETURN booking instead of saving
    return $booking;
}

// nothing changed
return null;
}

    /* ============================================================
       REMAINING HELPERS
       ============================================================ */

private function buildChangeSummary(
    string $bookingCode,
    array $changes,
    $booking
): string {

    $traveler = trim(
        ($booking->get('firstName') ?? '') . ' ' .
        ($booking->get('lastName') ?? '')
    );

$tour =
    $booking->get('toursName')
    ?: $booking->get('name')
    ?: '';


    // ULTRA MODE — critical travel-impact fields
    $criticalFields = [
        'arrivalAirline',
        'arrivalFlightNumber',
        'arrivalAirport',
        'arrivalDateTime',
        'departureAirport',
        'departureAirline',
        'departureFlightNumber',
        'departureDateTime',
    ];

    $html = '';
    $html .= '<h2 style="margin-bottom:6px;">Booking Follow-up Updated</h2>';
    $html .= '<p style="margin:0;">';
    $html .= '<strong>Traveler:</strong> '
        . htmlspecialchars($traveler) . '<br>';
    $html .= '<strong>Booking:</strong> '
        . htmlspecialchars($bookingCode) . '<br>';
    $html .= '<strong>Tour:</strong> '
        . htmlspecialchars($tour);
    $html .= '</p>';

    $html .= '<p style="margin-top:10px;">';
    $html .= '<strong>Summary:</strong> '
        . count($changes)
        . ' travel detail(s) updated.';
    $html .= '</p>';
    $html .= '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse: collapse; width:100%; table-layout: fixed;">';
    $html .= '<tr style="background:#f5f5f5;">';
    $html .= '<th style="width:33%;">Field</th>';
    $html .= '<th style="width:33%;">Previous</th>';
    $html .= '<th style="width:33%;">Updated</th>';
    $html .= '</tr>';


$rowIndex = 0;

foreach ($changes as $c) {

    $label = $this->getFieldLabel($c['field']);

    $isCritical = in_array(
        $c['field'],
        $criticalFields,
        true
    );

    // zebra striping
    $rowBg = ($rowIndex % 2 === 0)
        ? '#ffffff'
        : '#f7f9fc';

    // critical highlight overrides stripe
    if ($isCritical) {
        $rowBg = '#fff3cd';
    }

    $html .= '<tr style="background:' . $rowBg . ';">';

    $html .= '
        <td style="
        width:33%;
        padding:8px;
        font-weight:600;
        vertical-align:top;
    ">'
        . htmlspecialchars($label)
        . '</td>';

    $html .= '<td style="
        width:33%;
        padding:8px;
        color:#777;
        vertical-align:top;
    ">'
        . htmlspecialchars((string)$c['old'])
        . '</td>';
    $html .= '<td style="
        width:33%;
        padding:8px;
        font-weight:bold;
        vertical-align:top;
        background:#eaf7ea;
        border-left:2px solid #8bc98b;
    ">'
    . htmlspecialchars((string)$c['new'])
    . '</td>';

    $html .= '</tr>';

    $rowIndex++;
}

    $html .= '</table>';

    $html .= '<p style="margin-top:12px;color:#777;font-size:12px;">';
    $html .= '— Okan Arts Operations —<br>';
    $html .= 'Automatically generated from traveler follow-up update.';
    $html .= '</p>';

    return $html;
}


private function buildCustomerSnapshotHtml($booking): string
{
    $firstName = htmlspecialchars((string)$booking->get('firstName'));
    $lastName = htmlspecialchars((string)$booking->get('lastName'));
    $bookingCode = htmlspecialchars((string)$booking->get('bookingCode'));
    $tour = htmlspecialchars(
        (string)($booking->get('toursName') ?: '')
    );

    $html = '';
    $html .= '<h2 style="margin-bottom:6px;">Your up-to-date information</h2>';
    $html .= '<p style="margin:0;">';
    $html .= '<strong>Traveler:</strong> ' . $firstName . ' ' . $lastName . '<br>';
    $html .= '<strong>Booking:</strong> ' . $bookingCode . '<br>';
    $html .= '<strong>Tour:</strong> ' . $tour;
    $html .= '</p>';

    $html .= '<table border="1" cellpadding="6" cellspacing="0" ';
    $html .= 'style="border-collapse: collapse; width:100%; margin-top:12px;">';

    $html .= '<tr style="background:#f5f5f5;">';
    $html .= '<th style="width:40%;">Detail</th>';
    $html .= '<th style="width:60%;">Information on File</th>';
    $html .= '</tr>';

    $fields = [
        'arrivalAirline',
        'arrivalFlightNumber',
        'arrivalAirport',
        'arrivalDateTime',
        'departureAirport',
        'departureAirline',
        'departureFlightNumber',
        'departureDateTime',
        'departureCity',
        'independentTravel',
        'guidance',
        'companions',
    ];

    $rowIndex = 0;

    foreach ($fields as $field) {

        $value = $booking->get($field);

        if ($value === null || $value === '') {
            continue; // hide empty rows
        }

        $label = htmlspecialchars($this->getFieldLabel($field));
        $value = htmlspecialchars((string)$value);

        $rowBg = ($rowIndex % 2 === 0) ? '#ffffff' : '#f7f9fc';

        $html .= '<tr style="background:' . $rowBg . ';">';
        $html .= '<td style="padding:8px; font-weight:600;">' . $label . '</td>';
        $html .= '<td style="padding:8px;">' . $value . '</td>';
        $html .= '</tr>';

        $rowIndex++;
    }

    $html .= '</table>';

    $html .= '<p style="margin-top:20px;">';
    $html .= '<a href="' . htmlspecialchars($booking->get('followUpFormUrlLong')) . '" ';
    $html .= 'style="background-color:#D34E33; color:#ffffff; padding:12px 20px; text-decoration:none; border-radius:4px; font-weight:bold;">';
    $html .= 'I need to change something</a>';
    $html .= '</p>';

    return $html;
}


private function notifyStaffOfChanges(
    $booking,
    string $summary
): void {

    try {

        $note = $this->entityManager->getEntity('Note');

        $note->set([
            'post' => $summary,
            'type' => 'Post',
            'parentType' => 'CBooking',
            'parentId' => $booking->getId(),
        ]);

        $this->entityManager->saveEntity($note);

        $this->log->info(
            "STAFF NOTE CREATED for booking " .
            $booking->get('bookingCode')
        );

    } catch (\Throwable $e) {

        $this->log->error(
            "Failed staff notification for booking " .
            $booking->get('bookingCode') .
            ": " . $e->getMessage()
        );
    }
}



private function mapInitialAnswersToBooking(array $answers): array
{
    return [
        'firstName'   => $answers['184']['answer'] ?? '',
        'lastName'    => $answers['185']['answer'] ?? '',
        'socialMedia' => $answers['147']['answer'] ?? '',
        'contactEmail'=> $answers['188']['answer'] ?? '',
        'contactPhone'=> $answers['186']['answer'] ?? '',
        'preferredName' => $answers['209']['answer'] ?? '',

        'dietaryInfo' => $answers['53']['answer'] ?? '',
        'rawFish'     => ($answers['57']['answer'] ?? '') === 'YES please'
            ? 'Yes'
            : 'No',

        'fullNameonPassport'     => $answers['33']['answer'] ?? '',
        'passportNumber'         => $answers['35']['answer'] ?? '',
        'passportExpirationDate' => $this->formatJotformDate(
            $answers['41']['answer'] ?? ''
        ),
        'passportCountry'        => $answers['42']['answer'] ?? '',
        'birthDate'              => $this->formatJotformDate(
            $answers['43']['answer'] ?? ''
        ),

        'addressStreet'  => $answers['123']['answer'] ?? '',
        'addressStreet2' => $answers['124']['answer'] ?? '',
        'addressCity'    => $answers['125']['answer'] ?? '',
        'addressState'   => $answers['126']['answer'] ?? '',
        'addressZip'     => $answers['127']['answer'] ?? '',
        'addressCountry' => $answers['128']['answer'] ?? '',

        'firstEmergencyContactName'         => $answers['72']['answer'] ?? '',
        'firstEmergencyContactRelationship' => $answers['76']['answer'] ?? '',
        'firstEmergencyContactEmail'        => $answers['77']['answer'] ?? '',
        'firstEmergencyContactPhone'        => $answers['78']['answer'] ?? '',

        'secondEmergencyContactName'         => $answers['81']['answer'] ?? '',
        'secondEmergencyContactRelationship' => $answers['82']['answer'] ?? '',
        'secondEmergencyContactEmail'        => $answers['83']['answer'] ?? '',
        'secondEmergencyContactPhone'        => $answers['84']['answer'] ?? '',
    ];
}

private function formatJotformDate($dateInput): ?string
{
    if (is_array($dateInput)) {
        $m = $dateInput['month'] ?? '';
        $d = $dateInput['day'] ?? '';
        $y = $dateInput['year'] ?? '';

        return ($m && $d && $y) ? "{$y}-{$m}-{$d}" : null;
    }

    return null;
}


private function fetchSubmissionsByCreatedAt($apiKey, $formId, $since)
{
    // Normalize bookmark to UTC ISO-8601 (JotForm expects this)
    $dt = new \DateTime($since, new \DateTimeZone('UTC'));
    $isoSince = $dt->format('Y-m-d\TH:i:s\Z');

$filter = urlencode(json_encode([
    'created_at:gt' => $isoSince
]));

    return $this->fetch($apiKey, $formId, $filter);
}

private function fetch($apiKey, $formId, $filter)
{
    // default ordering
    $field = 'updated_at';

    if (!empty($filter)) {
        $decodedFilter = json_decode(urldecode($filter), true);

        if (is_array($decodedFilter) && !empty($decodedFilter)) {
            $tmp = array_key_first($decodedFilter);
            $field = str_replace(':gt', '', $tmp);
        }
    }




$url = "https://api.jotform.com/form/{$formId}/submissions" .
    "?apiKey={$apiKey}" .
    "&orderby={$field}" .
    "&direction=DESC" .
    "&limit=1000";

if (!empty($filter)) {
    $url .= "&filter={$filter}";
}

    $response = file_get_contents($url);

    $data = json_decode($response, true);

    return $data['content'] ?? [];
}

private function loadIgnoredSubmissions(): array
{
    $file = 'data/jotform-orphan-ignore.json';

    if (!$this->fileManager->exists($file)) {
        return [];
    }

    return json_decode(
        $this->fileManager->getContents($file),
        true
    ) ?? [];
}


    private function loadState($path)
    {
        if (!$this->fileManager->exists($path)) {
            return [];
        }

        return json_decode(
            $this->fileManager->getContents($path),
            true
        ) ?? [];
    }

    private function saveState($path, $state)
    {
        $tmp = $path . '.tmp';
        $this->fileManager->putContents($tmp, Json::encode($state));
        rename($tmp, $path);
    }

}











