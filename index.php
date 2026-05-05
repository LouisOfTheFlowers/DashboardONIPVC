<?php
declare(strict_types=1);

const ALERTS_CONFIG_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'alertasconfig';
$availablePages = [
    'presidencia' => [
        'label' => 'Presidência',
        'json' => 'alertsPresidencia.json',
        'config' => 'alertsPresidenciaconfig.json',
        'payload_key' => 'presidencia',
    ],
    'cc' => [
        'label' => 'Coordenador de Curso',
        'json' => 'alertsCC.json',
        'config' => 'alertsCCconfig.json',
        'payload_key' => 'coord_curso',
    ],
    'docente' => [
        'label' => 'Docente',
        'json' => 'alertsDocente.json',
        'config' => 'alertsDocenteconfig.json',
        'payload_key' => 'docente',
    ],
];

$selectedPage = trim((string) ($_GET['profile'] ?? 'presidencia'));
if (!isset($availablePages[$selectedPage])) {
    $selectedPage = 'presidencia';
}

$pageDefinition = $availablePages[$selectedPage];
$jsonPath = __DIR__ . DIRECTORY_SEPARATOR . 'alertas' . DIRECTORY_SEPARATOR . $pageDefinition['json'];
$configPath = ALERTS_CONFIG_DIR . DIRECTORY_SEPARATOR . $pageDefinition['config'];

if (!is_file($jsonPath)) {
    http_response_code(500);
    echo 'Missing ' . e((string) $pageDefinition['json']);
    exit;
}

$payload = loadJsonFile($jsonPath, 'alerts JSON');

$dashboardProfiles = findDashboardProfiles($payload);
$preferredProfileKey = (string) ($pageDefinition['payload_key'] ?? '');
$selectedProfileKey = isset($dashboardProfiles[$preferredProfileKey])
    ? $preferredProfileKey
    : (string) array_key_first($dashboardProfiles);

if ($selectedProfileKey === '') {
    http_response_code(500);
    echo 'Selected JSON does not contain a dashboard profile with grupos';
    exit;
}

$dashboardProfile = $dashboardProfiles[$selectedProfileKey];
$grupos = is_array($dashboardProfile['grupos'] ?? null) ? $dashboardProfile['grupos'] : [];

$alertsConfig = [];
if (is_file($configPath)) {
    $alertsConfig = loadJsonFile($configPath, 'config JSON');
}

function e(string $value): string
{
    return htmlspecialchars(cleanText($value), ENT_QUOTES, 'UTF-8');
}

function loadJsonFile(string $path, string $label): array
{
    try {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Could not read file');
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException | RuntimeException $exception) {
        http_response_code(500);
        echo 'Invalid ' . e($label) . ': ' . e($exception->getMessage());
        exit;
    }

    return is_array($decoded) ? $decoded : [];
}

function findDashboardProfiles(array $payload): array
{
    $profiles = [];

    foreach ($payload as $key => $value) {
        if (is_array($value) && is_array($value['grupos'] ?? null)) {
            $profiles[(string) $key] = $value;
        }
    }

    return $profiles;
}

function findGroup(array $groups, array $needles): array
{
    foreach ($needles as $needle) {
        if (isset($groups[$needle]) && is_array($groups[$needle])) {
            return $groups[$needle];
        }
    }

    foreach ($groups as $key => $group) {
        if (!is_array($group)) {
            continue;
        }

        $normalizedKey = normalizeState((string) $key);

        foreach ($needles as $needle) {
            if (str_contains($normalizedKey, normalizeState($needle))) {
                return $group;
            }
        }
    }

    return [];
}

function buildUoOrder(array ...$datasets): array
{
    return buildFieldOrder('uo', ...$datasets);
}

function buildSemesterOrder(array ...$datasets): array
{
    return buildFieldOrder('semestre', ...$datasets);
}

function buildFieldOrder(string $field, array ...$datasets): array
{
    if ($field === '') {
        return [];
    }

    $uoOrder = [];
    $lookup = [];

    foreach ($datasets as $dataset) {
        foreach ($dataset as $row) {
            $value = $row[$field] ?? null;
            if ((is_string($value) || is_int($value)) && (string) $value !== '' && !isset($lookup[(string) $value])) {
                $uoOrder[] = (string) $value;
                $lookup[(string) $value] = true;
            }
        }
    }

    sort($uoOrder, SORT_NATURAL | SORT_FLAG_CASE);

    return $uoOrder;
}

function buildFieldOrderFromPanelDatasets(array $panelDatasets, string $fieldKey): array
{
    $values = [];
    $lookup = [];

    foreach ($panelDatasets as $panelDataset) {
        $field = (string) ($panelDataset[$fieldKey] ?? '');
        $rows = is_array($panelDataset['rows'] ?? null) ? $panelDataset['rows'] : [];

        if ($field === '') {
            continue;
        }

        foreach ($rows as $row) {
            $value = $row[$field] ?? null;
            if ((is_string($value) || is_int($value)) && (string) $value !== '' && !isset($lookup[(string) $value])) {
                $values[] = (string) $value;
                $lookup[(string) $value] = true;
            }
        }
    }

    sort($values, SORT_NATURAL | SORT_FLAG_CASE);

    return $values;
}

