<?php

require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cookieFile = '/tmp/dpi-post-test.txt';
@unlink($cookieFile);

function http(string $url, array $opts = []): string
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

    return $code.'|'.$raw;
}

$login = http('http://nginx/login');
preg_match('/name="_token" value="([^"]+)"/', $login, $m);
$token = $m[1] ?? '';

http('http://nginx/login', [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'login' => 'admin@dpi-rdc.local',
        'password' => 'dpi-admin-2024',
        '_token' => $token,
    ]),
]);

$create = http('http://nginx/patients/nouveau');
preg_match('/name="_token" value="([^"]+)"/', $create, $m2);
$formToken = $m2[1] ?? '';

$post = http('http://nginx/patients', [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_token' => $formToken,
        'nom' => 'MUTOMBO',
        'prenom' => 'Patrick',
        'sexe' => 'M',
        'type_prise_en_charge' => 'prive',
        'nationalite' => 'Congolaise',
    ]),
]);

$code = (int) explode('|', $post, 2)[0];
echo 'post_status='.$code.PHP_EOL;
echo 'patient_exists='.(App\Models\Patient::where('nom', 'MUTOMBO')->where('prenom', 'Patrick')->exists() ? 'yes' : 'no').PHP_EOL;
