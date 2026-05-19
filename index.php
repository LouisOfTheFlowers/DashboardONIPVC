<?php
declare(strict_types=1);

$profile = trim((string) ($_GET['profile'] ?? 'presidencia'));
$pages = [
    'presidencia' => 'presidencia.php',
    'cc' => 'cc.php',
    'docente' => 'docente.php',
    'dir_uo' => 'dir_uo.php',
    'pessoal' => 'pessoal.php',
    'gestao_documental' => 'gestao_documental.php',
    'gac' => 'gac.php',
];

require __DIR__ . DIRECTORY_SEPARATOR . ($pages[$profile] ?? $pages['presidencia']);

