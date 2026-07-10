<?php

$cookieFile = '/tmp/dpi-test-cookies.txt';
@unlink($cookieFile);

function request(string $url, array $opts = []): array
{
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_FOLLOWLOCATION => false,
    ] + $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return ['code' => $code, 'raw' => $raw ?: ''];
}

$loginPage = request('http://nginx/login');
preg_match('/name="_token" value="([^"]+)"/', $loginPage['raw'], $m);
$token = $m[1] ?? '';
echo 'login_page='.$loginPage['code'].' token_len='.strlen($token).PHP_EOL;

$loginPost = request('http://nginx/login', [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'login' => 'admin@dpi-rdc.local',
        'password' => 'dpi-admin-2024',
        '_token' => $token,
    ]),
]);
echo 'login_post='.$loginPost['code'].PHP_EOL;
echo 'cookie_file_size='.(file_exists($cookieFile) ? filesize($cookieFile) : 0).PHP_EOL;

$page = request('http://nginx/patients/nouveau');
$body = $page['raw'];
echo 'create_page='.$page['code'].' len='.strlen($body).PHP_EOL;
echo 'has_patient_form='.(str_contains($body, 'Enregistrer le patient') ? 'yes' : 'no').PHP_EOL;
echo 'redirect_login='.(str_contains($body, '/login') ? 'yes' : 'no').PHP_EOL;

if (! str_contains($body, 'csrf-token')) {
    echo "FAIL: not authenticated\n";
    exit(1);
}

preg_match('/csrf-token" content="([^"]+)"/', $body, $cm);
$csrf = $cm[1] ?? '';

preg_match_all('/wire:snapshot="([^"]+)"/', $body, $snapshots);
echo 'components_on_page='.count($snapshots[1]).PHP_EOL;

if (count($snapshots[1]) === 0) {
    echo "FAIL: no livewire snapshots\n";
    exit(1);
}

$snapshot = html_entity_decode($snapshots[1][0], ENT_QUOTES);
$snapshotData = json_decode($snapshot, true);
$payload = [
    '_token' => $csrf,
    'components' => [[
        'snapshot' => $snapshot,
        'updates' => [],
        'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
    ]],
];

$update = request('http://nginx/livewire/update', [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-CSRF-TOKEN: '.$csrf,
        'X-Livewire: ',
    ],
]);

$updateBody = $update['raw'];
$headerEnd = strpos($updateBody, "\r\n\r\n");
$json = substr($updateBody, $headerEnd + 4);
$parsed = json_decode($json, true);

echo 'update_http='.$update['code'].PHP_EOL;
echo 'has_components='.(isset($parsed['components']) ? 'yes' : 'no').PHP_EOL;
echo 'components_count='.(isset($parsed['components']) ? count($parsed['components']) : 0).PHP_EOL;

if ($update['code'] === 200 && isset($parsed['components']) && count($parsed['components']) > 0) {
    echo "OK: Livewire save roundtrip works\n";
    exit(0);
}

echo "Response preview: ".substr($json, 0, 300).PHP_EOL;
exit(1);