function firstNonEmptyPanelField(array $panelDatasets, string $fieldKey): string
{
    foreach ($panelDatasets as $panelDataset) {
        $field = trim((string) ($panelDataset[$fieldKey] ?? ''));
        if ($field !== '') {
            return $field;
        }
    }

    return '';
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
    $value = cleanText($value);

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

function filterRows(
    array $dataset,
    string $semesterFilter = '',
    string $uoFilter = '',
    string $semesterField = 'semestre',
    string $uoField = 'uo'
): array
{
    return array_values(array_filter(
        $dataset,
        static function (array $row) use ($semesterFilter, $uoFilter, $semesterField, $uoField): bool {
            if ($semesterFilter !== '' && $semesterField !== '' && (string) ($row[$semesterField] ?? '') !== $semesterFilter) {
                return false;
            }

            if ($uoFilter !== '' && $uoField !== '' && (string) ($row[$uoField] ?? '') !== $uoFilter) {
                return false;
            }

            return true;
        }
    ));
}

function titleWithSemester(string $title, string $semester): string
{
    return $semester !== '' ? $title . ' - ' . $semester : $title;
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

function buildTextColorStyle(string $color): string
{
    if (!isHexColor($color)) {
        return '';
    }

    return 'color:' . expandHexColor($color) . ';';
}

function resolveColor(array $config, string $color, string $paletteKey = 'card_colors'): string
{
    if ($color === '') {
        return '';
    }

    if (isHexColor($color)) {
        return $color;
    }

    $palette = is_array($config[$paletteKey] ?? null) ? $config[$paletteKey] : [];
    $entry = $palette[$color] ?? null;

    if (is_string($entry)) {
        return $entry;
    }

    if (is_array($entry)) {
        return (string) ($entry['color'] ?? '');
    }

    $fallbackPalette = [
        'success' => '#22c55e',
        'warning' => '#eab308',
        'critical' => '#ef4444',
        'danger' => '#dc2626',
        'info' => '#2563eb',
    ];

    if (isset($fallbackPalette[$color])) {
        return $fallbackPalette[$color];
    }

    return $color;
}

function resolveSchoolColor(array $schoolColors, string $school): string
{
    $entry = lookupConfigEntry($schoolColors, $school);
    if ($entry !== []) {
        return (string) ($entry['color'] ?? '');
    }

    $color = $schoolColors[$school] ?? '';

    return is_string($color) ? $color : '';
}

function hasFieldInRows(array $rows, string $field): bool
{
    if ($field === '') {
        return false;
    }

    foreach ($rows as $row) {
        if (is_array($row) && array_key_exists($field, $row)) {
            return true;
        }
    }

    return false;
}

function selectFirstExistingField(array $rows, array $candidates): string
{
    foreach ($candidates as $candidate) {
        if (hasFieldInRows($rows, $candidate)) {
            return $candidate;
        }
    }

    return '';
}

function humanizeFieldLabel(string $field): string
{
    $field = cleanText($field);

    $map = [
        'uo' => 'Unidade Orgânica',
        'sigla_curso' => 'Curso',
        'semestre' => 'Semestre',
        'nome_uc' => 'UC',
        'nome_curso' => 'Curso',
        'hor_nome_disciplina' => 'Disciplina',
        'hor_nome_turno' => 'Turno',
        'data_hora_ini' => 'Data Início',
        'data_inicio_definitiva' => 'Data Substituição',
        'cd_letivo' => 'Ano Letivo',
        'num_aulas' => 'Aulas',
        'num_ucs' => 'UCs',
        'qnt_estagios_nao_terminados' => 'Estágios Não Terminados',
        'descricao' => 'Estado',
        'descricao_estado' => 'Estado',
        'estado' => 'Estado',
        'regente' => 'Regência',
        'sigla' => 'Sigla',
        'ds_discip' => 'Disciplina',
    ];

    if (isset($map[$field])) {
        return $map[$field];
    }

    return ucwords(str_replace('_', ' ', $field));
}

function defaultScopeOptionLabel(string $field): string
{
    return match ($field) {
        'uo' => 'Todas as Unidades Orgânicas',
        'sigla_curso' => 'Todos os Cursos',
        default => 'Todos',
    };
}

function detectPanelIcon(string $groupKey): string
{
    $normalized = normalizeState($groupKey);

    if (str_contains($normalized, 'sumario') || str_contains($normalized, 'puc') || str_contains($normalized, 'ruc')) {
        return 'document';
    }

    return 'chart';
}

function uniqueRowValues(array $rows, string $field): array
{
    $values = [];
    $lookup = [];

    foreach ($rows as $row) {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value === '' || isset($lookup[$value])) {
            continue;
        }

        $values[] = $value;
        $lookup[$value] = true;
    }

    return $values;
}

function buildAutomaticCardPanelConfigs(array $groups): array
{
    $configs = [];

    foreach ($groups as $groupKey => $group) {
        if (!is_array($group)) {
            continue;
        }

        $rows = is_array($group['dados'] ?? null) ? $group['dados'] : [];
        if ($rows === [] || !isset($rows[0]) || !is_array($rows[0])) {
            continue;
        }

        $normalizedKey = normalizeState((string) $groupKey);
        if (str_contains($normalizedKey, 'resumo_estados_')) {
            continue;
        }

        $countField = selectFirstExistingField($rows, ['num_aulas', 'num_ucs', 'count', 'total']);
        $stateField = selectFirstExistingField($rows, ['descricao', 'descricao_estado', 'estado']);

        if ($countField === '' || $stateField === '') {
            continue;
        }

        $items = [];
        foreach (uniqueRowValues($rows, $stateField) as $value) {
            $items[] = [
                'key' => normalizeState($value),
                'label' => $value,
                'source_label' => $value,
                'empty_display' => 'dash',
            ];
        }

        if ($items === []) {
            continue;
        }

        $groupField = selectFirstExistingField($rows, ['uo', 'sigla_curso']);
        $semesterField = selectFirstExistingField($rows, ['semestre']);

        $configs[] = [
            'group' => (string) $groupKey,
            'title' => (string) ($group['ds'] ?? $groupKey),
            'icon' => detectPanelIcon((string) $groupKey),
            'config_group' => $stateField,
            'state_field' => $stateField,
            'count_field' => $countField,
            'semester_field' => $semesterField,
            'uo_field' => $groupField,
            'group_label' => humanizeFieldLabel($groupField),
            'show_table' => $groupField !== '',
            'items' => $items,
        ];
    }

    return $configs;
}

function buildProfileUrl(string $profile, array $params = []): string
{
    $query = array_filter(
        array_merge(['profile' => $profile], $params),
        static fn (mixed $value): bool => $value !== null && $value !== ''
    );

    return '?' . http_build_query($query);
}

function buildStateItems(array $rows, array $stateConfig, string $stateField = 'estado', string $countField = 'num_ucs'): array
{
    $counts = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $label = trim((string) ($row[$stateField] ?? ''));
        if ($label === '') {
            continue;
        }

        $increment = hasFieldInRows([$row], $countField) ? (int) ($row[$countField] ?? 0) : 1;
        $counts[$label] = ($counts[$label] ?? 0) + $increment;
    }

    $items = [];
    foreach ($counts as $label => $count) {
        $configEntry = lookupConfigEntry($stateConfig, $label);
        $configColor = (string) ($configEntry['color'] ?? '');
        $configLabel = trim((string) ($configEntry['label'] ?? ''));
        $items[] = [
            'label' => $configLabel !== '' ? $configLabel : $label,
            'count' => $count,
            'box_style' => buildStateBoxStyle($configColor),
            'dot_style' => buildDotStyle($configColor),
        ];
    }

    return $items;
}

function buildColumnsFromRows(array $rows): array
{
    $columns = [];
    $lookup = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        foreach (array_keys($row) as $column) {
            $column = (string) $column;
            if (isset($lookup[$column])) {
                continue;
            }

            $columns[] = $column;
            $lookup[$column] = true;
        }
    }

    return $columns;
}

function formatTableCellValue(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    if (is_bool($value)) {
        return $value ? 'Sim' : 'Não';
    }

    return (string) $value;
}

function cleanText(string $value): string
{
    if ($value === '') {
        return '';
    }

    if (preg_match('/[ÃÂâ]/u', $value) === 1) {
        $decoded = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }
    }

    return $value;
}

function sortSemesterValues(array $values): array
{
    usort(
        $values,
        static function (string $left, string $right): int {
            $order = ['S1' => 1, 'S2' => 2];
            $leftRank = $order[$left] ?? 99;
            $rightRank = $order[$right] ?? 99;

            if ($leftRank === $rightRank) {
                return strnatcasecmp($left, $right);
            }

            return $leftRank <=> $rightRank;
        }
    );

    return array_values(array_unique($values));
}

function groupDataRows(array $groups, array $needles, string $selectedSemester = ''): array
{
    $group = findGroup($groups, $needles);
    $rows = is_array($group['dados'] ?? null) ? $group['dados'] : [];

    if ($selectedSemester !== '' && hasFieldInRows($rows, 'semestre')) {
        $rows = filterRows($rows, $selectedSemester, '', 'semestre', '');
    }

    return $rows;
}

function groupLabel(array $groups, array $needles, string $fallback): string
{
    $group = findGroup($groups, $needles);

    return (string) ($group['ds'] ?? $fallback);
}

function sumStateCount(array $rows, string $stateField, string $stateLabel, string $countField): int
{
    $total = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if (normalizeState((string) ($row[$stateField] ?? '')) !== normalizeState($stateLabel)) {
            continue;
        }

        $total += (int) ($row[$countField] ?? 0);
    }

    return $total;
}

function buildSemesterMetricCards(
    array $rows,
    array $configGroup,
    string $stateField,
    string $countField,
    string $unitLabel
): array {
    $cards = [];
    $semesters = sortSemesterValues(uniqueRowValues($rows, 'semestre'));
    $stateOrder = array_keys($configGroup);

    if ($stateOrder === []) {
        $stateOrder = uniqueRowValues($rows, $stateField);
    }

    foreach ($stateOrder as $stateLabel) {
        foreach ($semesters as $semester) {
            $semesterRows = filterRows($rows, $semester, '', 'semestre', '');
            $count = sumStateCount($semesterRows, $stateField, (string) $stateLabel, $countField);
            if ($count <= 0) {
                continue;
            }

            $configEntry = lookupConfigEntry($configGroup, (string) $stateLabel);
            $cards[] = [
                'semester' => $semester,
                'state' => (string) $stateLabel,
                'count' => $count,
                'unit' => $unitLabel,
                'color' => (string) ($configEntry['color'] ?? ''),
            ];
        }
    }

    return $cards;
}

