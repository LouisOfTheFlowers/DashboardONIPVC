<?php
declare(strict_types=1);

$jsonPath = __DIR__ . DIRECTORY_SEPARATOR . 'alertas/alertsPresidencia.json';
$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'alertas' . DIRECTORY_SEPARATOR . 'alertsPresidenciaconfig.json';

if (!is_file($jsonPath)) {
    http_response_code(500);
    echo 'Missing alertsPresidencia.json';
    exit;
}

try {
    $payload = json_decode(file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    http_response_code(500);
    echo 'Invalid JSON: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

$alertsConfig = [];
if (is_file($configPath)) {
    try {
        $alertsConfig = json_decode(file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        http_response_code(500);
        echo 'Invalid config JSON: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        exit;
    }
}

$presidencia = $payload['presidencia'] ?? [];
$grupos = $presidencia['grupos'] ?? [];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function buildUoOrder(array ...$datasets): array
{
    $preferred = ['ESA', 'ESCE', 'ESDL', 'ESE', 'ESS', 'ESTG'];
    $lookup = array_fill_keys($preferred, true);

    foreach ($datasets as $dataset) {
        foreach ($dataset as $row) {
            $uo = $row['uo'] ?? null;
            if (is_string($uo) && $uo !== '' && !isset($lookup[$uo])) {
                $preferred[] = $uo;
                $lookup[$uo] = true;
            }
        }
    }

    return $preferred;
}

function buildSemesterOrder(array ...$datasets): array
{
    $preferred = ['S1', 'S2'];
    $lookup = array_fill_keys($preferred, true);

    foreach ($datasets as $dataset) {
        foreach ($dataset as $row) {
            $semester = $row['semestre'] ?? null;
            if (is_string($semester) && $semester !== '' && !isset($lookup[$semester])) {
                $preferred[] = $semester;
                $lookup[$semester] = true;
            }
        }
    }

    return $preferred;
}

function initMatrix(array $uoOrder, array $keys): array
{
    $matrix = [];

    foreach ($uoOrder as $uo) {
        $matrix[$uo] = array_fill_keys($keys, 0);
    }

    return $matrix;
}

function normalizeState(string $value): string
{
    $map = [
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A',
        'É' => 'E', 'Ê' => 'E',
        'Í' => 'I',
        'Ó' => 'O', 'Õ' => 'O', 'Ô' => 'O',
        'Ú' => 'U',
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
        'é' => 'e', 'ê' => 'e',
        'í' => 'i',
        'ó' => 'o', 'õ' => 'o', 'ô' => 'o',
        'ú' => 'u',
        'ç' => 'c', 'Ç' => 'C',
    ];

    return strtolower(strtr($value, $map));
}

function filterRows(array $dataset, string $semesterFilter = '', string $uoFilter = ''): array
{
    return array_values(array_filter(
        $dataset,
        static function (array $row) use ($semesterFilter, $uoFilter): bool {
            if ($semesterFilter !== '' && (($row['semestre'] ?? '') !== $semesterFilter)) {
                return false;
            }

            if ($uoFilter !== '' && (($row['uo'] ?? '') !== $uoFilter)) {
                return false;
            }

            return true;
        }
    ));
}

function lookupConfigEntry(array $group, string $key): array
{
    $normalizedKey = normalizeState($key);

    foreach ($group as $entryKey => $entryValue) {
        if (normalizeState((string) $entryKey) === $normalizedKey && is_array($entryValue)) {
            return $entryValue;
        }
    }

    return [];
}

function findRangeRule(array $rules, int $value): array
{
    foreach ($rules as $rule) {
        $min = (int) ($rule['min'] ?? PHP_INT_MIN);
        $max = (int) ($rule['max'] ?? PHP_INT_MAX);

        if ($value >= $min && $value <= $max) {
            return is_array($rule) ? $rule : [];
        }
    }

    return [];
}

function isHexColor(string $color): bool
{
    return (bool) preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color);
}

function expandHexColor(string $color): string
{
    $hex = ltrim($color, '#');

    if (strlen($hex) === 3) {
        return '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    return '#' . $hex;
}

function rgbaColor(string $color, float $alpha): string
{
    $hex = expandHexColor($color);
    $rgb = sscanf($hex, "#%02x%02x%02x");

    if (!is_array($rgb) || count($rgb) !== 3) {
        return $color;
    }

    return sprintf('rgba(%d, %d, %d, %.3f)', $rgb[0], $rgb[1], $rgb[2], $alpha);
}

function buildCardStyle(string $color): string
{
    if (!isHexColor($color)) {
        return '';
    }

    return sprintf(
        'background:%s;border-color:%s;color:%s;',
        rgbaColor($color, 0.10),
        rgbaColor($color, 0.30),
        expandHexColor($color)
    );
}

function buildStateBoxStyle(string $color): string
{
    if (!isHexColor($color)) {
        return '';
    }

    return sprintf(
        'background:%s;color:%s;',
        rgbaColor($color, 0.10),
        expandHexColor($color)
    );
}

function buildDotStyle(string $color): string
{
    if (!isHexColor($color)) {
        return '';
    }

    return 'background:' . expandHexColor($color) . ';';
}

$aulasDados = $grupos['aulas_por_lecionar_pres']['dados'] ?? [];
$sumariosDados = $grupos['sumarios_por_publicar_pres']['dados'] ?? [];
$pucDados = $grupos['resumo_estados_puc_pres']['dados'] ?? [];
$rucDados = $grupos['resumo_estados_ruc_pres']['dados'] ?? [];
$ucsSemDocenteDados = $grupos['ucs_sem_docente']['dados'] ?? [];

$semesterOrder = buildSemesterOrder($aulasDados, $sumariosDados);
$allUoOrder = buildUoOrder($aulasDados, $sumariosDados);

$selectedSemester = trim((string) ($_GET['semester'] ?? ''));
if ($selectedSemester !== '' && !in_array($selectedSemester, $semesterOrder, true)) {
    $selectedSemester = '';
}

$selectedUo = trim((string) ($_GET['uo'] ?? ''));
if ($selectedUo !== '' && !in_array($selectedUo, $allUoOrder, true)) {
    $selectedUo = '';
}

$aulasDados = filterRows($aulasDados, $selectedSemester, $selectedUo);
$sumariosDados = filterRows($sumariosDados, $selectedSemester, $selectedUo);

$uoOrder = $selectedUo !== ''
    ? [$selectedUo]
    : buildUoOrder($aulasDados, $sumariosDados);

$defaultSemesterFilter = $selectedSemester !== '' ? $selectedSemester : 'Todos os Semestres';
$defaultUoFilter = $selectedUo !== '' ? $selectedUo : 'Todas as UOs';

$aulasPorUo = initMatrix($uoOrder, ['por_lecionar', 'nao_lecionadas', 'nao_justificadas']);
$aulasTotals = ['por_lecionar' => 0, 'nao_lecionadas' => 0, 'nao_justificadas' => 0];

foreach ($aulasDados as $row) {
    $uo = $row['uo'] ?? '';
    $count = (int) ($row['num_aulas'] ?? 0);
    $estado = (int) ($row['id_estado'] ?? 0);

    if (!isset($aulasPorUo[$uo])) {
        $aulasPorUo[$uo] = ['por_lecionar' => 0, 'nao_lecionadas' => 0, 'nao_justificadas' => 0];
    }

    if ($estado === 1) {
        $aulasPorUo[$uo]['por_lecionar'] += $count;
        $aulasTotals['por_lecionar'] += $count;
    } elseif ($estado === 3) {
        $aulasPorUo[$uo]['nao_lecionadas'] += $count;
        $aulasTotals['nao_lecionadas'] += $count;
    } elseif ($estado === 7) {
        $aulasPorUo[$uo]['nao_justificadas'] += $count;
        $aulasTotals['nao_justificadas'] += $count;
    }
}

$sumariosPorUo = initMatrix($uoOrder, ['elaborados', 'nao_elaborados']);
$sumariosTotals = ['elaborados' => 0, 'nao_elaborados' => 0];

foreach ($sumariosDados as $row) {
    $uo = $row['uo'] ?? '';
    $count = (int) ($row['num_aulas'] ?? 0);
    $estado = (int) ($row['id_estado_sumario'] ?? 0);

    if (!isset($sumariosPorUo[$uo])) {
        $sumariosPorUo[$uo] = ['elaborados' => 0, 'nao_elaborados' => 0];
    }

    if ($estado === 2) {
        $sumariosPorUo[$uo]['elaborados'] += $count;
        $sumariosTotals['elaborados'] += $count;
    } elseif ($estado === 1) {
        $sumariosPorUo[$uo]['nao_elaborados'] += $count;
        $sumariosTotals['nao_elaborados'] += $count;
    }
}

$descricaoConfig = is_array($alertsConfig['descricao'] ?? null) ? $alertsConfig['descricao'] : [];
$descricaoEstadoConfig = is_array($alertsConfig['descricao_estado'] ?? null) ? $alertsConfig['descricao_estado'] : [];
$estadoConfig = is_array($alertsConfig['estado'] ?? null) ? $alertsConfig['estado'] : [];

$aulasCardMeta = [
    'por_lecionar' => lookupConfigEntry($descricaoConfig, 'Por lecionar'),
    'nao_lecionadas' => lookupConfigEntry($descricaoConfig, 'Não lecionada'),
    'nao_justificadas' => lookupConfigEntry($descricaoConfig, 'Não Justificada'),
];

$sumariosCardMeta = [
    'elaborados' => lookupConfigEntry($descricaoEstadoConfig, 'Elaborado'),
    'nao_elaborados' => lookupConfigEntry($descricaoEstadoConfig, 'Não Elaborado'),
];

$pucStates = [];
foreach ($pucDados as $row) {
    $label = (string) ($row['estado'] ?? '');
    $configEntry = lookupConfigEntry($estadoConfig, $label);
    $configColor = (string) ($configEntry['color'] ?? '');
    $configLabel = trim((string) ($configEntry['label'] ?? ''));
    $pucStates[] = [
        'label' => $configLabel !== '' ? $configLabel : $label,
        'count' => (int) ($row['num_ucs'] ?? 0),
        'box_style' => buildStateBoxStyle($configColor),
        'dot_style' => buildDotStyle($configColor),
    ];
}

$rucStates = [];
foreach ($rucDados as $row) {
    $label = (string) ($row['estado'] ?? '');
    $configEntry = lookupConfigEntry($estadoConfig, $label);
    $configColor = (string) ($configEntry['color'] ?? '');
    $configLabel = trim((string) ($configEntry['label'] ?? ''));
    $rucStates[] = [
        'label' => $configLabel !== '' ? $configLabel : $label,
        'count' => (int) ($row['num_ucs'] ?? 0),
        'box_style' => buildStateBoxStyle($configColor),
        'dot_style' => buildDotStyle($configColor),
    ];
}

$ucsBySigla = [];
foreach ($ucsSemDocenteDados as $row) {
    $sigla = trim((string) ($row['sigla'] ?? 'ND'));
    $disciplina = trim((string) ($row['ds_discip'] ?? ''));

    if ($sigla === '') {
        $sigla = 'ND';
    }

    if (!isset($ucsBySigla[$sigla])) {
        $ucsBySigla[$sigla] = [];
    }

    if ($disciplina !== '') {
        $ucsBySigla[$sigla][] = $disciplina;
    }
}

ksort($ucsBySigla);
$totalUcsSemDocente = 0;
foreach ($ucsBySigla as $disciplinas) {
    $totalUcsSemDocente += count($disciplinas);
}

$ucsSemDocenteRule = findRangeRule(
    is_array($alertsConfig['ucs_sem_docente_count'] ?? null) ? $alertsConfig['ucs_sem_docente_count'] : [],
    $totalUcsSemDocente
);
$ucsSemDocenteTitleStyle = buildStateBoxStyle((string) ($ucsSemDocenteRule['color'] ?? ''));

$pageTitle = (string) ($presidencia['ds_grupo'] ?? 'Presidencia');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | ON.IPVC</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --surface: rgba(255, 255, 255, 0.92);
            --surface-strong: #ffffff;
            --line: #d9e1ec;
            --text: #0f2344;
            --muted: #70819c;
            --brand: #2f7f90;
            --brand-2: #2356f5;
            --green: #00944f;
            --green-soft: #eefaf3;
            --red: #ef1111;
            --red-soft: #fff0f0;
            --orange: #df5b00;
            --orange-soft: #fff5eb;
            --blue: #1f53ea;
            --blue-soft: #edf3ff;
            --amber: #b87700;
            --amber-soft: #fff9df;
            --rose: #be1e2d;
            --rose-soft: #fff2f3;
            --shadow: 0 20px 45px rgba(15, 35, 68, 0.08);
            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
            --radius-sm: 12px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            background: #eef1f4;
            overflow: hidden;
        }

        .page-shell {
            height: 100vh;
        }

        .dashboard {
            height: 100vh;
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            align-items: stretch;
        }

        .sidebar,
        .content-panel {
            min-width: 0;
        }

        .sidebar {
            height: 100vh;
            background: #f1f1f1;
            border-right: 1px solid #d6dbe3;
            padding: 22px 20px 28px;
            display: flex;
            flex-direction: column;
            gap: 18px;
            overflow: hidden;
        }

        .brand-row {
            display: flex;
            align-items: center;
            min-height: 52px;
            padding-bottom: 18px;
            border-bottom: 1px solid #d6dbe3;
        }

        .brand-mark {
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--brand);
        }

        .brand-mark span {
            color: #6a7f95;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
            border: 1px solid #cfd6e0;
            border-radius: 6px;
            padding: 10px 10px 10px 18px;
        }

        .search-box input {
            width: 100%;
            border: 0;
            outline: 0;
            font-size: 0.96rem;
            color: var(--text);
            background: transparent;
        }

        .search-button {
            width: 56px;
            height: 36px;
            border: 0;
            border-radius: 6px;
            display: grid;
            place-items: center;
            background: #f0f2f6;
            color: #5f6f87;
            cursor: pointer;
        }

        .module-list {
            display: grid;
            gap: 12px;
        }

        .module-card {
            padding: 22px 20px;
            border-radius: 5px;
            background: #fff;
            border: 1px solid #cfd6e0;
            font-size: 0.96rem;
            font-weight: 600;
            color: var(--brand);
            letter-spacing: -0.02em;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid #d6dbe3;
            padding-top: 18px;
            color: var(--muted);
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .content-panel {
            background: #fff;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .utility-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 18px;
            min-height: 74px;
            padding: 0 20px;
            border-bottom: 1px solid #d6dbe3;
            position: sticky;
            top: 0;
            z-index: 30;
            background: #fff;
        }

        .utility-actions {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .language-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 38px;
            padding: 0 12px;
            border: 1px solid #cfd6e0;
            border-radius: 6px;
            color: #8190a8;
            background: #fff;
            font-weight: 500;
        }

        .topbar {
            padding: 18px 36px 0;
            border-bottom: 1px solid #d6dbe3;
            position: sticky;
            top: 74px;
            z-index: 25;
            background: #fff;
        }

        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 12px;
            padding-bottom: 6px;
        }

        .tab {
            padding: 10px 12px;
            border-radius: 0;
            border: 1px solid transparent;
            background: transparent;
            color: #111827;
            font-size: 0.96rem;
            font-weight: 600;
        }

        .tab.active {
            border-color: #6b95ff;
            background: #fff;
            color: var(--brand-2);
        }

        .profile-pill {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0;
            background: #fff;
            color: #687a95;
            white-space: nowrap;
        }

        .avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #d7dee8;
            display: grid;
            place-items: center;
            color: #6a7a90;
            flex: 0 0 auto;
        }

        .content-body {
            padding: 28px 40px 36px;
        }

        .hero {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            padding: 18px 0 8px;
        }

        .hero-copy h1 {
            margin: 0;
            font-size: clamp(2.2rem, 3vw, 3.15rem);
            letter-spacing: -0.05em;
            line-height: 0.95;
        }

        .hero-controls {
            display: flex;
            align-items: center;
            gap: 16px;
            padding-top: 6px;
        }

        .filter-form {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .filter-dropdown {
            position: relative;
        }

        .filter-pill {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            min-width: 226px;
            height: 44px;
            padding: 0 16px;
            border: 0;
            border-radius: 10px;
            background: #f2f2f4;
            color: #111827;
            font-size: 0.96rem;
            font-weight: 600;
            gap: 16px;
            cursor: pointer;
        }

        .filter-trigger {
            width: 100%;
        }

        .filter-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            min-width: 226px;
            padding: 8px;
            border: 1px solid #d9e1ec;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 14px 30px rgba(15, 35, 68, 0.12);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-4px);
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s ease;
            z-index: 40;
        }

        .filter-dropdown.is-open .filter-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .filter-option {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #111827;
            text-align: left;
            padding: 12px 14px;
            font-size: 0.96rem;
            cursor: pointer;
        }

        .filter-option:hover {
            background: #f5f7fb;
        }

        .filter-option.is-selected {
            background: #eef2f8;
        }

        .filter-option-check {
            color: #687a95;
            flex: 0 0 auto;
        }

        .main-grid {
            display: grid;
            gap: 22px;
        }

        .panel {
            background: var(--surface-strong);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 28px 30px 30px;
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 0 18px;
            font-size: 1.18rem;
            letter-spacing: -0.04em;
        }

        .panel-title small {
            color: var(--muted);
            font-size: 0.95rem;
            font-weight: 500;
            letter-spacing: normal;
        }

        .title-icon {
            width: 28px;
            height: 28px;
            color: var(--brand);
            flex: 0 0 auto;
        }

        .stat-cards {
            display: grid;
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-cards.three {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .stat-cards.two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .stat-card {
            padding: 22px 24px;
            border-radius: 16px;
            border: 1px solid var(--line);
            text-align: center;
        }

        .stat-card strong {
            display: block;
            margin-top: 8px;
            font-size: clamp(1.95rem, 3vw, 2.35rem);
            letter-spacing: -0.05em;
        }

        .card-blue {
            background: var(--blue-soft);
            border-color: #b8cdfd;
            color: var(--blue);
        }

        .card-red {
            background: var(--red-soft);
            border-color: #ffc1c1;
            color: #cf1212;
        }

        .card-orange {
            background: var(--orange-soft);
            border-color: #ffc98f;
            color: var(--orange);
        }

        .card-green {
            background: var(--green-soft);
            border-color: #a9edc3;
            color: var(--green);
        }

        .table-shell {
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 16px 20px;
            text-align: left;
            border-bottom: 1px solid #ecf1f7;
            font-size: 0.98rem;
        }

        th {
            background: #fbfcfe;
            font-size: 1.05rem;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .col-blue {
            color: var(--blue);
        }

        .col-green {
            color: var(--green);
        }

        .col-red {
            color: var(--red);
        }

        .col-orange {
            color: var(--orange);
        }

        .uo-cell {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
        }

        .mini-grid {
            display: grid;
            gap: 22px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .state-list {
            display: grid;
            gap: 14px;
            margin-top: 16px;
        }

        .state-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 20px;
            border-radius: 16px;
            border: 1px solid transparent;
            font-size: 1rem;
        }

        .state-item strong {
            font-size: 1.1rem;
        }

        .state-label {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }

        .dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            flex: 0 0 auto;
        }

        .dot-red { background: var(--red); }
        .dot-blue { background: #3678f3; }
        .dot-amber { background: #f1b304; }
        .dot-rose { background: #ff6873; }
        .dot-green { background: #10c95b; }
        .dot-slate { background: #94a3b8; }

        .state-red { background: var(--red-soft); color: #9f1b1b; }
        .state-blue { background: var(--blue-soft); color: var(--blue); }
        .state-amber { background: var(--amber-soft); color: #a96a00; }
        .state-rose { background: var(--rose-soft); color: var(--rose); }
        .state-green { background: var(--green-soft); color: #006d3b; }
        .state-slate { background: #f3f6fa; color: #54657d; }

        .ucs-shell {
            max-height: 520px;
            overflow: auto;
            padding-right: 10px;
        }

        .ucs-shell::-webkit-scrollbar {
            width: 10px;
        }

        .ucs-shell::-webkit-scrollbar-track {
            background: #eef3f8;
            border-radius: 999px;
        }

        .ucs-shell::-webkit-scrollbar-thumb {
            background: #3b4f69;
            border-radius: 999px;
        }

        .ucs-group + .ucs-group {
            margin-top: 18px;
        }

        .ucs-group-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            color: var(--muted);
            font-size: 1rem;
        }

        .sigla-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 58px;
            padding: 6px 10px;
            border-radius: 10px;
            background: #edf1f7;
            color: #27415f;
            font-weight: 700;
        }

        .uc-item {
            padding: 14px 16px;
            border-left: 3px solid #ffd4d4;
            border-radius: 0 12px 12px 0;
            background: #fff6f6;
        }

        .muted {
            color: var(--muted);
        }

        .icon-house,
        .icon-user {
            width: 20px;
            height: 20px;
            color: #93a0b5;
            flex: 0 0 auto;
        }

        @media (max-width: 1280px) {
            body {
                overflow: auto;
            }

            .page-shell,
            .dashboard {
                height: auto;
            }

            .dashboard {
                grid-template-columns: 1fr;
            }

            .sidebar {
                min-height: auto;
                height: auto;
                border-right: 0;
                border-bottom: 1px solid #d6dbe3;
                overflow: visible;
            }

            .content-panel {
                height: auto;
                overflow: visible;
            }
        }

        @media (max-width: 900px) {
            .stat-cards.three,
            .stat-cards.two,
            .mini-grid {
                grid-template-columns: 1fr;
            }

            .utility-bar,
            .topbar,
            .hero {
                flex-direction: column;
                align-items: stretch;
            }

            .topbar {
                top: 74px;
            }

            .topbar,
            .content-body {
                padding-left: 20px;
                padding-right: 20px;
            }

            .hero-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-pill {
                min-width: 0;
                width: 100%;
            }

            .filter-menu {
                min-width: 0;
                width: 100%;
            }

            th,
            td {
                padding: 14px 12px;
                font-size: 0.95rem;
            }

            .panel {
                padding: 22px 18px;
            }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <div class="dashboard">
            <aside class="sidebar">
                <div class="brand-row">
                    <div class="brand-mark">ON<span>.IPVC</span></div>
                </div>

                <label class="search-box" for="module-search">
                    <input id="module-search" type="text" placeholder="Pesquise pelo m&oacute;dulo ..." aria-label="Pesquisar m&oacute;dulo">
                    <button class="search-button" type="button" aria-label="Pesquisar">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="M20 20l-3.5-3.5"></path>
                        </svg>
                    </button>
                </label>

                <div class="module-list">
                    <div class="module-card">Atividade Letiva</div>
                    <div class="module-card">Institucional</div>
                    <div class="module-card">Servi&ccedil;os</div>
                    <div class="module-card">Sistema de Gest&atilde;o</div>
                    <div class="module-card">Utilidades</div>
                </div>

            </aside>

            <main class="content-panel">
                <div class="utility-bar">
                    <div class="utility-actions">
                        <button class="language-pill" type="button" aria-label="Idioma">
                            <span>EN</span>
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M6 9l6 6 6-6"></path>
                            </svg>
                        </button>

                        <div class="profile-pill">
                            <span>Lu&iacute;s Felipe da Cunha Flores</span>
                            <span class="avatar" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21a8 8 0 0 0-16 0"></path>
                                    <circle cx="12" cy="8" r="4"></circle>
                                </svg>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="topbar">
                    <nav class="tabs" aria-label="Perfis">
                        <div class="tab active">Presid&ecirc;ncia</div>
                        <div class="tab">Coordenador de Curso</div>
                        <div class="tab">Docente</div>
                        <div class="tab">Dire&ccedil;&atilde;o UO</div>
                        <div class="tab">Pessoal</div>
                        <div class="tab">Gest&atilde;o Documental</div>
                        <div class="tab">GAC</div>
                    </nav>
                </div>

                <div class="content-body">
                    <div class="hero">
                        <div class="hero-copy">
                            <h1><?= e($pageTitle) ?></h1>
                        </div>
                        <div class="hero-controls" aria-label="Filtros">
                            <form class="filter-form" method="get">
                                <input type="hidden" name="semester" value="<?= e($selectedSemester) ?>">
                                <input type="hidden" name="uo" value="<?= e($selectedUo) ?>">

                                <div class="filter-dropdown" data-filter-dropdown>
                                    <button class="filter-pill filter-trigger" type="button" aria-haspopup="true" aria-expanded="false">
                                        <span><?= e($defaultSemesterFilter) ?></span>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M6 9l6 6 6-6"></path>
                                        </svg>
                                    </button>
                                    <div class="filter-menu" role="menu" aria-label="Filtro de semestre">
                                        <button class="filter-option<?= $selectedSemester === '' ? ' is-selected' : '' ?>" type="button" data-filter-name="semester" data-filter-value="">
                                            <span>Todos os Semestres</span>
                                            <?php if ($selectedSemester === ''): ?>
                                                <svg class="filter-option-check" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M20 6L9 17l-5-5"></path>
                                                </svg>
                                            <?php endif; ?>
                                        </button>
                                        <?php foreach ($semesterOrder as $semester): ?>
                                            <button class="filter-option<?= $selectedSemester === $semester ? ' is-selected' : '' ?>" type="button" data-filter-name="semester" data-filter-value="<?= e($semester) ?>">
                                                <span><?= e($semester) ?></span>
                                                <?php if ($selectedSemester === $semester): ?>
                                                    <svg class="filter-option-check" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M20 6L9 17l-5-5"></path>
                                                    </svg>
                                                <?php endif; ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="filter-dropdown" data-filter-dropdown>
                                    <button class="filter-pill filter-trigger" type="button" aria-haspopup="true" aria-expanded="false">
                                        <span><?= e($defaultUoFilter) ?></span>
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M6 9l6 6 6-6"></path>
                                        </svg>
                                    </button>
                                    <div class="filter-menu" role="menu" aria-label="Filtro de unidade orgânica">
                                        <button class="filter-option<?= $selectedUo === '' ? ' is-selected' : '' ?>" type="button" data-filter-name="uo" data-filter-value="">
                                            <span>Todas as UOs</span>
                                            <?php if ($selectedUo === ''): ?>
                                                <svg class="filter-option-check" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M20 6L9 17l-5-5"></path>
                                                </svg>
                                            <?php endif; ?>
                                        </button>
                                        <?php foreach ($allUoOrder as $uo): ?>
                                            <button class="filter-option<?= $selectedUo === $uo ? ' is-selected' : '' ?>" type="button" data-filter-name="uo" data-filter-value="<?= e($uo) ?>">
                                                <span><?= e($uo) ?></span>
                                                <?php if ($selectedUo === $uo): ?>
                                                    <svg class="filter-option-check" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M20 6L9 17l-5-5"></path>
                                                    </svg>
                                                <?php endif; ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="main-grid">
                    <section class="panel">
                        <h2 class="panel-title">
                            <svg class="title-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                <path d="M6.5 2H20v15H6.5A2.5 2.5 0 0 0 4 19.5V4.5A2.5 2.5 0 0 1 6.5 2z"></path>
                            </svg>
                            Aulas por Lecionar
                        </h2>

                        <div class="stat-cards three">
                            <div class="stat-card card-blue"<?= ($style = buildCardStyle((string) ($aulasCardMeta['por_lecionar']['color'] ?? ''))) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                <span>Por Lecionar</span>
                                <strong><?= number_format($aulasTotals['por_lecionar'], 0, ',', '.') ?></strong>
                            </div>
                            <div class="stat-card card-red"<?= ($style = buildCardStyle((string) ($aulasCardMeta['nao_lecionadas']['color'] ?? ''))) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                <span>N&atilde;o Lecionadas</span>
                                <strong><?= number_format($aulasTotals['nao_lecionadas'], 0, ',', '.') ?></strong>
                            </div>
                            <div class="stat-card card-orange"<?= ($style = buildCardStyle((string) ($aulasCardMeta['nao_justificadas']['color'] ?? ''))) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                <span>N&atilde;o Justificadas</span>
                                <strong><?= number_format($aulasTotals['nao_justificadas'], 0, ',', '.') ?></strong>
                            </div>
                        </div>

                        <div class="table-shell">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Unidade Org&acirc;nica</th>
                                        <th class="col-blue">Por Lecionar</th>
                                        <th class="col-red">N&atilde;o Lecionadas</th>
                                        <th class="col-orange">N&atilde;o Justificadas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($uoOrder as $uo): ?>
                                        <tr>
                                            <td>
                                                <div class="uo-cell">
                                                    <svg class="icon-house" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M3 21h18"></path>
                                                        <path d="M5 21V8l7-5 7 5v13"></path>
                                                        <path d="M9 21v-6h6v6"></path>
                                                        <path d="M9 9h.01"></path>
                                                        <path d="M15 9h.01"></path>
                                                    </svg>
                                                    <?= e($uo) ?>
                                                </div>
                                            </td>
                                            <td class="col-blue"><?= number_format($aulasPorUo[$uo]['por_lecionar'] ?? 0, 0, ',', '.') ?></td>
                                            <td class="col-red"><?= ($aulasPorUo[$uo]['nao_lecionadas'] ?? 0) > 0 ? number_format($aulasPorUo[$uo]['nao_lecionadas'], 0, ',', '.') : '&mdash;' ?></td>
                                            <td class="col-orange"><?= ($aulasPorUo[$uo]['nao_justificadas'] ?? 0) > 0 ? number_format($aulasPorUo[$uo]['nao_justificadas'], 0, ',', '.') : '&mdash;' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="panel">
                        <h2 class="panel-title">
                            <svg class="title-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <path d="M14 2v6h6"></path>
                                <path d="M8 13h8"></path>
                                <path d="M8 17h8"></path>
                            </svg>
                            Sum&aacute;rios por Publicar
                        </h2>

                        <div class="stat-cards two">
                            <div class="stat-card card-green"<?= ($style = buildCardStyle((string) ($sumariosCardMeta['elaborados']['color'] ?? ''))) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                <span>Elaborados</span>
                                <strong><?= number_format($sumariosTotals['elaborados'], 0, ',', '.') ?></strong>
                            </div>
                            <div class="stat-card card-red"<?= ($style = buildCardStyle((string) ($sumariosCardMeta['nao_elaborados']['color'] ?? ''))) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                <span>N&atilde;o Elaborados</span>
                                <strong><?= number_format($sumariosTotals['nao_elaborados'], 0, ',', '.') ?></strong>
                            </div>
                        </div>

                        <div class="table-shell">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Unidade Org&acirc;nica</th>
                                        <th class="col-green">Elaborados</th>
                                        <th class="col-red">N&atilde;o Elaborados</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($uoOrder as $uo): ?>
                                        <tr>
                                            <td>
                                                <div class="uo-cell">
                                                    <svg class="icon-house" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M3 21h18"></path>
                                                        <path d="M5 21V8l7-5 7 5v13"></path>
                                                        <path d="M9 21v-6h6v6"></path>
                                                        <path d="M9 9h.01"></path>
                                                        <path d="M15 9h.01"></path>
                                                    </svg>
                                                    <?= e($uo) ?>
                                                </div>
                                            </td>
                                            <td class="col-green"><?= number_format($sumariosPorUo[$uo]['elaborados'] ?? 0, 0, ',', '.') ?></td>
                                            <td class="col-red"><?= number_format($sumariosPorUo[$uo]['nao_elaborados'] ?? 0, 0, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <div class="mini-grid">
                        <section class="panel">
                            <h2 class="panel-title">
                                <svg class="title-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M9 11l3 3L22 4"></path>
                                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                                </svg>
                                Resumo Estados PUCs
                            </h2>

                            <div class="state-list">
                                <?php foreach ($pucStates as $state): ?>
                                    <div class="state-item<?= $state['box_style'] === '' ? ' state-slate' : '' ?>"<?= $state['box_style'] !== '' ? ' style="' . e($state['box_style']) . '"' : '' ?>>
                                        <span class="state-label">
                                            <span class="dot<?= $state['dot_style'] === '' ? ' dot-slate' : '' ?>"<?= $state['dot_style'] !== '' ? ' style="' . e($state['dot_style']) . '"' : '' ?>></span>
                                            <?= e($state['label']) ?>
                                        </span>
                                        <strong><?= number_format($state['count'], 0, ',', '.') ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <section class="panel">
                            <h2 class="panel-title">
                                <svg class="title-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M9 11l3 3L22 4"></path>
                                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                                </svg>
                                Resumo Estados RUCs
                            </h2>

                            <div class="state-list">
                                <?php foreach ($rucStates as $state): ?>
                                    <div class="state-item<?= $state['box_style'] === '' ? ' state-slate' : '' ?>"<?= $state['box_style'] !== '' ? ' style="' . e($state['box_style']) . '"' : '' ?>>
                                        <span class="state-label">
                                            <span class="dot<?= $state['dot_style'] === '' ? ' dot-slate' : '' ?>"<?= $state['dot_style'] !== '' ? ' style="' . e($state['dot_style']) . '"' : '' ?>></span>
                                            <?= e($state['label']) ?>
                                        </span>
                                        <strong><?= number_format($state['count'], 0, ',', '.') ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    </div>

                    <section class="panel">
                        <h2 class="panel-title">
                            <svg class="title-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M12 8v4"></path>
                                <path d="M12 16h.01"></path>
                            </svg>
                            UCs sem docente <small<?= $ucsSemDocenteTitleStyle !== '' ? ' style="' . e($ucsSemDocenteTitleStyle) . 'padding:4px 8px;border-radius:999px;margin-left:6px;display:inline-block;"' : '' ?>>(<?= number_format($totalUcsSemDocente, 0, ',', '.') ?>)</small>
                        </h2>

                        <div class="ucs-shell">
                            <?php foreach ($ucsBySigla as $sigla => $disciplinas): ?>
                                <div class="ucs-group">
                                    <div class="ucs-group-header">
                                        <span class="sigla-tag"><?= e($sigla) ?></span>
                                        <span><?= count($disciplinas) ?> <?= count($disciplinas) === 1 ? 'UC' : 'UCs' ?></span>
                                    </div>
                                    <?php foreach ($disciplinas as $disciplina): ?>
                                        <div class="uc-item"><?= e($disciplina) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const dropdowns = document.querySelectorAll('[data-filter-dropdown]');

            function closeAllDropdowns() {
                dropdowns.forEach(function (dropdown) {
                    dropdown.classList.remove('is-open');
                    const trigger = dropdown.querySelector('.filter-trigger');
                    if (trigger) {
                        trigger.setAttribute('aria-expanded', 'false');
                    }
                });
            }

            dropdowns.forEach(function (dropdown) {
                const trigger = dropdown.querySelector('.filter-trigger');
                const options = dropdown.querySelectorAll('.filter-option');

                if (!trigger) {
                    return;
                }

                trigger.addEventListener('click', function (event) {
                    event.stopPropagation();
                    const isOpen = dropdown.classList.contains('is-open');
                    closeAllDropdowns();

                    if (!isOpen) {
                        dropdown.classList.add('is-open');
                        trigger.setAttribute('aria-expanded', 'true');
                    }
                });

                options.forEach(function (option) {
                    option.addEventListener('click', function () {
                        const form = dropdown.closest('form');
                        const input = form ? form.querySelector('input[name="' + this.dataset.filterName + '"]') : null;

                        if (!form || !input) {
                            return;
                        }

                        input.value = this.dataset.filterValue;
                        form.submit();
                    });
                });
            });

            document.addEventListener('click', function (event) {
                if (!event.target.closest('[data-filter-dropdown]')) {
                    closeAllDropdowns();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeAllDropdowns();
                }
            });
        });
    </script>
</body>
</html>
