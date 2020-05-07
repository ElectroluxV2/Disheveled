<?php
// 	/usr/local/bin/php /home/budziszm/domains/api.edziennik.ga/public_html/app/cron.php
$data = array('secret' => '205296');
$payload = json_encode($data);

// Prepare new cURL resource
//$ch = curl_init('https://api.edziennik.ga/deepChangesCheck');
$ch = curl_init('http://localhost:8080/deepChangesCheck');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLINFO_HEADER_OUT, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

// Set HTTP Header for POST request
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload))
);

// Submit the POST request
$result = curl_exec($ch);
if ($result === FALSE) {
    die(curl_error($ch));
}

// Close cURL session handle
curl_close($ch);

echo $result;