function buildTotalMetricCards(
    array $rows,
    array $configGroup,
    string $stateField,
    string $countField,
    array $labelMap = [],
    bool $includeZeros = true
): array {
    $cards = [];
    $stateOrder = array_keys($configGroup);
    $normalizedLabelMap = [];

    foreach ($labelMap as $key => $label) {
        $normalizedLabelMap[normalizeState((string) $key)] = $label;
    }

    if ($stateOrder === []) {
        $stateOrder = uniqueRowValues($rows, $stateField);
    }

    foreach ($stateOrder as $stateLabel) {
        $count = sumStateCount($rows, $stateField, (string) $stateLabel, $countField);
        if (!$includeZeros && $count <= 0) {
            continue;
        }

        $configEntry = lookupConfigEntry($configGroup, (string) $stateLabel);
        $cards[] = [
            'label' => $normalizedLabelMap[normalizeState((string) $stateLabel)] ?? (string) $stateLabel,
            'count' => $count,
            'color' => (string) ($configEntry['color'] ?? ''),
        ];
    }

    return $cards;
}

function buildStateSummaryCards(
    array $rows,
    array $stateConfig,
    string $stateField = 'estado',
    string $countField = 'num_ucs'
): array {
    $items = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $state = (string) ($row[$stateField] ?? '');
        if ($state === '') {
            continue;
        }

        $configEntry = lookupConfigEntry($stateConfig, $state);
        $items[] = [
            'label' => $state,
            'count' => (int) ($row[$countField] ?? 0),
            'color' => (string) ($configEntry['color'] ?? ''),
        ];
    }

    return $items;
}

function buildEntryCards(array $rows, array $stateConfig, bool $showCourseCode = true): array
{
    $items = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $state = (string) ($row['estado'] ?? '');
        $configEntry = lookupConfigEntry($stateConfig, $state);
        $courseBits = [];

        if ($showCourseCode) {
            $sigla = trim((string) ($row['sigla_curso'] ?? ''));
            if ($sigla !== '') {
                $courseBits[] = $sigla;
            }
        }

        $courseName = trim((string) ($row['nome_curso'] ?? ''));
        if ($courseName !== '') {
            $courseBits[] = $courseName;
        }

        $items[] = [
            'title' => (string) ($row['nome_uc'] ?? ''),
            'subtitle' => implode(' - ', $courseBits),
            'state' => $state,
            'color' => (string) ($configEntry['color'] ?? ''),
        ];
    }

    return $items;
}

function formatDateValue(string $value): string
{
    if ($value === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($value);
        return $date->format('d/m/Y');
    } catch (Exception) {
        return $value;
    }
}

function buildReplacementCards(array $rows): array
{
    $items = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $items[] = [
            'title' => (string) ($row['hor_nome_disciplina'] ?? ''),
            'turno' => (string) ($row['hor_nome_turno'] ?? ''),
            'ano_letivo' => (string) ($row['cd_letivo'] ?? ''),
            'data_aula' => formatDateValue((string) ($row['data_hora_ini'] ?? '')),
            'data_definitiva' => formatDateValue((string) ($row['data_inicio_definitiva'] ?? '')),
        ];
    }

    return $items;
}

function buildStageMetric(array $group, array $config): array
{
    $data = $group['dados'] ?? null;
    if (!is_array($data) || isset($data[0])) {
        return [];
    }

    foreach ($data as $field => $value) {
        if (!is_int($value) && !is_float($value)) {
            continue;
        }

        $rule = findRangeRule(is_array($config[$field] ?? null) ? $config[$field] : [], (int) $value);
        return [
            'label' => humanizeFieldLabel((string) $field),
            'value' => (int) $value,
            'color' => (string) ($rule['color'] ?? ''),
        ];
    }

    return [];
}

function buildStateTotalsFromEntries(array $rows, array $stateConfig): array
{
    $counts = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $state = trim((string) ($row['estado'] ?? ''));
        if ($state === '') {
            continue;
        }

        $counts[$state] = ($counts[$state] ?? 0) + 1;
    }

    $items = [];
    foreach ($counts as $state => $count) {
        $configEntry = lookupConfigEntry($stateConfig, $state);
        $items[] = [
            'label' => $state,
            'count' => $count,
            'color' => (string) ($configEntry['color'] ?? ''),
        ];
    }

    return $items;
}

function buildDetailTablePanels(
    array $groups,
    array $skipGroupKeys,
    string $selectedSemester,
    string $selectedUo,
    string $scopeField
): array {
    $panels = [];
    $skipLookup = array_fill_keys($skipGroupKeys, true);

    foreach ($groups as $groupKey => $group) {
        if (isset($skipLookup[(string) $groupKey]) || !is_array($group)) {
            continue;
        }

        $rows = is_array($group['dados'] ?? null) ? $group['dados'] : null;
        if ($rows === null || $rows === [] || (!isset($rows[0]) || !is_array($rows[0]))) {
            continue;
        }

        $semesterField = selectFirstExistingField($rows, ['semestre']);
        $panelScopeField = $scopeField !== '' && hasFieldInRows($rows, $scopeField) ? $scopeField : '';
        $filteredRows = filterRows($rows, $selectedSemester, $selectedUo, $semesterField, $panelScopeField);
        $columns = buildColumnsFromRows($filteredRows !== [] ? $filteredRows : $rows);

        $panels[] = [
            'title' => (string) ($group['ds'] ?? $groupKey),
            'rows' => $filteredRows,
            'columns' => $columns,
        ];
    }

    return $panels;
}

function buildMetricOnlyPanels(array $groups, array $config): array
{
    $panels = [];

    foreach ($groups as $groupKey => $group) {
        if (!is_array($group) || !is_array($group['dados'] ?? null)) {
            continue;
        }

        $data = $group['dados'];
        if ($data === [] || isset($data[0])) {
            continue;
        }

        $items = [];
        foreach ($data as $field => $value) {
            if (!is_int($value) && !is_float($value)) {
                continue;
            }

            $rules = is_array($config[$field] ?? null) ? $config[$field] : [];
            $rule = findRangeRule($rules, (int) $value);
            $items[] = [
                'key' => (string) $field,
                'label' => humanizeFieldLabel((string) $field),
                'value' => (int) $value,
                'color' => (string) ($rule['color'] ?? ''),
            ];
        }

        if ($items === []) {
            continue;
        }

        $panels[] = [
            'title' => (string) ($group['ds'] ?? $groupKey),
            'items' => $items,
        ];
    }

    return $panels;
}

function configuredCardPanelConfigs(array $config): array
{
    $panels = is_array($config['card_panels'] ?? null) ? $config['card_panels'] : [];

    return array_values(array_filter($panels, 'is_array'));
}

function panelFilterDatasets(array $panelConfigs, array $groups): array
{
    $datasets = [];

    foreach ($panelConfigs as $panelConfig) {
        $groupKey = (string) ($panelConfig['group'] ?? '');
        if ($groupKey === '') {
            continue;
        }

        $group = findGroup($groups, [$groupKey]);
        $rows = is_array($group['dados'] ?? null) ? $group['dados'] : [];

        $datasets[] = [
            'rows' => $rows,
            'semester_field' => (string) ($panelConfig['semester_field'] ?? 'semestre'),
            'uo_field' => (string) ($panelConfig['uo_field'] ?? 'uo'),
        ];
    }

    return $datasets;
}

function itemStateValues(array $item): array
{
    $value = $item['state_values'] ?? ($item['values'] ?? ($item['state_value'] ?? ($item['value'] ?? null)));

    return is_array($value) ? $value : [$value];
}

