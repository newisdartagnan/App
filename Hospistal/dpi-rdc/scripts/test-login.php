<?php

require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$request = Illuminate\Http\Request::create('/login', 'POST', [
    'login' => 'admin@dpi-rdc.local',
    'password' => 'dpi-admin-2024',
]);
$request->headers->set('Accept', 'text/html');

$session = $app->make('session');
$session->start();
$request->setLaravelSession($session);
$request->setSession($session);

// Simulate CSRF
$token = csrf_token();
$request->merge(['_token' => $token]);
$request->headers->set('X-CSRF-TOKEN', $token);

$response = $app->handle($request);
echo 'status='.$response->getStatusCode().PHP_EOL;
echo 'authenticated='.(auth()->check() ? 'yes' : 'no').PHP_EOL;
if (! auth()->check()) {
    echo 'errors='.json_encode(session('errors')?->getMessages()).PHP_EOL;
}
