<?php
declare(strict_types=1);

$profile = trim((string) ($_GET['profile'] ?? 'presidencia'));
$profiles = [
    'presidencia',
    'cc',
    'docente',
    'dir_uo',
    'pessoal',
    'gestao_documental',
    'gac',
];

if (!in_array($profile, $profiles, true)) {
    $profile = 'presidencia';
}

define('DASHBOARD_PAGE', $profile);

require __DIR__ . DIRECTORY_SEPARATOR . 'dashboard.php';