function valueMatches(mixed $actual, array $expectedValues): bool
{
    foreach ($expectedValues as $expected) {
        if ($actual !== null && normalizeState((string) $actual) === normalizeState((string) $expected)) {
            return true;
        }
    }

    return false;
}

function normalizeCardPanelItems(array $panelConfig, array $config): array
{
    $items = is_array($panelConfig['items'] ?? null) ? $panelConfig['items'] : [];
    $configGroupKey = (string) ($panelConfig['config_group'] ?? '');
    $configGroup = $configGroupKey !== '' && is_array($config[$configGroupKey] ?? null) ? $config[$configGroupKey] : [];
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $key = (string) ($item['key'] ?? '');
        if ($key === '') {
            $key = normalizeState((string) ($item['label'] ?? $item['source_label'] ?? 'item_' . count($normalized)));
        }

        $sourceLabel = (string) ($item['source_label'] ?? $item['label'] ?? '');
        $configEntry = $sourceLabel !== '' ? lookupConfigEntry($configGroup, $sourceLabel) : [];
        $label = trim((string) ($item['label'] ?? $configEntry['label'] ?? $sourceLabel));
        $color = resolveColor($config, (string) ($item['color'] ?? $configEntry['color'] ?? ''), 'card_colors');

        $normalized[] = [
            'key' => $key,
            'label' => $label !== '' ? $label : $key,
            'source_label' => $sourceLabel,
            'state_values' => itemStateValues($item),
            'color' => $color,
            'empty_display' => (string) ($item['empty_display'] ?? 'zero'),
        ];
    }

    return $normalized;
}

function buildCardPanels(
    array $panelConfigs,
    array $groups,
    array $config,
    string $selectedSemester,
    string $selectedUo
): array {
    $panels = [];

    foreach ($panelConfigs as $panelConfig) {
        $groupKey = (string) ($panelConfig['group'] ?? '');
        if ($groupKey === '') {
            continue;
        }

        $group = findGroup($groups, [$groupKey]);
        $rows = is_array($group['dados'] ?? null) ? $group['dados'] : [];
        $semesterField = (string) ($panelConfig['semester_field'] ?? 'semestre');
        $uoField = (string) ($panelConfig['uo_field'] ?? 'uo');
        $countField = (string) ($panelConfig['count_field'] ?? 'count');
        $stateField = (string) ($panelConfig['state_field'] ?? '');
        $labelField = (string) ($panelConfig['label_field'] ?? '');
        $items = normalizeCardPanelItems($panelConfig, $config);

        if ($items === []) {
            continue;
        }

        $rows = filterRows($rows, $selectedSemester, $selectedUo, $semesterField, $uoField);
        $itemKeys = array_column($items, 'key');
        $totals = array_fill_keys($itemKeys, 0);
        $rowOrder = buildFieldOrder($uoField, $rows);
        $rowsByUo = initMatrix($rowOrder, $itemKeys);

        foreach ($rows as $row) {
            $rowUo = (string) ($row[$uoField] ?? '');
            $count = (int) ($row[$countField] ?? 0);
            $stateValue = $stateField !== '' ? ($row[$stateField] ?? null) : null;
            $labelValue = $labelField !== '' ? ($row[$labelField] ?? null) : null;

            foreach ($items as $item) {
                $matchesState = valueMatches($stateValue, $item['state_values']);
                $matchesLabel = $item['source_label'] !== '' && $labelValue !== null
                    && normalizeState((string) $labelValue) === normalizeState((string) $item['source_label']);

                if (!$matchesState && !$matchesLabel) {
                    continue;
                }

                $totals[$item['key']] += $count;

                if ($rowUo !== '') {
                    if (!isset($rowsByUo[$rowUo])) {
                        $rowsByUo[$rowUo] = array_fill_keys($itemKeys, 0);
                        $rowOrder[] = $rowUo;
                    }

                    $rowsByUo[$rowUo][$item['key']] += $count;
                }
            }
        }

        $title = trim((string) ($panelConfig['title'] ?? ''));
        $panels[] = [
            'title' => titleWithSemester($title !== '' ? $title : (string) ($group['ds'] ?? $groupKey), $selectedSemester),
            'icon' => (string) ($panelConfig['icon'] ?? 'chart'),
            'items' => $items,
            'totals' => $totals,
            'rows_by_uo' => $rowsByUo,
            'uo_order' => $rowOrder,
            'group_label' => (string) ($panelConfig['group_label'] ?? humanizeFieldLabel($uoField)),
            'show_table' => (bool) ($panelConfig['show_table'] ?? true) && $rowOrder !== [],
        ];
    }

    return $panels;
}

