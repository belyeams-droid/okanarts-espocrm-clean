<?php

// ================= CONFIGURATION =================
$jotform_api_key = '799f4be8320e6bf289704e3d6fcf4eff';
$jotform_form_id = '260148371232046';           // Your form ID
$espo_url        = 'http://http://10.13.110.220:8283//api/v1';  // Your local EspoCRM URL
$espo_api_key    = '66facbbabf29d3d123d5e4dc761aa578';   // API Key from EspoCRM

$last_id_file    = __DIR__ . '/last_submission_id.txt';

// Field mapping: JotForm question ID → EspoCRM Contact field
// You MUST adjust these numbers after checking your form's submission structure
$field_mapping = [
    '3'  => 'firstName',       // usually first name
    '4'  => 'lastName',        // usually last name
    '5'  => 'emailAddress',    // primary email
    // '6'  => 'phoneNumber',
    // '7'  => 'title',
    // '8'  => 'addressStreet',
    // etc...
];

// ================= HELPERS =================

function get_last_submission_id($file) {
    if (file_exists($file)) {
        return trim(file_get_contents($file));
    }
    return null;
}

function save_last_submission_id($file, $id) {
    file_put_contents($file, $id);
}

function jotform_request($endpoint, $params = []) {
    global $jotform_api_key;

    $url = "https://api.jotform.com{$endpoint}";
    $params['apiKey'] = $jotform_api_key;

    $query = http_build_query($params);
    $full_url = $url . ($query ? '?' . $query : '');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $full_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("JotForm API failed: HTTP $http_code");
    }

    $data = json_decode($response, true);
    if ($data['responseCode'] !== 200 && $data['responseCode'] !== 0) {
        throw new Exception("JotForm error: " . ($data['message'] ?? 'Unknown'));
    }

    return $data;
}

function espo_request($method, $path, $data = null) {
    global $espo_url, $espo_api_key;

    $url = rtrim($espo_url, '/') . '/' . ltrim($path, '/');

    $headers = [
        'X-Api-Key: ' . $espo_api_key,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($data !== null) {
        $json = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code < 200 || $http_code >= 300) {
        throw new Exception("EspoCRM API failed: HTTP $http_code - $response");
    }

    return json_decode($response, true);
}

function find_contact_by_email($email) {
    if (!$email) return null;

    $params = [
        'select' => 'id',
        'maxSize' => 1,
        'where[0][type]' => 'equals',
        'where[0][attribute]' => 'emailAddress',
        'where[0][value]' => $email
    ];

    $result = espo_request('GET', 'Contact?' . http_build_query($params));

    if (!empty($result['list'])) {
        return $result['list'][0]['id'];
    }

    return null;
}

// ================= MAIN LOGIC =================

try {
    echo "Starting JotForm → EspoCRM sync...\n";

    $last_id = get_last_submission_id($last_id_file);

    $params = [
        'limit' => 100,
        'orderby' => 'created_at'
    ];

    if ($last_id) {
        $params['filter'] = json_encode(['id:gt' => $last_id]);
    }

    $response = jotform_request("/form/{$jotform_form_id}/submissions", $params);
    $submissions = $response['content'] ?? [];

    if (empty($submissions)) {
        echo "No new submissions found.\n";
        exit(0);
    }

    $newest_id = $last_id;

    foreach ($submissions as $submission) {
        $answers = $submission['answers'] ?? [];
        $contact_data = [];

        foreach ($field_mapping as $qid => $field) {
            if (isset($answers[$qid])) {
                $answer = $answers[$qid];
                $value = $answer['answer'] ?? $answer['text'] ?? $answer['prettyFormat'] ?? null;
                if ($value !== null && $value !== '') {
                    $contact_data[$field] = $value;
                }
            }
        }

        $email = $contact_data['emailAddress'] ?? null;
        if (!$email) {
            echo "Skipping submission {$submission['id']} - no email\n";
            continue;
        }

        $contact_id = find_contact_by_email($email);

        if ($contact_id) {
            // UPDATE
            espo_request('PUT', "Contact/{$contact_id}", $contact_data);
            echo "Updated contact {$contact_id} from submission {$submission['id']}\n";
        } else {
            // CREATE
            $result = espo_request('POST', 'Contact', $contact_data);
            echo "Created new contact from submission {$submission['id']}\n";
        }

        if ((int)$submission['id'] > (int)$newest_id) {
            $newest_id = $submission['id'];
        }
    }

    if ($newest_id !== $last_id) {
        save_last_submission_id($last_id_file, $newest_id);
        echo "Updated last processed ID to: $newest_id\n";
    }

    echo "Sync completed successfully.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
