<?php

require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('is_active', true)->first();
Auth::login($user);

$response = Livewire\Livewire::test(App\Livewire\Patients\PatientCreate::class)
    ->set('nom', 'NOUVEAU')
    ->set('prenom', 'PatientTest')
    ->set('sexe', 'M')
    ->set('type_prise_en_charge', 'prive')
    ->call('save');

echo 'errors='.json_encode($response->errors()).PHP_EOL;
echo 'redirect='.($response->effects['redirect'] ?? 'none').PHP_EOL;
echo 'saved='.(App\Models\Patient::where('nom', 'NOUVEAU')->exists() ? 'yes' : 'no').PHP_EOL;