function buildPresidenciaStatusPanel(
    array $rows,
    string $stateField,
    string $countField,
    string $uoField,
    array $definitions
): array {
    $itemKeys = array_column($definitions, 'key');
    $totals = array_fill_keys($itemKeys, 0);
    $uoOrder = buildFieldOrder($uoField, $rows);
    $rowsByUo = initMatrix($uoOrder, $itemKeys);
    $items = [];

    foreach ($definitions as $definition) {
        $items[] = [
            'key' => (string) ($definition['key'] ?? ''),
            'label' => (string) ($definition['label'] ?? ''),
            'color' => (string) ($definition['color'] ?? ''),
            'empty_display' => (string) ($definition['empty_display'] ?? 'zero'),
        ];
    }

    foreach ($rows as $row) {
        $stateId = (int) ($row[$stateField] ?? 0);
        $count = (int) ($row[$countField] ?? 0);
        $uo = trim((string) ($row[$uoField] ?? ''));

        foreach ($definitions as $definition) {
            if ($stateId !== (int) ($definition['state_id'] ?? 0)) {
                continue;
            }

            $key = (string) ($definition['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $totals[$key] += $count;

            if ($uo !== '') {
                if (!isset($rowsByUo[$uo])) {
                    $rowsByUo[$uo] = array_fill_keys($itemKeys, 0);
                    $uoOrder[] = $uo;
                }

                $rowsByUo[$uo][$key] += $count;
            }

            break;
        }
    }

    return [
        'items' => $items,
        'totals' => $totals,
        'rows_by_uo' => $rowsByUo,
        'uo_order' => $uoOrder,
    ];
}

function formatMetricValue(int $value, string $emptyDisplay = 'zero'): string
{
    if ($value === 0 && $emptyDisplay === 'dash') {
        return '&mdash;';
    }

    return number_format($value, 0, ',', '.');
}

function panelIconSvg(string $icon): string
{
    if ($icon === 'document') {
        return '<svg class="title-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h8"></path></svg>';
    }

    return '<svg class="title-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v15H6.5A2.5 2.5 0 0 0 4 19.5V4.5A2.5 2.5 0 0 1 6.5 2z"></path></svg>';
}

function uoIconSvg(): string
{
    return '<svg class="icon-house" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"></path><path d="M5 9.5V20h14V9.5"></path><path d="M9 20v-5h6v5"></path></svg>';
}

$navTabs = [
    ['key' => 'presidencia', 'label' => 'Presidência', 'enabled' => true],
    ['key' => 'cc', 'label' => 'Coordenador de Curso', 'enabled' => true],
    ['key' => 'docente', 'label' => 'Docente', 'enabled' => true],
    ['key' => 'dir_uo', 'label' => 'Direção UO', 'enabled' => false],
    ['key' => 'pessoal', 'label' => 'Pessoal', 'enabled' => false],
    ['key' => 'gestao_documental', 'label' => 'Gestão Documental', 'enabled' => false],
    ['key' => 'gac', 'label' => 'GAC', 'enabled' => false],
];

$semesterDatasets = [];
foreach ($grupos as $group) {
    if (!is_array($group)) {
        continue;
    }

    $rows = is_array($group['dados'] ?? null) ? $group['dados'] : [];
    if ($rows !== [] && isset($rows[0]) && is_array($rows[0]) && hasFieldInRows($rows, 'semestre')) {
        $semesterDatasets[] = $rows;
    }
}

$semesterOrder = $semesterDatasets === [] ? [] : sortSemesterValues(buildFieldOrder('semestre', ...$semesterDatasets));
$selectedSemester = trim((string) ($_GET['semester'] ?? ''));
if ($selectedSemester !== '' && !in_array($selectedSemester, $semesterOrder, true)) {
    $selectedSemester = '';
}

$showSemesterFilter = $semesterOrder !== [];
$defaultSemesterFilter = $selectedSemester !== '' ? $selectedSemester : 'Todos os Semestres';
$showScopeFilter = false;
$selectedUo = '';
$scopeField = '';
$allUoOrder = [];
$defaultUoFilter = 'Todas as UO';

$descricaoConfig = is_array($alertsConfig['descricao'] ?? null) ? $alertsConfig['descricao'] : [];
$descricaoEstadoConfig = is_array($alertsConfig['descricao_estado'] ?? null) ? $alertsConfig['descricao_estado'] : [];
$estadoConfig = is_array($alertsConfig['estado'] ?? null) ? $alertsConfig['estado'] : [];
$regenteConfig = is_array($alertsConfig['regente'] ?? null) ? $alertsConfig['regente'] : [];

$pageTitle = (string) ($dashboardProfile['ds_grupo'] ?? 'Presidencia');
$heroTitle = 'Dashboard – ' . $pageTitle;

$aulasTitle = 'Aulas';
$aulasCards = [];
$sumariosTitle = 'Sumários';
$sumariosCards = [];
$summaryPanels = [];
$entrySections = [];
$replacementTitle = 'Pedidos de Substituição';
$replacementCards = [];
$stageTitle = 'Estágios';
$stageMetric = [];
$stageMessage = '';
$ucsSemDocenteTitle = 'UCs sem docente';
$ucsBySigla = [];
$totalUcsSemDocente = 0;
$ucsSemDocenteTitleStyle = '';
$presidenciaAulasPanel = [];
$presidenciaSumariosPanel = [];

if ($selectedPage === 'presidencia') {
    $scopeField = 'uo';
    $allUoOrder = buildUoOrder(
        groupDataRows($grupos, ['aulas_por_lecionar_pres', 'aulas_por_lecionar']),
        groupDataRows($grupos, ['sumarios_por_publicar_pres', 'sumarios_por_publicar'])
    );
    $selectedUo = trim((string) ($_GET['scope'] ?? ''));
    if ($selectedUo !== '' && !in_array($selectedUo, $allUoOrder, true)) {
        $selectedUo = '';
    }
    $showScopeFilter = $allUoOrder !== [];
    $defaultUoFilter = $selectedUo !== '' ? $selectedUo : 'Todas as UO';
}

if ($selectedPage === 'presidencia') {
    $aulasTitle = groupLabel($grupos, ['aulas_por_lecionar_pres', 'aulas_por_lecionar'], 'Aulas');
    $aulasCards = buildSemesterMetricCards(
        groupDataRows($grupos, ['aulas_por_lecionar_pres', 'aulas_por_lecionar'], $selectedSemester),
        $descricaoConfig,
        'descricao',
        'num_aulas',
        'aulas'
    );

    $sumariosTitle = groupLabel($grupos, ['sumarios_por_publicar_pres', 'sumarios_por_publicar'], 'Sumários');
    $sumariosCards = buildTotalMetricCards(
        groupDataRows($grupos, ['sumarios_por_publicar_pres', 'sumarios_por_publicar'], $selectedSemester),
        $descricaoEstadoConfig,
        'descricao_estado',
        'num_aulas',
        ['Elaborado' => 'Elaborados', 'Não Elaborado' => 'Não Elaborados']
    );

    $aulasStateColors = [];
    foreach (groupDataRows($grupos, ['aulas_por_lecionar_pres', 'aulas_por_lecionar']) as $row) {
        $stateId = (int) ($row['id_estado'] ?? 0);
        if ($stateId <= 0 || isset($aulasStateColors[$stateId])) {
            continue;
        }

        $configEntry = lookupConfigEntry($descricaoConfig, (string) ($row['descricao'] ?? ''));
        $aulasStateColors[$stateId] = (string) ($configEntry['color'] ?? '');
    }

    $sumariosStateColors = [];
    foreach (groupDataRows($grupos, ['sumarios_por_publicar_pres', 'sumarios_por_publicar']) as $row) {
        $stateId = (int) ($row['id_estado_sumario'] ?? 0);
        if ($stateId <= 0 || isset($sumariosStateColors[$stateId])) {
            continue;
        }

        $configEntry = lookupConfigEntry($descricaoEstadoConfig, (string) ($row['descricao_estado'] ?? ''));
        $sumariosStateColors[$stateId] = (string) ($configEntry['color'] ?? '');
    }

    $presidenciaPanels = buildCardPanels(
        [
            [
                'group' => 'aulas_por_lecionar_pres',
                'title' => $aulasTitle,
                'icon' => 'chart',
                'config_group' => 'descricao',
                'state_field' => 'id_estado',
                'count_field' => 'num_aulas',
                'semester_field' => 'semestre',
                'uo_field' => 'uo',
                'group_label' => 'Unidade OrgÃ¢nica',
                'show_table' => true,
                'items' => [
                    [
                        'key' => 'por_lecionar',
                        'label' => 'Por Lecionar',
                        'source_label' => 'Por lecionar',
                        'state_values' => [1],
                        'color' => (string) ($aulasStateColors[1] ?? ''),
                        'empty_display' => 'zero',
                    ],
                    [
                        'key' => 'nao_lecionadas',
                        'label' => 'NÃ£o Lecionadas',
                        'source_label' => 'NÃ£o lecionada',
                        'state_values' => ['NÃ£o lecionada', 'NÃ£o lecionadas'],
                        'empty_display' => 'dash',
                    ],
                    [
                        'key' => 'nao_justificadas',
                        'label' => 'NÃ£o Justificadas',
                        'source_label' => 'NÃ£o Justificada',
                        'state_values' => ['NÃ£o Justificada', 'NÃ£o Justificadas'],
                        'empty_display' => 'dash',
                    ],
                ],
            ],
            [
                'group' => 'sumarios_por_publicar_pres',
                'title' => $sumariosTitle,
                'icon' => 'document',
                'config_group' => 'descricao_estado',
                'state_field' => 'id_estado_sumario',
                'count_field' => 'num_aulas',
                'semester_field' => 'semestre',
                'uo_field' => 'uo',
                'group_label' => 'Unidade OrgÃ¢nica',
                'show_table' => true,
                'items' => [
                    [
                        'key' => 'elaborados',
                        'label' => 'Elaborados',
                        'source_label' => 'Elaborado',
                        'state_values' => ['Elaborado', 'Elaborados'],
                        'empty_display' => 'dash',
                    ],
                    [
                        'key' => 'nao_elaborados',
                        'label' => 'NÃ£o Elaborados',
                        'source_label' => 'NÃ£o Elaborado',
                        'state_values' => ['NÃ£o Elaborado', 'NÃ£o Elaborados'],
                        'empty_display' => 'dash',
                    ],
                ],
            ],
        ],
        $grupos,
        $alertsConfig,
        $selectedSemester,
        $selectedUo
    );

    $presidenciaAulasPanel = $presidenciaPanels[0] ?? [];
    $presidenciaSumariosPanel = $presidenciaPanels[1] ?? [];

    $aulasOverviewRows = filterRows(
        groupDataRows($grupos, ['aulas_por_lecionar_pres', 'aulas_por_lecionar'], $selectedSemester),
        '',
        $selectedUo,
        '',
        'uo'
    );
    $sumariosOverviewRows = filterRows(
        groupDataRows($grupos, ['sumarios_por_publicar_pres', 'sumarios_por_publicar'], $selectedSemester),
        '',
        $selectedUo,
        '',
        'uo'
    );

    $presidenciaAulasPanel = buildPresidenciaStatusPanel(
        $aulasOverviewRows,
        'id_estado',
        'num_aulas',
        'uo',
        [
            [
                'key' => 'por_lecionar',
                'label' => 'Por Lecionar',
                'state_id' => 1,
                'color' => (string) ($aulasStateColors[1] ?? ''),
                'empty_display' => 'zero',
            ],
            [
                'key' => 'nao_lecionadas',
                'label' => 'Não Lecionadas',
                'state_id' => 3,
                'color' => (string) ($aulasStateColors[3] ?? ''),
                'empty_display' => 'dash',
            ],
            [
                'key' => 'nao_justificadas',
                'label' => 'Não Justificadas',
                'state_id' => 7,
                'color' => (string) ($aulasStateColors[7] ?? ''),
                'empty_display' => 'dash',
            ],
        ]
    );

    $presidenciaSumariosPanel = buildPresidenciaStatusPanel(
        $sumariosOverviewRows,
        'id_estado_sumario',
        'num_aulas',
        'uo',
        [
            [
                'key' => 'elaborados',
                'label' => 'Elaborados',
                'state_id' => 2,
                'color' => (string) ($sumariosStateColors[2] ?? ''),
                'empty_display' => 'dash',
            ],
            [
                'key' => 'nao_elaborados',
                'label' => 'Não Elaborados',
                'state_id' => 1,
                'color' => (string) ($sumariosStateColors[1] ?? ''),
                'empty_display' => 'dash',
            ],
        ]
    );

    $pucRows = groupDataRows($grupos, ['resumo_estados_puc_pres', 'resumo_estados_puc'], $selectedSemester);
    $rucRows = groupDataRows($grupos, ['resumo_estados_ruc_pres', 'resumo_estados_ruc'], $selectedSemester);
    if ($pucRows !== []) {
        $summaryPanels[] = [
            'title' => groupLabel($grupos, ['resumo_estados_puc_pres', 'resumo_estados_puc'], 'Resumo Estados PUCs'),
            'items' => buildStateSummaryCards($pucRows, $estadoConfig),
        ];
    }
    if ($rucRows !== []) {
        $summaryPanels[] = [
            'title' => groupLabel($grupos, ['resumo_estados_ruc_pres', 'resumo_estados_ruc'], 'Resumo Estados RUCs'),
            'items' => buildStateSummaryCards($rucRows, $estadoConfig),
        ];
    }

    $ucsSemDocenteRows = groupDataRows($grupos, ['ucs_sem_docente']);
    $ucsSemDocenteTitle = groupLabel($grupos, ['ucs_sem_docente'], 'UCs sem docente');
    foreach ($ucsSemDocenteRows as $row) {
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
    foreach ($ucsBySigla as $disciplinas) {
        $totalUcsSemDocente += count($disciplinas);
    }
    $ucsSemDocenteRule = findRangeRule(
        is_array($alertsConfig['ucs_sem_docente_count'] ?? null) ? $alertsConfig['ucs_sem_docente_count'] : [],
        $totalUcsSemDocente
    );
    $ucsSemDocenteTitleStyle = buildStateBoxStyle((string) ($ucsSemDocenteRule['color'] ?? ''));
} elseif ($selectedPage === 'cc') {
    $aulasTitle = groupLabel($grupos, ['aulas_por_lecionar_cc'], 'Aulas');
    $aulasCards = buildSemesterMetricCards(
        groupDataRows($grupos, ['aulas_por_lecionar_cc'], $selectedSemester),
        $descricaoConfig,
        'descricao',
        'num_aulas',
        'aulas'
    );

    $sumariosTitle = groupLabel($grupos, ['sumarios_por_publicar_cc'], 'Sumários');
    $sumariosCards = buildTotalMetricCards(
        groupDataRows($grupos, ['sumarios_por_publicar_cc'], $selectedSemester),
        $descricaoEstadoConfig,
        'descricao_estado',
        'num_aulas',
        ['Elaborado' => 'Elaborados', 'Não Elaborado' => 'Não Elaborados']
    );

    $summaryPanels[] = [
        'title' => groupLabel($grupos, ['resumo_estados_puc_cc', 'resumo_estados_puc'], 'Resumo Estados PUCs'),
        'items' => buildStateSummaryCards(groupDataRows($grupos, ['resumo_estados_puc_cc', 'resumo_estados_puc'], $selectedSemester), $estadoConfig),
    ];
    $summaryPanels[] = [
        'title' => groupLabel($grupos, ['resumo_estados_ruc_cc', 'resumo_estados_ruc'], 'Resumo Estados RUCs'),
        'items' => buildStateSummaryCards(groupDataRows($grupos, ['resumo_estados_ruc_cc', 'resumo_estados_ruc'], $selectedSemester), $estadoConfig),
    ];

    $ccPucEntries = buildEntryCards(groupDataRows($grupos, ['estado_pucs_cc'], $selectedSemester), $estadoConfig);
    if ($ccPucEntries !== []) {
        $entrySections[] = [
            'title' => groupLabel($grupos, ['estado_pucs_cc'], 'Estado PUCs/US'),
            'items' => $ccPucEntries,
        ];
    }

    $ccRucEntries = buildEntryCards(groupDataRows($grupos, ['estado_rucs_cc'], $selectedSemester), $estadoConfig);
    if ($ccRucEntries !== []) {
        $entrySections[] = [
            'title' => groupLabel($grupos, ['estado_rucs_cc'], 'Estado RUCs/US'),
            'items' => $ccRucEntries,
        ];
    }

    $replacementCards = buildReplacementCards(groupDataRows($grupos, ['pedidos_substituicao']));
    $replacementTitle = groupLabel($grupos, ['pedidos_substituicao'], 'Pedidos de Substituição');

    $stageGroup = findGroup($grupos, ['estagios']);
    $stageTitle = groupLabel($grupos, ['estagios'], 'Estágios');
    $stageMetric = buildStageMetric($stageGroup, $alertsConfig);
    if ($stageMetric === []) {
        $stageMessage = 'Sem dados de estágios.';
    }
} elseif ($selectedPage === 'docente') {
    $aulasTitle = groupLabel($grupos, ['aulas_por_lecionar'], 'Aulas por Lecionar');
    $aulasCards = buildSemesterMetricCards(
        groupDataRows($grupos, ['aulas_por_lecionar'], $selectedSemester),
        $descricaoConfig,
        'descricao',
        'num_aulas',
        'aulas'
    );

    $sumariosTitle = groupLabel($grupos, ['sumarios_por_publicar'], 'Sumários por Publicar');
    $sumariosCards = buildTotalMetricCards(
        groupDataRows($grupos, ['sumarios_por_publicar'], $selectedSemester),
        $descricaoEstadoConfig,
        'descricao_estado',
        'num_aulas',
        ['Elaborado' => 'Elaborados', 'Não Elaborado' => 'Não Elaborados']
    );

    $entrySections[] = [
        'title' => groupLabel($grupos, ['estado_pucs_docente'], 'Estado dos PUCs'),
        'items' => buildEntryCards(groupDataRows($grupos, ['estado_pucs_docente'], $selectedSemester), $estadoConfig),
    ];
    $entrySections[] = [
        'title' => groupLabel($grupos, ['estado_rucs_docente'], 'Estado dos RUCs'),
        'items' => buildEntryCards(groupDataRows($grupos, ['estado_rucs_docente'], $selectedSemester), $estadoConfig),
    ];

    $stageTitle = groupLabel($grupos, ['estagios'], 'Estágios');
    $stageMessage = 'Sem estágios atribuídos.';
}
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
            display: inline-flex;
            align-items: center;
            padding: 10px 12px;
            border-radius: 0;
            border: 1px solid transparent;
            background: transparent;
            color: #111827;
            font-size: 0.96rem;
            font-weight: 600;
            text-decoration: none;
        }

        .tab.active {
            border-color: #6b95ff;
            background: #fff;
            color: var(--brand-2);
        }

        .tab-disabled {
            opacity: 0.56;
            cursor: default;
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
            font-size: clamp(1.95rem, 2.5vw, 2.55rem);
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

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .metric-card {
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 16px 18px;
            min-height: 118px;
        }

        .metric-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 18px;
        }

        .metric-badge,
        .metric-status {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 4px 8px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.65);
            font-size: 0.78rem;
            line-height: 1;
        }

        .metric-status {
            margin-left: auto;
            text-align: right;
        }

        .metric-value {
            display: block;
            font-size: 2rem;
            line-height: 1;
            letter-spacing: -0.05em;
        }

        .metric-unit {
            display: block;
            margin-top: 8px;
            font-size: 0.84rem;
            opacity: 0.85;
        }

        .summary-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .summary-card-grid-single {
            grid-template-columns: minmax(0, 180px);
        }

        .summary-card {
            min-height: 92px;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: center;
        }

        .summary-label {
            font-size: 0.85rem;
            opacity: 0.82;
        }

        .summary-value {
            display: block;
            margin-top: 8px;
            font-size: 2rem;
            line-height: 1;
            letter-spacing: -0.05em;
        }

        .pres-overview-stats {
            display: grid;
            gap: 18px;
            margin-bottom: 18px;
        }

        .pres-overview-stats.three {
            grid-template-columns: repeat(3, minmax(0, 180px));
            justify-content: space-between;
        }

        .pres-overview-stats.two {
            grid-template-columns: repeat(2, minmax(0, 180px));
            justify-content: space-between;
        }

        .pres-overview-card {
            min-height: 0;
            padding: 16px 18px;
        }

        .pres-overview-table th,
        .pres-overview-table td {
            padding: 14px 16px;
        }

        .pres-overview-table th {
            background: #f8fafc;
            font-size: 0.82rem;
            font-weight: 700;
            color: #5c6d86;
        }

        .pres-overview-table td {
            font-size: 0.95rem;
        }

        .pres-overview-table .uo-column {
            width: 34%;
        }

        .pres-overview-table .metric-column {
            text-align: right;
        }

        .pres-overview-table .metric-column span {
            display: inline-block;
            text-align: right;
            line-height: 1.35;
        }

        .pres-overview-table .metric-cell {
            text-align: right;
            font-weight: 700;
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
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
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

        .mini-grid-single {
            grid-template-columns: 1fr;
        }

        .panel-compact {
            align-self: start;
        }

        .state-list {
            display: grid;
            gap: 14px;
            margin-top: 16px;
        }

        .summary-state-list {
            display: grid;
            gap: 12px;
        }

        .summary-state-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px 16px;
            font-size: 0.95rem;
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

        .entry-list {
            display: grid;
            gap: 12px;
        }

        .entry-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px 16px;
            display: grid;
            gap: 6px;
        }

        .entry-title {
            font-size: 0.98rem;
            line-height: 1.35;
        }

        .entry-subtitle,
        .entry-state,
        .entry-meta {
            font-size: 0.82rem;
            opacity: 0.9;
        }

        .replacement-card .entry-title {
            font-size: 0.95rem;
        }

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
            .mini-grid,
            .metric-grid,
            .summary-card-grid,
            .pres-overview-stats.three,
            .pres-overview-stats.two {
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
                        <?php foreach ($navTabs as $tab): ?>
                            <?php if ($tab['enabled']): ?>
                                <a class="tab<?= $selectedPage === $tab['key'] ? ' active' : '' ?>" href="<?= e(buildProfileUrl((string) $tab['key'])) ?>">
                                    <?= e((string) $tab['label']) ?>
                                </a>
                            <?php else: ?>
                                <span class="tab tab-disabled"><?= e((string) $tab['label']) ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                </div>

                <div class="content-body">
                    <div class="hero">
                        <div class="hero-copy">
                            <h1><?= e($heroTitle) ?></h1>
                        </div>
                        <div class="hero-controls" aria-label="Filtros">
                            <form class="filter-form" method="get">
                                <input type="hidden" name="profile" value="<?= e($selectedPage) ?>">
                                <input type="hidden" name="semester" value="<?= e($selectedSemester) ?>">
                                <input type="hidden" name="scope" value="<?= e($selectedUo) ?>">

                                <?php if ($showSemesterFilter): ?>
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
                                <?php endif; ?>

                                <?php if ($showScopeFilter): ?>
                                    <div class="filter-dropdown" data-filter-dropdown>
                                        <button class="filter-pill filter-trigger" type="button" aria-haspopup="true" aria-expanded="false">
                                            <span><?= e($defaultUoFilter) ?></span>
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </button>
                                    <div class="filter-menu" role="menu" aria-label="Filtro de unidade orgânica">
                                        <button class="filter-option<?= $selectedUo === '' ? ' is-selected' : '' ?>" type="button" data-filter-name="scope" data-filter-value="">
                                                <span>Todas as UO</span>
                                            <?php if ($selectedUo === ''): ?>
                                                <svg class="filter-option-check" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M20 6L9 17l-5-5"></path>
                                                </svg>
                                            <?php endif; ?>
                                        </button>
                                        <?php foreach ($allUoOrder as $uo): ?>
                                            <button class="filter-option<?= $selectedUo === $uo ? ' is-selected' : '' ?>" type="button" data-filter-name="scope" data-filter-value="<?= e($uo) ?>">
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
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <div class="main-grid">
                        <?php if ($selectedPage === 'presidencia'): ?>
                            <section class="panel">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('chart') ?>
                                    <?= e($aulasTitle) ?>
                                </h2>

                                <?php if ($presidenciaAulasPanel !== []): ?>
                                    <div class="pres-overview-stats three">
                                        <?php foreach ($presidenciaAulasPanel['items'] as $item): ?>
                                            <article class="summary-card pres-overview-card"<?= ($style = buildCardStyle((string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                <span class="summary-label"><?= e((string) $item['label']) ?></span>
                                                <strong class="summary-value"><?= number_format((int) ($presidenciaAulasPanel['totals'][$item['key']] ?? 0), 0, ',', '.') ?></strong>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if (($presidenciaAulasPanel['uo_order'] ?? []) !== []): ?>
                                        <div class="table-shell">
                                            <table class="pres-overview-table">
                                                <thead>
                                                    <tr>
                                                        <th class="uo-column">Unidade Org&acirc;nica</th>
                                                        <?php foreach ($presidenciaAulasPanel['items'] as $item): ?>
                                                            <th class="metric-column"<?= ($style = buildTextColorStyle((string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                                <span><?= e((string) $item['label']) ?></span>
                                                            </th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($presidenciaAulasPanel['uo_order'] as $uo): ?>
                                                        <tr>
                                                            <td>
                                                                <div class="uo-cell">
                                                                    <?= uoIconSvg() ?>
                                                                    <span><?= e((string) $uo) ?></span>
                                                                </div>
                                                            </td>
                                                            <?php foreach ($presidenciaAulasPanel['items'] as $item): ?>
                                                                <td class="metric-cell"<?= ($style = buildTextColorStyle((string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                                    <strong><?= formatMetricValue((int) ($presidenciaAulasPanel['rows_by_uo'][$uo][$item['key']] ?? 0), (string) ($item['empty_display'] ?? 'zero')) ?></strong>
                                                                </td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p class="muted">Sem aulas para apresentar.</p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p class="muted">Sem aulas para apresentar.</p>
                                <?php endif; ?>
                            </section>

                            <section class="panel">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('document') ?>
                                    <?= e($sumariosTitle) ?>
                                </h2>

                                <?php if ($presidenciaSumariosPanel !== []): ?>
                                    <div class="pres-overview-stats two">
                                        <?php foreach ($presidenciaSumariosPanel['items'] as $item): ?>
                                            <article class="summary-card pres-overview-card"<?= ($style = buildCardStyle((string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                <span class="summary-label"><?= e((string) $item['label']) ?></span>
                                                <strong class="summary-value"><?= number_format((int) ($presidenciaSumariosPanel['totals'][$item['key']] ?? 0), 0, ',', '.') ?></strong>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if (($presidenciaSumariosPanel['uo_order'] ?? []) !== []): ?>
                                        <div class="table-shell">
                                            <table class="pres-overview-table">
                                                <thead>
                                                    <tr>
                                                        <th class="uo-column">Unidade Org&acirc;nica</th>
                                                        <?php foreach ($presidenciaSumariosPanel['items'] as $item): ?>
                                                            <th class="metric-column"<?= ($style = buildTextColorStyle((string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                                <span><?= e((string) $item['label']) ?></span>
                                                            </th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($presidenciaSumariosPanel['uo_order'] as $uo): ?>
                                                        <tr>
                                                            <td>
                                                                <div class="uo-cell">
                                                                    <?= uoIconSvg() ?>
                                                                    <span><?= e((string) $uo) ?></span>
                                                                </div>
                                                            </td>
                                                            <?php foreach ($presidenciaSumariosPanel['items'] as $item): ?>
                                                                <td class="metric-cell"<?= ($style = buildTextColorStyle((string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                                    <strong><?= formatMetricValue((int) ($presidenciaSumariosPanel['rows_by_uo'][$uo][$item['key']] ?? 0), (string) ($item['empty_display'] ?? 'zero')) ?></strong>
                                                                </td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p class="muted">Sem sum&aacute;rios para apresentar.</p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p class="muted">Sem sum&aacute;rios para apresentar.</p>
                                <?php endif; ?>
                            </section>
                        <?php else: ?>
                        <section class="panel">
                            <h2 class="panel-title">
                                <?= panelIconSvg('chart') ?>
                                <?= e($aulasTitle) ?>
                            </h2>

                            <?php if ($aulasCards !== []): ?>
                                <div class="metric-grid">
                                    <?php foreach ($aulasCards as $card): ?>
                                        <article class="metric-card"<?= ($style = buildCardStyle((string) $card['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                            <div class="metric-card-top">
                                                <span class="metric-badge"><?= e((string) $card['semester']) ?></span>
                                                <span class="metric-status"><?= e((string) $card['state']) ?></span>
                                            </div>
                                            <strong class="metric-value"><?= number_format((int) $card['count'], 0, ',', '.') ?></strong>
                                            <span class="metric-unit"><?= e((string) $card['unit']) ?></span>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="muted">Sem aulas para apresentar.</p>
                            <?php endif; ?>
                        </section>

                        <section class="panel">
                            <h2 class="panel-title">
                                <?= panelIconSvg('document') ?>
                                <?= e($sumariosTitle) ?>
                            </h2>

                            <?php if ($sumariosCards !== []): ?>
                                <div class="summary-card-grid">
                                    <?php foreach ($sumariosCards as $card): ?>
                                        <article class="summary-card"<?= ($style = buildCardStyle((string) $card['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                            <span class="summary-label"><?= e((string) $card['label']) ?></span>
                                            <strong class="summary-value"><?= number_format((int) $card['count'], 0, ',', '.') ?></strong>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="muted">Sem sumários para apresentar.</p>
                            <?php endif; ?>
                        </section>

                        <?php endif; ?>

                        <?php if ($summaryPanels !== []): ?>
                            <div class="mini-grid">
                                <?php foreach ($summaryPanels as $panel): ?>
                                    <?php if ($panel['items'] === []) { continue; } ?>
                                    <section class="panel panel-compact">
                                        <h2 class="panel-title">
                                            <?= panelIconSvg('document') ?>
                                            <?= e((string) $panel['title']) ?>
                                        </h2>

                                        <div class="summary-state-list">
                                            <?php foreach ($panel['items'] as $item): ?>
                                                <div class="summary-state-item"<?= ($style = buildCardStyle((string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                    <span><?= e((string) $item['label']) ?></span>
                                                    <strong><?= number_format((int) $item['count'], 0, ',', '.') ?></strong>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($entrySections !== []): ?>
                            <div class="mini-grid<?= count($entrySections) === 1 ? ' mini-grid-single' : '' ?>">
                                <?php foreach ($entrySections as $section): ?>
                                    <section class="panel">
                                        <h2 class="panel-title">
                                            <?= panelIconSvg('document') ?>
                                            <?= e((string) $section['title']) ?>
                                        </h2>

                                        <?php if ($section['items'] !== []): ?>
                                            <div class="entry-list">
                                                <?php foreach ($section['items'] as $item): ?>
                                                    <article class="entry-card"<?= ($style = buildCardStyle((string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                        <strong class="entry-title"><?= e((string) $item['title']) ?></strong>
                                                        <?php if ((string) $item['subtitle'] !== ''): ?>
                                                            <span class="entry-subtitle"><?= e((string) $item['subtitle']) ?></span>
                                                        <?php endif; ?>
                                                        <span class="entry-state"><?= e((string) $item['state']) ?></span>
                                                    </article>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="muted">Sem dados para apresentar.</p>
                                        <?php endif; ?>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($replacementCards !== []): ?>
                            <section class="panel">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('document') ?>
                                    <?= e($replacementTitle) ?> <small>(<?= count($replacementCards) ?>)</small>
                                </h2>

                                <div class="entry-list">
                                    <?php foreach ($replacementCards as $item): ?>
                                        <article class="entry-card replacement-card" style="<?= e(buildCardStyle('#eab308')) ?>">
                                            <strong class="entry-title"><?= e((string) $item['title']) ?></strong>
                                            <span class="entry-subtitle">Turno: <?= e((string) $item['turno']) ?> · Ano: <?= e((string) $item['ano_letivo']) ?></span>
                                            <span class="entry-meta">Aula: <?= e((string) $item['data_aula']) ?> → Definitiva: <?= e((string) $item['data_definitiva']) ?></span>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if ($selectedPage === 'presidencia' && $ucsSemDocenteTitle !== ''): ?>
                            <section class="panel">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('document') ?>
                                    <?= e($ucsSemDocenteTitle) ?> <small<?= $ucsSemDocenteTitleStyle !== '' ? ' style="' . e($ucsSemDocenteTitleStyle) . 'padding:4px 8px;border-radius:999px;margin-left:6px;display:inline-block;"' : '' ?>>(<?= number_format($totalUcsSemDocente, 0, ',', '.') ?>)</small>
                                </h2>

                                <?php if ($ucsBySigla !== []): ?>
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
                                <?php else: ?>
                                    <p class="muted">Sem UCs em falta para apresentar.</p>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <?php if ($selectedPage !== 'presidencia'): ?>
                            <section class="panel">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('chart') ?>
                                    <?= e($stageTitle) ?>
                                </h2>

                                <?php if ($stageMetric !== []): ?>
                                    <div class="summary-card-grid summary-card-grid-single">
                                        <article class="summary-card"<?= ($style = buildCardStyle((string) $stageMetric['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                            <span class="summary-label"><?= e((string) $stageMetric['label']) ?></span>
                                            <strong class="summary-value"><?= number_format((int) $stageMetric['value'], 0, ',', '.') ?></strong>
                                        </article>
                                    </div>
                                <?php else: ?>
                                    <p class="muted"><?= e($stageMessage !== '' ? $stageMessage : 'Sem dados para apresentar.') ?></p>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>
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
