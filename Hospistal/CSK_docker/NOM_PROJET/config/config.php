<?php
// Démarrage de la session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configuration générale
define('APP_NAME', 'GPS - Gestion de la Personne Soignée');
define('APP_VERSION', '2.0');
define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost:8001/');
define('ASSETS_URL', BASE_URL . 'assets/');

// Configuration des chemins
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('CONTROLLERS_PATH', ROOT_PATH . '/controllers');
define('MODELS_PATH', ROOT_PATH . '/models');
define('VIEWS_PATH', ROOT_PATH . '/views');
define('ASSETS_PATH', ROOT_PATH . '/assets');

// Fuseau horaire
date_default_timezone_set('Africa/Kinshasa');

// Configuration des erreurs (désactiver en production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Autoloader simple
spl_autoload_register(function ($class) {
    $paths = [
        MODELS_PATH . '/' . $class . '.php',
        CONTROLLERS_PATH . '/' . $class . '.php',
        CONFIG_PATH . '/' . $class . '.php'
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

// Autoloader Composer (si vendor disponible)
if (file_exists(ROOT_PATH . '/vendor/autoload.php')) {
    require_once ROOT_PATH . '/vendor/autoload.php';
}

// ============================================
// FONCTIONS UTILITAIRES
// ============================================

function redirect($url) {
    header("Location: " . BASE_URL . $url);
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

function hasPermission($permission) {
    if (!isLoggedIn()) return false;
    return in_array($permission, $_SESSION['permissions'] ?? []);
}

function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) return '-';
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = 'd/m/Y H:i') {
    if (empty($datetime)) return '-';
    return date($format, strtotime($datetime));
}

function formatMoney($amount, $currency = 'FC') {
    if ($amount === null || $amount === '') return '0,00 ' . $currency;
    return number_format((float)$amount, 2, ',', ' ') . ' ' . $currency;
}

function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

function generateNumero($prefix, $lastNumber) {
    $newNumber = $lastNumber + 1;
    return $prefix . str_pad($newNumber, 6, '0', STR_PAD_LEFT);
}

function calculateAge($date_naissance) {
    if (empty($date_naissance)) return 0;
    $dob = new DateTime($date_naissance);
    $now = new DateTime();
    return $dob->diff($now)->y;
}

function flashMessage($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// ============================================
// EXPORT EXCEL / CSV - appelable depuis partout
// ============================================

function exportToExcel(array $data, string $filename = 'export.xlsx', array $headers = []) {
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        exportToCSVDownload($data, str_replace('.xlsx', '.csv', $filename), $headers);
        return;
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $headerStyle = [
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => [
            'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => ['rgb' => '1e40af']
        ],
        'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
    ];

    $col = 1;
    $colHeaders = !empty($headers) ? $headers : (!empty($data) ? array_keys($data[0]) : []);

    foreach ($colHeaders as $header) {
        $sheet->getCellByColumnAndRow($col, 1)->setValue(strtoupper($header));
        $col++;
    }

    if ($col > 1) {
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col - 1);
        $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray($headerStyle);
    }

    $row = 2;
    foreach ($data as $line) {
        $col = 1;
        foreach (array_values($line) as $value) {
            $sheet->getCellByColumnAndRow($col, $row)->setValue($value);
            $col++;
        }
        if ($row % 2 === 0 && $col > 1) {
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col - 1);
            $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getFill()
                  ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                  ->getStartColor()->setRGB('f0f4ff');
        }
        $row++;
    }

    foreach ($sheet->getColumnDimensions() as $colDim) {
        $colDim->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}

function exportToCSVDownload(array $data, string $filename = 'export.csv', array $headers = []) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    if (!empty($headers)) {
        fputcsv($output, $headers, ';');
    } elseif (!empty($data)) {
        fputcsv($output, array_keys($data[0]), ';');
    }
    foreach ($data as $row) {
        fputcsv($output, array_values($row), ';');
    }
    fclose($output);
    exit();
}
