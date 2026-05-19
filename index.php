<?php
declare(strict_types=1);

$profile = trim((string) ($_GET['profile'] ?? 'presidencia'));
$pages = [
    'presidencia' => 'presidencia.php',
    'cc' => 'cc.php',
    'docente' => 'docente.php',
];

require __DIR__ . DIRECTORY_SEPARATOR . ($pages[$profile] ?? $pages['presidencia']);
