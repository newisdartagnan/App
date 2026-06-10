<?php
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$establishment = \App\Models\Establishment::first();
$user = \App\Models\User::create([
    'nom' => 'Admin',
    'prenom' => 'Super',
    'email' => 'admin@dpi-rdc.cd',
    'matricule' => 'ADMIN001',
    'password' => bcrypt('Admin@1234'),
    'is_active' => true,
    'establishment_id' => $establishment->id,
]);
$user->assignRole('super_admin');
echo "OK: " . $user->email . "\n";