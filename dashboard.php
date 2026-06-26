<?php
declare(strict_types=1);

const ALERTS_CONFIG_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'alertasconfig';
$availablePages = [
    'presidencia' => [
        'label' => 'Presidência',
        'url' => 'presidencia.php',
        'json' => 'alertsPresidencia.json',
        'config' => 'alertsPresidenciaconfig.json',
        'payload_key' => 'presidencia',
    ],
    'cc' => [
        'label' => 'Coordenador de Curso',
        'url' => 'cc.php',
        'json' => 'alertsCC.json',
        'config' => 'alertsCCconfig.json',
        'payload_key' => 'coord_curso',
    ],
    'docente' => [
        'label' => 'Docente',
        'url' => 'docente.php',
        'json' => 'alertsDocente.json',
        'config' => 'alertsDocenteconfig.json',
        'payload_key' => 'docente',
    ],
    'dir_uo' => [
        'label' => 'Direção UO',
        'url' => 'index.php',
        'json' => 'alertsDirUO.json',
        'config' => 'alertsDirUOconfig.json',
        'payload_key' => 'direcao',
    ],
    'pessoal' => [
        'label' => 'Pessoal',
        'url' => 'index.php',
        'json' => 'alertsFuncGeral.json',
        'config' => 'alertsFuncGeralconfig.json',
        'payload_key' => 'info_pessoal',
    ],
    'gestao_documental' => [
        'label' => 'Gestão Documental',
        'url' => 'index.php',
        'json' => 'alertsFuncGeral.json',
        'config' => 'alertsFuncGeralconfig.json',
        'payload_key' => 'ges_doc',
    ],
    'gac' => [
        'label' => 'GAC',
        'url' => 'index.php',
        'json' => 'alertsSA.json',
        'config' => 'alertsSAconfig.json',
        'payload_key' => 'gac',
    ],
];

$selectedPage = trim((string) (defined('DASHBOARD_PAGE') ? DASHBOARD_PAGE : ($_GET['profile'] ?? 'presidencia')));
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

function pageCardStyle(string $page, string $color): string
{
    return buildCardStyle($color);
}

function pageTextColorStyle(string $page, string $color): string
{
    return buildTextColorStyle($color);
}

function pageSchoolTextStyle(string $page, array $config, string $school): string
{
    return buildSchoolTextStyle($config, $school);
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

function resolveRangeColor(array $config, string $rangeGroup, int $value): string
{
    if ($rangeGroup === '') {
        return '';
    }

    $rules = is_array($config[$rangeGroup] ?? null) ? $config[$rangeGroup] : [];
    $rule = findRangeRule($rules, $value);

    return resolveColor($config, (string) ($rule['color'] ?? ''), 'card_colors');
}

function resolveMetricColor(array $config, array $item, int $value, string $rangesKey = 'ranges'): string
{
    $inlineRules = is_array($item[$rangesKey] ?? null) ? $item[$rangesKey] : [];
    $inlineRule = findRangeRule($inlineRules, $value);
    $inlineColor = resolveColor($config, (string) ($inlineRule['color'] ?? ''), 'card_colors');

    if ($inlineColor !== '') {
        return $inlineColor;
    }

    $rangeColor = resolveRangeColor($config, (string) ($item['range_group'] ?? ''), $value);

    return $rangeColor !== '' ? $rangeColor : (string) ($item['color'] ?? '');
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

function resolveSchoolLabel(array $schoolColors, string $school): string
{
    $entry = lookupConfigEntry($schoolColors, $school);
    if ($entry !== []) {
        return trim((string) ($entry['label'] ?? ''));
    }

    return '';
}

function buildSchoolTextStyle(array $config, string $school): string
{
    $schoolColors = is_array($config['school_colors'] ?? null) ? $config['school_colors'] : [];
    $color = resolveSchoolColor($schoolColors, $school);

    return buildTextColorStyle($color);
}

function schoolTitle(array $config, string $school): string
{
    $schoolColors = is_array($config['school_colors'] ?? null) ? $config['school_colors'] : [];
    $label = resolveSchoolLabel($schoolColors, $school);

    return $label !== '' ? $label : $school;
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
        'uo' => 'Unidade Organica',
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
        'uo' => 'Todas as Unidades Organicas',
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
                'state_values' => [$value],
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

function normalizedGroupLookup(array $groupKeys): array
{
    $lookup = [];

    foreach ($groupKeys as $groupKey) {
        $lookup[normalizeState((string) $groupKey)] = true;
    }

    return $lookup;
}

function cardPanelGroupKeys(array $panelConfigs): array
{
    $keys = [];

    foreach ($panelConfigs as $panelConfig) {
        $groupKey = trim((string) ($panelConfig['group'] ?? ''));
        if ($groupKey !== '') {
            $keys[] = $groupKey;
        }
    }

    return $keys;
}

function appendSkippedGroup(array &$skipLookup, string $groupKey): void
{
    if ($groupKey !== '') {
        $skipLookup[normalizeState($groupKey)] = true;
    }
}

function groupIsSkipped(array $skipLookup, string $groupKey): bool
{
    return isset($skipLookup[normalizeState($groupKey)]);
}

function buildAutomaticSummaryPanels(
    array $groups,
    array &$skipLookup,
    array $stateConfig,
    array $config,
    string $selectedSemester
): array {
    $panels = [];

    foreach ($groups as $groupKey => $group) {
        $groupKey = (string) $groupKey;
        if (groupIsSkipped($skipLookup, $groupKey) || !is_array($group)) {
            continue;
        }

        $rows = is_array($group['dados'] ?? null) ? $group['dados'] : [];
        if ($rows === [] || !isset($rows[0]) || !is_array($rows[0])) {
            continue;
        }

        $stateField = selectFirstExistingField($rows, ['estado']);
        $countField = selectFirstExistingField($rows, ['num_ucs', 'count', 'total']);
        if ($stateField === '' || $countField === '') {
            continue;
        }

        $semesterField = selectFirstExistingField($rows, ['semestre']);
        $filteredRows = $semesterField !== '' ? filterRows($rows, $selectedSemester, '', $semesterField, '') : $rows;
        $rangeGroup = $countField;
        $normalizedGroupKey = normalizeState($groupKey);
        if (str_contains($normalizedGroupKey, 'puc')) {
            $rangeGroup = 'estados_pucs';
        } elseif (str_contains($normalizedGroupKey, 'ruc')) {
            $rangeGroup = 'estados_rucs';
        }

        $panels[] = [
            'title' => (string) ($group['ds'] ?? $groupKey),
            'items' => buildStateSummaryCards($filteredRows, $stateConfig, $stateField, $countField, $config, $rangeGroup),
        ];
        appendSkippedGroup($skipLookup, $groupKey);
    }

    return $panels;
}

function buildAutomaticEntrySections(
    array $groups,
    array &$skipLookup,
    array $stateConfig,
    string $selectedSemester
): array {
    $sections = [];

    foreach ($groups as $groupKey => $group) {
        $groupKey = (string) $groupKey;
        if (groupIsSkipped($skipLookup, $groupKey) || !is_array($group)) {
            continue;
        }

        $rows = is_array($group['dados'] ?? null) ? $group['dados'] : [];
        if ($rows === [] || !isset($rows[0]) || !is_array($rows[0])) {
            continue;
        }

        if (!hasFieldInRows($rows, 'estado') || !hasFieldInRows($rows, 'nome_uc')) {
            continue;
        }

        $semesterField = selectFirstExistingField($rows, ['semestre']);
        $filteredRows = $semesterField !== '' ? filterRows($rows, $selectedSemester, '', $semesterField, '') : $rows;
        $items = buildEntryCards($filteredRows, $stateConfig);
        if ($items === []) {
            continue;
        }

        $sections[] = [
            'title' => (string) ($group['ds'] ?? $groupKey),
            'items' => $items,
        ];
        appendSkippedGroup($skipLookup, $groupKey);
    }

    return $sections;
}

function buildProfileUrl(string $profile, array $params = []): string
{
    global $availablePages;

    $baseUrl = (string) ($availablePages[$profile]['url'] ?? 'index.php');
    if ($baseUrl === 'index.php') {
        $params = ['profile' => $profile] + $params;
    }
    $query = array_filter(
        $params,
        static fn (mixed $value): bool => $value !== null && $value !== ''
    );

    return $query === [] ? $baseUrl : $baseUrl . '?' . http_build_query($query);
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
    string $countField = 'num_ucs',
    array $config = [],
    string $rangeGroup = ''
): array {
    $items = [];
    $rangeGroup = $rangeGroup !== '' ? $rangeGroup : $countField;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $state = (string) ($row[$stateField] ?? '');
        if ($state === '') {
            continue;
        }

        $configEntry = lookupConfigEntry($stateConfig, $state);
        $count = (int) ($row[$countField] ?? 0);
        $rangeColor = resolveRangeColor($config, $rangeGroup, $count);
        $items[] = [
            'label' => $state,
            'count' => $count,
            'color' => $rangeColor !== '' ? $rangeColor : (string) ($configEntry['color'] ?? ''),
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

    $date = parseDateValue($value);
    if ($date instanceof DateTimeImmutable) {
        return $date->format('d/m/Y');
    }

    return $value;
}

function parseDateValue(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $formats = ['!d/m/Y', '!d-m-Y', '!Y-m-d', '!Y/m/d'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
        if ($date instanceof DateTimeImmutable && !$hasErrors) {
            return $date;
        }
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Exception) {
        return null;
    }
}

function resolveValidityRule(array $config, int $daysRemaining): array
{
    $rules = is_array($config['datas_validade'] ?? null) ? $config['datas_validade'] : [];

    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $minDays = array_key_exists('min_days', $rule) ? (int) $rule['min_days'] : PHP_INT_MIN;
        $maxDays = array_key_exists('max_days', $rule) ? (int) $rule['max_days'] : PHP_INT_MAX;
        if ($daysRemaining >= $minDays && $daysRemaining <= $maxDays) {
            return $rule;
        }
    }

    if ($daysRemaining < 0) {
        return ['label' => 'Expirado', 'color' => 'critical'];
    }

    if ($daysRemaining <= 30) {
        return ['label' => 'Expira em breve', 'color' => 'warning'];
    }

    return ['label' => 'Valido', 'color' => 'success'];
}

function buildValidityInfo(string $rawDate, array $config = []): array
{
    $dateLabel = formatDateValue($rawDate);
    if ($dateLabel === '') {
        $color = resolveColor($config, 'warning', 'card_colors');
        return ['date' => '-', 'status' => 'Sem data', 'color' => $color];
    }

    $date = parseDateValue($rawDate);
    if (!$date instanceof DateTimeImmutable) {
        $color = resolveColor($config, 'warning', 'card_colors');
        return ['date' => $dateLabel, 'status' => 'Sem validacao', 'color' => $color];
    }

    $today = new DateTimeImmutable('today');
    $daysRemaining = (int) $today->diff($date)->format('%r%a');
    $rule = resolveValidityRule($config, $daysRemaining);
    $color = resolveColor($config, (string) ($rule['color'] ?? ''), 'card_colors');
    return [
        'date' => $dateLabel,
        'status' => (string) ($rule['label'] ?? ''),
        'color' => $color,
    ];
}

function formatAcademicYear(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) === 6) {
        return substr($digits, 0, 4) . '/' . substr($digits, 4, 2);
    }

    return $value;
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

        $color = resolveRangeColor($config, (string) $field, (int) $value);
        return [
            'label' => humanizeFieldLabel((string) $field),
            'value' => (int) $value,
            'color' => $color,
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
    array $skipLookup,
    string $selectedSemester,
    string $selectedUo,
    string $scopeField
): array {
    $panels = [];

    foreach ($groups as $groupKey => $group) {
        $groupKey = (string) $groupKey;
        if (groupIsSkipped($skipLookup, $groupKey) || !is_array($group)) {
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

function buildMetricOnlyPanels(array $groups, array $config, array &$skipLookup = []): array
{
    $panels = [];

    foreach ($groups as $groupKey => $group) {
        $groupKey = (string) $groupKey;
        if (groupIsSkipped($skipLookup, $groupKey) || !is_array($group) || !is_array($group['dados'] ?? null)) {
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
        appendSkippedGroup($skipLookup, $groupKey);
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
    $defaultRangeGroup = (string) ($panelConfig['range_group'] ?? $panelConfig['count_field'] ?? '');
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
            'ranges' => is_array($item['ranges'] ?? null) ? $item['ranges'] : [],
            'table_ranges' => is_array($item['table_ranges'] ?? null) ? $item['table_ranges'] : [],
            'range_group' => (string) ($item['range_group'] ?? $defaultRangeGroup),
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
            'id' => $groupKey,
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
    ['key' => 'dir_uo', 'label' => 'Direção UO', 'enabled' => true],
    ['key' => 'pessoal', 'label' => 'Pessoal', 'enabled' => true],
    ['key' => 'gestao_documental', 'label' => 'Gestão Documental', 'enabled' => true],
    ['key' => 'gac', 'label' => 'GAC', 'enabled' => true],
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
$scopeAllLabel = 'Todas as UO';

$descricaoConfig = is_array($alertsConfig['descricao'] ?? null) ? $alertsConfig['descricao'] : [];
$descricaoEstadoConfig = is_array($alertsConfig['descricao_estado'] ?? null) ? $alertsConfig['descricao_estado'] : [];
$estadoConfig = is_array($alertsConfig['estado'] ?? null) ? $alertsConfig['estado'] : [];

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
$dirUoStageItems = [];
$stageMessage = '';
$ucsSemDocenteTitle = 'UCs sem docente';
$ucsBySigla = [];
$totalUcsSemDocente = 0;
$ucsSemDocenteTitleStyle = '';
$presidenciaAulasPanel = [];
$presidenciaSumariosPanel = [];
$pessoalInfoCards = [];
$pessoalDespachosPorLer = 0;
$pessoalGestaoPedidos = ['mensagens' => 0, 'tarefas' => 0, 'pedidos' => 0];
$pessoalDespachosColor = '';
$pessoalGestaoPedidosColors = ['mensagens' => '', 'tarefas' => '', 'pedidos' => ''];
$docTarefas = ['abertas' => 0, 'novas' => 0, 'por_submeter' => 0, 'processos_abertos' => 0];
$docTarefasColors = ['abertas' => '', 'novas' => '', 'por_submeter' => '', 'processos_abertos' => ''];
$docResumoTotal = 0;
$gacPedidos = [];
$configuredCardPanelConfigs = configuredCardPanelConfigs($alertsConfig);
$automaticCardPanelConfigs = buildAutomaticCardPanelConfigs($grupos);
$configuredCardPanelLookup = normalizedGroupLookup(cardPanelGroupKeys($configuredCardPanelConfigs));
$cardPanelConfigs = $configuredCardPanelConfigs;
foreach ($automaticCardPanelConfigs as $automaticCardPanelConfig) {
    $automaticGroupKey = (string) ($automaticCardPanelConfig['group'] ?? '');
    if ($automaticGroupKey !== '' && !isset($configuredCardPanelLookup[normalizeState($automaticGroupKey)])) {
        $cardPanelConfigs[] = $automaticCardPanelConfig;
    }
}
$configuredOverviewPanels = [];
$autoMetricPanels = [];
$autoDetailPanels = [];

if ($cardPanelConfigs !== []) {
    $panelDatasets = panelFilterDatasets($cardPanelConfigs, $grupos);
    $scopeField = firstNonEmptyPanelField($panelDatasets, 'uo_field');
    $allUoOrder = $scopeField !== '' ? buildFieldOrderFromPanelDatasets($panelDatasets, 'uo_field') : [];
    $scopeAllLabel = defaultScopeOptionLabel($scopeField);
    $selectedUo = trim((string) ($_GET['scope'] ?? ''));
    if ($selectedUo !== '' && !in_array($selectedUo, $allUoOrder, true)) {
        $selectedUo = '';
    }
    $showScopeFilter = $allUoOrder !== [];
    $defaultUoFilter = $selectedUo !== '' ? $selectedUo : $scopeAllLabel;
    $configuredOverviewPanels = buildCardPanels(
        $cardPanelConfigs,
        $grupos,
        $alertsConfig,
        $selectedSemester,
        $selectedUo
    );
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

    $presidenciaAulasPanel = $configuredOverviewPanels[0] ?? [];
    $presidenciaSumariosPanel = $configuredOverviewPanels[1] ?? [];
    $aulasTitle = (string) ($presidenciaAulasPanel['title'] ?? $aulasTitle);
    $sumariosTitle = (string) ($presidenciaSumariosPanel['title'] ?? $sumariosTitle);

    $pucRows = groupDataRows($grupos, ['resumo_estados_puc_pres', 'resumo_estados_puc'], $selectedSemester);
    $rucRows = groupDataRows($grupos, ['resumo_estados_ruc_pres', 'resumo_estados_ruc'], $selectedSemester);
    if ($pucRows !== []) {
        $summaryPanels[] = [
            'title' => groupLabel($grupos, ['resumo_estados_puc_pres', 'resumo_estados_puc'], 'Resumo Estados PUCs'),
            'items' => buildStateSummaryCards($pucRows, $estadoConfig, 'estado', 'num_ucs', $alertsConfig, 'estados_pucs'),
        ];
    }
    if ($rucRows !== []) {
        $summaryPanels[] = [
            'title' => groupLabel($grupos, ['resumo_estados_ruc_pres', 'resumo_estados_ruc'], 'Resumo Estados RUCs'),
            'items' => buildStateSummaryCards($rucRows, $estadoConfig, 'estado', 'num_ucs', $alertsConfig, 'estados_rucs'),
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
    $ucsSemDocenteTitleStyle = '';
} elseif ($selectedPage === 'dir_uo') {
    $replacementCards = buildReplacementCards(groupDataRows($grupos, ['pedidos_substituicao_dir']));
    $replacementTitle = groupLabel($grupos, ['pedidos_substituicao_dir'], 'Pedidos de Substituição');
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
        'items' => buildStateSummaryCards(groupDataRows($grupos, ['resumo_estados_puc_cc', 'resumo_estados_puc'], $selectedSemester), $estadoConfig, 'estado', 'num_ucs', $alertsConfig, 'estados_pucs'),
    ];
    $summaryPanels[] = [
        'title' => groupLabel($grupos, ['resumo_estados_ruc_cc', 'resumo_estados_ruc'], 'Resumo Estados RUCs'),
        'items' => buildStateSummaryCards(groupDataRows($grupos, ['resumo_estados_ruc_cc', 'resumo_estados_ruc'], $selectedSemester), $estadoConfig, 'estado', 'num_ucs', $alertsConfig, 'estados_rucs'),
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

    $stageGroup = findGroup($grupos, ['estagios']);
    $stageTitle = groupLabel($grupos, ['estagios'], 'Estágios');
    $stageMetric = buildStageMetric($stageGroup, $alertsConfig);
    if ($stageMetric === []) {
        $stageMessage = 'Sem estágios atribuídos.';
    }
}

if ($selectedPage === 'dir_uo') {
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
    $ucsSemDocenteTitleStyle = '';

    $dirUoStageGroup = findGroup($grupos, ['estagios']);
    $dirUoStageData = is_array($dirUoStageGroup['dados'] ?? null) ? $dirUoStageGroup['dados'] : [];
    $stageTitle = groupLabel($grupos, ['estagios'], 'Estágios');
    $dirUoStageLabelMap = [
        'qnt_estagio_protocolos_empresa_por_validar' => 'Protocolos Empresa Por Validar',
        'qnt_estagio_protocolos_aluno_por_validar' => 'Protocolos Aluno Por Validar',
        'qnt_estagios_nao_terminados' => 'Estágios Não Terminados',
    ];
    foreach ($dirUoStageLabelMap as $field => $label) {
        $value = $dirUoStageData[$field] ?? null;
        if (!is_int($value) && !is_float($value)) {
            continue;
        }
        $dirUoStageItems[] = [
            'label' => $label,
            'value' => (int) $value,
            'color' => resolveRangeColor($alertsConfig, $field, (int) $value),
        ];
    }
}

if ($selectedPage === 'pessoal') {
    $infoPessoalGroup = findGroup($grupos, ['info_pessoal']);
    $infoPessoalRows = is_array($infoPessoalGroup['dados'] ?? null) ? $infoPessoalGroup['dados'] : [];
    $infoPessoalRow = (isset($infoPessoalRows[0]) && is_array($infoPessoalRows[0])) ? $infoPessoalRows[0] : [];

    $adseInfo = buildValidityInfo((string) ($infoPessoalRow['DataValidadeADSE'] ?? ''), $alertsConfig);
    $biInfo = buildValidityInfo((string) ($infoPessoalRow['DataValidadeBI'] ?? ''), $alertsConfig);
    $pessoalInfoCards = [
        ['label' => 'Validade ADSE', 'date' => (string) $adseInfo['date'], 'status' => (string) $adseInfo['status'], 'color' => (string) $adseInfo['color']],
        ['label' => 'Validade BI', 'date' => (string) $biInfo['date'], 'status' => (string) $biInfo['status'], 'color' => (string) $biInfo['color']],
    ];

    $despachosGroup = findGroup($grupos, ['despachos_presidencia']);
    $despachosData = is_array($despachosGroup['dados'] ?? null) ? $despachosGroup['dados'] : [];
    $pessoalDespachosPorLer = (int) ($despachosData['qnt_por_ler'] ?? 0);
    $pessoalDespachosColor = resolveRangeColor($alertsConfig, 'qnt_por_ler', $pessoalDespachosPorLer);

    $gpedidosGroup = findGroup($grupos, ['gpedidos']);
    $gpedidosData = is_array($gpedidosGroup['dados'] ?? null) ? $gpedidosGroup['dados'] : [];
    $pessoalGestaoPedidos = [
        'mensagens' => (int) ($gpedidosData['qnt_msg_por_ler'] ?? 0),
        'tarefas' => (int) ($gpedidosData['qnt_tarefas'] ?? 0),
        'pedidos' => (int) ($gpedidosData['qnt_pedidos'] ?? 0),
    ];
    $pessoalGestaoPedidosColors = [
        'mensagens' => resolveRangeColor($alertsConfig, 'qnt_msg_por_ler', $pessoalGestaoPedidos['mensagens']),
        'tarefas' => resolveRangeColor($alertsConfig, 'qnt_tarefas', $pessoalGestaoPedidos['tarefas']),
        'pedidos' => resolveRangeColor($alertsConfig, 'qnt_pedidos', $pessoalGestaoPedidos['pedidos']),
    ];
} elseif ($selectedPage === 'gestao_documental') {
    $tarefasGroup = findGroup($grupos, ['tarefas']);
    $tarefasData = is_array($tarefasGroup['dados'] ?? null) ? $tarefasGroup['dados'] : [];

    $docTarefas = [
        'abertas' => (int) ($tarefasData['abertas'] ?? 0),
        'novas' => (int) ($tarefasData['novas'] ?? 0),
        'por_submeter' => (int) ($tarefasData['por_submeter'] ?? 0),
        'processos_abertos' => (int) ($tarefasData['processos_abertos'] ?? 0),
    ];
    $docTarefasColors = [
        'abertas' => resolveRangeColor($alertsConfig, 'abertas', $docTarefas['abertas']),
        'novas' => resolveRangeColor($alertsConfig, 'novas', $docTarefas['novas']),
        'por_submeter' => resolveRangeColor($alertsConfig, 'por_submeter', $docTarefas['por_submeter']),
        'processos_abertos' => resolveRangeColor($alertsConfig, 'processos_abertos', $docTarefas['processos_abertos']),
    ];

    $docResumoTotal = $docTarefas['abertas'] + $docTarefas['novas'];
} elseif ($selectedPage === 'gac') {
    $gacRows = groupDataRows($grupos, ['pedidos_substituicao_gac', 'pedidos_substituicao']);
    $numPedidosRules = is_array($alertsConfig['num_pedidos'] ?? null) ? $alertsConfig['num_pedidos'] : [];

    foreach ($gacRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $numPedidos = (int) ($row['num_pedidos'] ?? 0);
        $rule = findRangeRule($numPedidosRules, $numPedidos);
        $gacPedidos[] = [
            'data_aula' => formatDateValue((string) ($row['data_aula'] ?? '')),
            'ano_letivo' => formatAcademicYear((string) ($row['cd_letivo'] ?? '')),
            'num_pedidos' => $numPedidos,
            'color' => resolveRangeColor($alertsConfig, 'num_pedidos', $numPedidos) ?: (string) ($rule['color'] ?? '#22c55e'),
        ];
    }
}

$renderedGroupLookup = normalizedGroupLookup(cardPanelGroupKeys($cardPanelConfigs));
if ($selectedPage === 'presidencia') {
    foreach (['resumo_estados_puc_pres', 'resumo_estados_ruc_pres', 'ucs_sem_docente'] as $groupKey) {
        appendSkippedGroup($renderedGroupLookup, $groupKey);
    }
} elseif ($selectedPage === 'cc') {
    foreach ([
        'resumo_estados_puc_cc',
        'resumo_estados_ruc_cc',
        'estado_pucs_cc',
        'estado_rucs_cc',
        'pedidos_substituicao',
        'estagios',
    ] as $groupKey) {
        appendSkippedGroup($renderedGroupLookup, $groupKey);
    }
} elseif ($selectedPage === 'docente') {
    foreach (['estado_pucs_docente', 'estado_rucs_docente', 'estagios'] as $groupKey) {
        appendSkippedGroup($renderedGroupLookup, $groupKey);
    }
} elseif ($selectedPage === 'dir_uo') {
    foreach (['pedidos_substituicao_dir', 'ucs_sem_docente', 'estagios'] as $groupKey) {
        appendSkippedGroup($renderedGroupLookup, $groupKey);
    }
}

$summaryPanels = array_merge(
    $summaryPanels,
    buildAutomaticSummaryPanels($grupos, $renderedGroupLookup, $estadoConfig, $alertsConfig, $selectedSemester)
);
$entrySections = array_merge(
    $entrySections,
    buildAutomaticEntrySections($grupos, $renderedGroupLookup, $estadoConfig, $selectedSemester)
);
$autoMetricPanels = buildMetricOnlyPanels($grupos, $alertsConfig, $renderedGroupLookup);
$autoDetailPanels = buildDetailTablePanels($grupos, $renderedGroupLookup, $selectedSemester, $selectedUo, $scopeField);
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
            padding: 14px 36px 0;
            border-bottom: 1px solid #d6dbe3;
            position: sticky;
            top: 74px;
            z-index: 25;
            background: #fff;
        }

        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 10px;
            padding-bottom: 6px;
        }

        .tab {
            display: inline-flex;
            align-items: center;
            padding: 7px 10px;
            border-radius: 0;
            border: 1px solid transparent;
            background: transparent;
            color: #111827;
            font-size: 0.82rem;
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
            min-width: 0;
            max-width: 100%;
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
            display: flex;
            margin-top: 15px;
            font-size: clamp(1.05rem, 2vw, 1.35rem);
            letter-spacing: -0.05em;
            line-height: 0.95;
        }

        .hero-controls {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-top: 6px;
        }

        .filter-form {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .filter-dropdown {
            position: relative;
        }

        .filter-pill {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            min-width: 176px;
            height: 36px;
            padding: 0 12px;
            border: 0;
            border-radius: 8px;
            background: #f2f2f4;
            color: #111827;
            font-size: 0.78rem;
            font-weight: 600;
            gap: 12px;
            cursor: pointer;
        }

        .filter-trigger {
            width: 100%;
        }

        .filter-menu {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            min-width: 176px;
            padding: 6px;
            border: 1px solid #d9e1ec;
            border-radius: 10px;
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
            padding: 9px 10px;
            font-size: 0.78rem;
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
            min-width: 0;
            max-width: 100%;
            display: grid;
            gap: 22px;
        }

        .personalization-bar {
            margin: -8px 0 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 10px 14px;
            border: 1px dashed #cbd5e1;
            border-radius: 14px;
            background: #f8fafc;
            color: #41536d;
            font-size: 0.86rem;
        }

        .personalization-bar button {
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            padding: 7px 12px;
            background: #fff;
            color: var(--text);
            font-weight: 700;
            cursor: pointer;
        }

        .dashboard-sortable [data-sortable-panel] {
            cursor: grab;
            transition: transform 0.16s ease, box-shadow 0.16s ease, opacity 0.16s ease;
        }

        .dashboard-sortable [data-sortable-panel]:active {
            cursor: grabbing;
        }

        .dashboard-sortable [data-sortable-panel].is-dragging {
            opacity: 0.55;
            transform: scale(0.99);
            box-shadow: 0 18px 42px rgba(15, 35, 68, 0.16);
        }

        .dashboard-sortable [data-sortable-panel].is-drag-over {
            outline: 2px dashed #64748b;
            outline-offset: 6px;
        }

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .metric-card {
            background: #fff;
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

        .stage-card-grid {
            grid-template-columns: repeat(auto-fit, minmax(160px, 180px));
            justify-content: space-between;
        }

        .stage-card-grid .summary-card {
            min-height: 86px;
            padding: 14px 16px;
        }

        .stage-card-grid .summary-label {
            line-height: 1.35;
        }

        .stage-card-grid .summary-value {
            font-size: 1.8rem;
        }

        .summary-card {
            background: #fff;
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

        .pessoal-grid-two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .pessoal-grid-three {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .pessoal-card,
        .doc-card {
            background: #fff;
            border-color: var(--line);
            color: var(--text);
            text-align: left;
            min-height: 70px;
            padding: 10px 12px;
        }

        .pessoal-card .summary-label,
        .pessoal-card .metric-unit,
        .doc-card .summary-label,
        .doc-card .metric-unit {
            color: var(--text);
        }

        .pessoal-grid-two .pessoal-card,
        .pessoal-grid-three .pessoal-card {
            align-items: center;
            text-align: center;
        }

        .pessoal-card .summary-label,
        .doc-card .summary-label {
            font-size: 0.82rem;
            opacity: 0.95;
        }

        .pessoal-card .summary-value,
        .doc-card .summary-value {
            margin-top: 4px;
            font-size: 1.55rem;
        }

        .pessoal-card .metric-unit,
        .doc-card .metric-unit {
            margin-top: 3px;
            font-size: 0.72rem;
        }

        .pessoal-single {
            grid-template-columns: minmax(0, 1fr);
        }

        .pessoal-single .pessoal-card {
            min-height: 56px;
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 12px;
        }

        .pessoal-single .summary-value {
            margin-top: 0;
            font-size: 1.45rem;
        }

        .doc-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .doc-card {
            min-height: 86px;
            text-align: center;
            align-items: center;
            padding: 14px 16px;
        }

        .doc-card .summary-label {
            line-height: 1.35;
        }

        .doc-card .summary-value {
            font-size: 1.75rem;
        }

        .gac-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
            gap: 12px;
        }

        .gac-scroll-list {
            max-height: 640px;
            overflow: auto;
            padding-right: 10px;
        }

        .gac-scroll-list::-webkit-scrollbar {
            width: 10px;
        }

        .gac-scroll-list::-webkit-scrollbar-track {
            background: #eef3f8;
            border-radius: 999px;
        }

        .gac-scroll-list::-webkit-scrollbar-thumb {
            background: #3b4f69;
            border-radius: 999px;
        }

        .gac-item {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 12px;
            text-align: left;
        }

        .gac-item-title {
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .gac-item-subtitle {
            font-size: 0.72rem;
            color: #7d8ba0;
        }

        .gac-item-num {
            text-align: right;
            line-height: 1.05;
        }

        .gac-item-num strong {
            display: block;
            font-size: 1.8rem;
            letter-spacing: -0.03em;
        }

        .gac-item-num span {
            font-size: 0.7rem;
            color: #7d8ba0;
        }

        .pres-overview-stats {
            min-width: 0;
            max-width: 100%;
            display: grid;
            gap: 18px;
            margin-bottom: 18px;
        }

        .pres-overview-stats.three {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .pres-overview-stats.two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .pres-overview-card {
            width: 100%;
            max-width: none;
            min-height: 0;
            padding: 16px 18px;
        }

        .pres-overview-card .summary-label {
            font-size: 0.74rem;
            line-height: 1.25;
        }

        .pres-overview-card .summary-value {
            font-size: 1.55rem;
        }

        .pres-overview-table th,
        .pres-overview-table td {
            padding: 14px 16px;
        }

        .pres-overview-table th {
            background: #f8fafc;
            font-size: 0.72rem;
            font-weight: 700;
            color: #5c6d86;
        }

        .pres-overview-table td {
            font-size: 0.78rem;
        }

        .pres-overview-table .uo-column {
            width: 22%;
        }

        .pres-overview-table .metric-column {
            width: 26%;
            text-align: center;
        }

        .pres-overview-table .metric-column span {
            display: inline-block;
            text-align: center;
            line-height: 1.35;
        }

        .pres-overview-table .metric-cell {
            text-align: center;
            font-weight: 700;
        }

        .panel-dropdown {
            padding: 0;
            overflow: hidden;
        }

        .panel-dropdown summary {
            list-style: none;
            cursor: pointer;
        }

        .panel-dropdown summary::-webkit-details-marker {
            display: none;
        }

        .panel-dropdown-summary {
            min-height: 88px;
            padding: 26px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .panel-dropdown-summary .panel-title {
            margin: 0;
            font-size: 0.96rem;
        }

        .panel-dropdown-toggle {
            width: 22px;
            height: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            transition: transform 0.16s ease;
        }

        .panel-dropdown[open] .panel-dropdown-toggle {
            transform: rotate(180deg);
        }

        .panel-dropdown-body {
            border-top: 1px solid #e5eaf1;
            padding: 24px 30px 30px;
        }

        .page-presidencia .pres-overview-table th,
        .page-presidencia .pres-overview-table td {
            padding-left: 18px;
            padding-right: 18px;
        }

        .page-cc .pres-overview-table .uo-column {
            width: 22%;
        }

        .page-cc .pres-overview-table .metric-column {
            width: 39%;
            text-align: center;
        }

        .page-cc .pres-overview-table .metric-column span {
            text-align: center;
        }

        .page-cc .pres-overview-table .metric-cell {
            text-align: center;
        }

        .panel {
            min-width: 0;
            max-width: 100%;
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
            font-size: 0.96rem;
            letter-spacing: -0.04em;
        }

        .panel-title small {
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 500;
            letter-spacing: normal;
        }

        .title-icon {
            width: 28px;
            height: 28px;
            color: #334155;
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

        .card-blue,
        .card-red,
        .card-orange,
        .card-green {
            background: #fff;
            border-color: var(--line);
            color: var(--text);
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

        .col-blue,
        .col-green,
        .col-red,
        .col-orange {
            color: var(--text);
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
            background: #fff;
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

        .dot-red,
        .dot-blue,
        .dot-amber,
        .dot-rose,
        .dot-green,
        .dot-slate {
            background: #334155;
        }

        .state-red,
        .state-blue,
        .state-amber,
        .state-rose,
        .state-green,
        .state-slate {
            background: #fff;
            border-color: var(--line);
            color: var(--text);
        }

        .entry-list {
            display: grid;
            gap: 12px;
        }

        .scroll-entry-list {
            max-height: 520px;
            overflow: auto;
            padding-right: 10px;
        }

        .scroll-entry-list::-webkit-scrollbar {
            width: 10px;
        }

        .scroll-entry-list::-webkit-scrollbar-track {
            background: #eef3f8;
            border-radius: 999px;
        }

        .scroll-entry-list::-webkit-scrollbar-thumb {
            background: #3b4f69;
            border-radius: 999px;
        }

        .entry-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px 16px;
            display: grid;
            gap: 6px;
        }

        .entry-title {
            font-size: 0.82rem;
            line-height: 1.35;
        }

        .entry-subtitle,
        .entry-state,
        .entry-meta {
            font-size: 0.72rem;
            opacity: 0.9;
        }

        .replacement-card .entry-title {
            font-size: 0.8rem;
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
            font-size: 0.78rem;
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
            font-size: 0.78rem;
        }

        .uc-item {
            padding: 14px 16px;
            border-left: 3px solid #d9e1ec;
            border-radius: 0 12px 12px 0;
            background: #f8fafc;
            font-size: 0.82rem;
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

        .page-presidencia .summary-card,
        .page-presidencia .metric-card,
        .page-presidencia .summary-state-item,
        .page-presidencia .entry-card,
        .page-presidencia .pres-overview-card {
            background: #fff;
            border-color: var(--line);
            color: var(--text);
        }

        .page-presidencia .metric-column,
        .page-presidencia .metric-cell,
        .page-presidencia .summary-value,
        .page-presidencia .summary-state-item strong,
        .page-presidencia .entry-title {
            color: var(--text);
        }

        .page-presidencia .title-icon {
            color: #334155;
        }

        .page-presidencia .metric-badge,
        .page-presidencia .metric-status,
        .page-presidencia .sigla-tag {
            background: #f3f6fa;
            color: #334155;
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
            .pessoal-grid-two,
            .pessoal-grid-three,
            .doc-grid,
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
                font-size: 0.82rem;
            }

            .panel {
                padding: 22px 18px;
            }
        }
    </style>
</head>
<body class="page-<?= e($selectedPage) ?>">
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
                                    <div class="filter-menu" role="menu" aria-label="Filtro de <?= e(humanizeFieldLabel($scopeField)) ?>">
                                        <button class="filter-option<?= $selectedUo === '' ? ' is-selected' : '' ?>" type="button" data-filter-name="scope" data-filter-value="">
                                                <span><?= e($scopeAllLabel) ?></span>
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

                    <div class="personalization-bar" data-personalization-bar>
                        <span>Personalização: mantém pressionado um bloco e arrasta para reorganizar esta dashboard.</span>
                        <button type="button" data-reset-dashboard-layout>Repor ordem</button>
                    </div>

                    <div class="main-grid dashboard-sortable" data-dashboard-sortable data-sortable-profile="<?= e($selectedPage) ?>">
                        <?php if ($selectedPage === 'pessoal'): ?>
                            <section class="panel" data-sortable-panel data-panel-id="pessoal-info" draggable="true">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('chart') ?>
                                    Informa&ccedil;&atilde;o Pessoal
                                </h2>
                                <div class="summary-card-grid pessoal-grid-two">
                                    <?php foreach ($pessoalInfoCards as $card): ?>
                                        <article class="summary-card pessoal-card">
                                            <span class="summary-label"><?= e((string) $card['label']) ?></span>
                                            <strong class="summary-value"<?= ($style = buildTextColorStyle((string) $card['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= e((string) $card['date']) ?></strong>
                                            <span class="metric-unit"><?= e((string) $card['status']) ?></span>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>

                            <section class="panel" data-sortable-panel data-panel-id="pessoal-despachos" draggable="true">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('document') ?>
                                    Despachos da Presid&ecirc;ncia
                                </h2>
                                <div class="summary-card-grid pessoal-single">
                                    <article class="summary-card pessoal-card">
                                        <span class="summary-label">Despachos por ler</span>
                                        <strong class="summary-value"<?= ($style = buildTextColorStyle($pessoalDespachosColor)) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= number_format($pessoalDespachosPorLer, 0, ',', '.') ?></strong>
                                    </article>
                                </div>
                            </section>

                            <section class="panel" data-sortable-panel data-panel-id="pessoal-gestao-pedidos" draggable="true">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('chart') ?>
                                    Gest&atilde;o de Pedidos
                                </h2>
                                <div class="summary-card-grid pessoal-grid-three">
                                    <article class="summary-card pessoal-card">
                                        <span class="summary-label">Mensagens por Ler</span>
                                        <strong class="summary-value"<?= ($style = buildTextColorStyle($pessoalGestaoPedidosColors['mensagens'])) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= number_format((int) $pessoalGestaoPedidos['mensagens'], 0, ',', '.') ?></strong>
                                    </article>
                                    <article class="summary-card pessoal-card">
                                        <span class="summary-label">Tarefas Pendentes</span>
                                        <strong class="summary-value"<?= ($style = buildTextColorStyle($pessoalGestaoPedidosColors['tarefas'])) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= number_format((int) $pessoalGestaoPedidos['tarefas'], 0, ',', '.') ?></strong>
                                    </article>
                                    <article class="summary-card pessoal-card">
                                        <span class="summary-label">Pedidos</span>
                                        <strong class="summary-value"<?= ($style = buildTextColorStyle($pessoalGestaoPedidosColors['pedidos'])) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= number_format((int) $pessoalGestaoPedidos['pedidos'], 0, ',', '.') ?></strong>
                                    </article>
                                </div>
                            </section>
                        <?php elseif ($selectedPage === 'gestao_documental'): ?>
                            <section class="panel" data-sortable-panel data-panel-id="gestao-documental-tarefas" draggable="true">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('document') ?>
                                    Tarefas
                                </h2>

                                <div class="doc-grid">
                                    <article class="summary-card doc-card">
                                        <span class="summary-label">Abertas</span>
                                        <strong class="summary-value"<?= ($style = buildTextColorStyle($docTarefasColors['abertas'])) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= number_format((int) $docTarefas['abertas'], 0, ',', '.') ?></strong>
                                        <span class="metric-unit">Tarefas em andamento</span>
                                    </article>
                                    <article class="summary-card doc-card">
                                        <span class="summary-label">Novas</span>
                                        <strong class="summary-value"<?= ($style = buildTextColorStyle($docTarefasColors['novas'])) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= number_format((int) $docTarefas['novas'], 0, ',', '.') ?></strong>
                                        <span class="metric-unit">Recentemente atribuidas</span>
                                    </article>
                                    <article class="summary-card doc-card">
                                        <span class="summary-label">Por Submeter</span>
                                        <strong class="summary-value"<?= ($style = buildTextColorStyle($docTarefasColors['por_submeter'])) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= number_format((int) $docTarefas['por_submeter'], 0, ',', '.') ?></strong>
                                        <span class="metric-unit">Aguardam submissao</span>
                                    </article>
                                    <article class="summary-card doc-card">
                                        <span class="summary-label">Processos Abertos</span>
                                        <strong class="summary-value"<?= ($style = buildTextColorStyle($docTarefasColors['processos_abertos'])) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= number_format((int) $docTarefas['processos_abertos'], 0, ',', '.') ?></strong>
                                        <span class="metric-unit">Processos ativos</span>
                                    </article>
                                </div>
                            </section>
                        <?php elseif ($selectedPage === 'gac'): ?>
                            <section class="panel" data-sortable-panel data-panel-id="gac-pedidos-substituicao" draggable="true">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('document') ?>
                                    Pedidos de Substitui&ccedil;&atilde;o
                                </h2>

                                <?php if ($gacPedidos !== []): ?>
                                    <div class="gac-list gac-scroll-list">
                                        <?php foreach ($gacPedidos as $pedido): ?>
                                            <article class="gac-item">
                                                <div>
                                                    <div class="gac-item-title">Data da Aula: <?= e((string) $pedido['data_aula']) ?></div>
                                                    <div class="gac-item-subtitle">Ano Letivo: <?= e((string) $pedido['ano_letivo']) ?></div>
                                                </div>
                                                <div class="gac-item-num"<?= ($style = buildTextColorStyle((string) $pedido['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                    <strong><?= number_format((int) $pedido['num_pedidos'], 0, ',', '.') ?></strong>
                                                    <span>pedidos</span>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="muted">Sem pedidos para apresentar.</p>
                                <?php endif; ?>
                            </section>
                        <?php else: ?>
                        <?php if ($configuredOverviewPanels !== []): ?>
                            <?php foreach ($configuredOverviewPanels as $overviewPanel): ?>
                                <?php $items = is_array($overviewPanel['items'] ?? null) ? $overviewPanel['items'] : []; ?>
                                <details class="panel panel-dropdown" data-sortable-panel data-panel-id="<?= e((string) ($overviewPanel['id'] ?? $overviewPanel['title'] ?? 'overview')) ?>" draggable="true">
                                    <summary>
                                        <div class="panel-dropdown-summary">
                                            <h2 class="panel-title">
                                                <?= panelIconSvg((string) ($overviewPanel['icon'] ?? 'chart')) ?>
                                                <?= e((string) ($overviewPanel['title'] ?? '')) ?>
                                            </h2>
                                            <svg class="panel-dropdown-toggle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M6 9l6 6 6-6"></path>
                                            </svg>
                                        </div>
                                    </summary>

                                    <div class="panel-dropdown-body">

                                    <?php if ($items !== []): ?>
                                        <?php $statsClass = count($items) === 3 ? 'three' : (count($items) === 2 ? 'two' : ''); ?>
                                        <div class="pres-overview-stats <?= e($statsClass) ?>">
                                            <?php foreach ($items as $item): ?>
                                                <?php $totalValue = (int) ($overviewPanel['totals'][$item['key']] ?? 0); ?>
                                                <?php $totalColor = resolveMetricColor($alertsConfig, $item, $totalValue); ?>
                                                <article class="summary-card pres-overview-card">
                                                    <span class="summary-label"><?= e((string) $item['label']) ?></span>
                                                    <strong class="summary-value"<?= ($style = buildTextColorStyle($totalColor)) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= number_format($totalValue, 0, ',', '.') ?></strong>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if ((bool) ($overviewPanel['show_table'] ?? false) && ($overviewPanel['uo_order'] ?? []) !== []): ?>
                                            <div class="table-shell">
                                                <table class="pres-overview-table">
                                                    <thead>
                                                        <tr>
                                                            <th class="uo-column"><?= e((string) ($overviewPanel['group_label'] ?? 'Grupo')) ?></th>
                                                            <?php foreach ($items as $item): ?>
                                                                <th class="metric-column">
                                                                    <span><?= e((string) $item['label']) ?></span>
                                                                </th>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($overviewPanel['uo_order'] as $uo): ?>
                                                            <tr>
                                                                <td>
                                                                    <div class="uo-cell" title="<?= e(schoolTitle($alertsConfig, (string) $uo)) ?>"<?= ($style = pageSchoolTextStyle($selectedPage, $alertsConfig, (string) $uo)) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                                        <?= uoIconSvg() ?>
                                                                        <span><?= e((string) $uo) ?></span>
                                                                    </div>
                                                                </td>
                                                                <?php foreach ($items as $item): ?>
                                                                    <?php $cellValue = (int) ($overviewPanel['rows_by_uo'][$uo][$item['key']] ?? 0); ?>
                                                                    <?php $cellColor = resolveMetricColor($alertsConfig, $item, $cellValue, 'table_ranges'); ?>
                                                                    <td class="metric-cell"<?= ($style = buildTextColorStyle($cellColor)) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                                        <strong><?= formatMetricValue($cellValue, (string) ($item['empty_display'] ?? 'zero')) ?></strong>
                                                                    </td>
                                                                <?php endforeach; ?>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p class="muted">Sem dados para apresentar.</p>
                                    <?php endif; ?>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <section class="panel" data-sortable-panel data-panel-id="aulas" draggable="true">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('chart') ?>
                                    <?= e($aulasTitle) ?>
                                </h2>

                                <?php if ($aulasCards !== []): ?>
                                    <div class="metric-grid">
                                        <?php foreach ($aulasCards as $card): ?>
                                            <article class="metric-card"<?= ($style = pageCardStyle($selectedPage, (string) $card['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
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

                            <section class="panel" data-sortable-panel data-panel-id="sumarios" draggable="true">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('document') ?>
                                    <?= e($sumariosTitle) ?>
                                </h2>

                                <?php if ($sumariosCards !== []): ?>
                                    <div class="summary-card-grid">
                                        <?php foreach ($sumariosCards as $card): ?>
                                            <article class="summary-card"<?= ($style = pageCardStyle($selectedPage, (string) $card['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
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
                            <div class="mini-grid" data-sortable-panel data-panel-id="resumo-estados" draggable="true">
                                <?php foreach ($summaryPanels as $panel): ?>
                                    <?php if ($panel['items'] === []) { continue; } ?>
                                    <section class="panel panel-compact">
                                        <h2 class="panel-title">
                                            <?= panelIconSvg('document') ?>
                                            <?= e((string) $panel['title']) ?>
                                        </h2>

                                        <div class="summary-state-list">
                                            <?php foreach ($panel['items'] as $item): ?>
                                                <div class="summary-state-item">
                                                    <span><?= e((string) $item['label']) ?></span>
                                                    <strong<?= ($style = buildTextColorStyle((string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= number_format((int) $item['count'], 0, ',', '.') ?></strong>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($entrySections !== []): ?>
                            <div class="mini-grid<?= count($entrySections) === 1 ? ' mini-grid-single' : '' ?>" data-sortable-panel data-panel-id="estados-detalhe" draggable="true">
                                <?php foreach ($entrySections as $section): ?>
                                    <section class="panel">
                                        <h2 class="panel-title">
                                            <?= panelIconSvg('document') ?>
                                            <?= e((string) $section['title']) ?>
                                        </h2>

                                        <?php if ($section['items'] !== []): ?>
                                            <div class="entry-list">
                                                <?php foreach ($section['items'] as $item): ?>
                                                    <article class="entry-card"<?= ($style = pageCardStyle($selectedPage, (string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
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
                            <section class="panel" data-sortable-panel data-panel-id="pedidos-substituicao" draggable="true">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('document') ?>
                                    <?= e($replacementTitle) ?> <small>(<?= count($replacementCards) ?>)</small>
                                </h2>

                                <div class="entry-list<?= $selectedPage === 'cc' ? ' scroll-entry-list' : '' ?>">
                                    <?php foreach ($replacementCards as $item): ?>
                                        <article class="entry-card replacement-card">
                                            <strong class="entry-title"><?= e((string) $item['title']) ?></strong>
                                            <span class="entry-subtitle">Turno: <?= e((string) $item['turno']) ?> · Ano: <?= e((string) $item['ano_letivo']) ?></span>
                                            <span class="entry-meta">Aula: <?= e((string) $item['data_aula']) ?> → Definitiva: <?= e((string) $item['data_definitiva']) ?></span>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if (($selectedPage === 'presidencia' || $selectedPage === 'dir_uo') && $ucsSemDocenteTitle !== ''): ?>
                            <section class="panel" data-sortable-panel data-panel-id="ucs-sem-docente" draggable="true">
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

                        <?php if ($selectedPage === 'dir_uo' && $dirUoStageItems !== []): ?>
                            <section class="panel" data-sortable-panel data-panel-id="estagios-direcao" draggable="true">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('chart') ?>
                                    <?= e($stageTitle) ?>
                                </h2>

                                <div class="summary-card-grid stage-card-grid">
                                    <?php foreach ($dirUoStageItems as $item): ?>
                                        <article class="summary-card">
                                            <span class="summary-label"><?= e((string) $item['label']) ?></span>
                                            <strong class="summary-value"<?= ($style = buildTextColorStyle((string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>><?= number_format((int) $item['value'], 0, ',', '.') ?></strong>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if ($selectedPage === 'cc' || $selectedPage === 'docente'): ?>
                            <section class="panel" data-sortable-panel data-panel-id="estagios" draggable="true">
                                <h2 class="panel-title">
                                    <?= panelIconSvg('chart') ?>
                                    <?= e($stageTitle) ?>
                                </h2>

                                <?php if ($stageMetric !== []): ?>
                                    <div class="summary-card-grid summary-card-grid-single">
                                        <article class="summary-card"<?= ($style = pageCardStyle($selectedPage, (string) $stageMetric['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                            <span class="summary-label"><?= e((string) $stageMetric['label']) ?></span>
                                            <strong class="summary-value"><?= number_format((int) $stageMetric['value'], 0, ',', '.') ?></strong>
                                        </article>
                                    </div>
                                <?php else: ?>
                                    <p class="muted"><?= e($stageMessage !== '' ? $stageMessage : 'Sem dados para apresentar.') ?></p>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <?php if ($autoMetricPanels !== []): ?>
                            <?php foreach ($autoMetricPanels as $metricIndex => $metricPanel): ?>
                                <section class="panel" data-sortable-panel data-panel-id="auto-metric-<?= e((string) $metricIndex) ?>" draggable="true">
                                    <h2 class="panel-title">
                                        <?= panelIconSvg('chart') ?>
                                        <?= e((string) $metricPanel['title']) ?>
                                    </h2>

                                    <div class="summary-card-grid">
                                        <?php foreach ($metricPanel['items'] as $item): ?>
                                            <article class="summary-card"<?= ($style = pageCardStyle($selectedPage, (string) $item['color'])) !== '' ? ' style="' . e($style) . '"' : '' ?>>
                                                <span class="summary-label"><?= e((string) $item['label']) ?></span>
                                                <strong class="summary-value"><?= number_format((int) $item['value'], 0, ',', '.') ?></strong>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if ($autoDetailPanels !== []): ?>
                            <?php foreach ($autoDetailPanels as $detailIndex => $detailPanel): ?>
                                <section class="panel" data-sortable-panel data-panel-id="auto-detail-<?= e((string) $detailIndex) ?>" draggable="true">
                                    <h2 class="panel-title">
                                        <?= panelIconSvg('document') ?>
                                        <?= e((string) $detailPanel['title']) ?>
                                    </h2>

                                    <?php if (($detailPanel['rows'] ?? []) !== []): ?>
                                        <div class="table-shell">
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <?php foreach ($detailPanel['columns'] as $column): ?>
                                                            <th><?= e(humanizeFieldLabel((string) $column)) ?></th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($detailPanel['rows'] as $row): ?>
                                                        <tr>
                                                            <?php foreach ($detailPanel['columns'] as $column): ?>
                                                                <td><?= e(formatTableCellValue($row[$column] ?? null)) ?></td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <p class="muted">Sem dados para apresentar.</p>
                                    <?php endif; ?>
                                </section>
                            <?php endforeach; ?>
                        <?php endif; ?>
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

            const sortableGrid = document.querySelector('[data-dashboard-sortable]');
            if (sortableGrid) {
                const sortableProfile = sortableGrid.dataset.sortableProfile || 'dashboard';
                const storageKey = 'onipvc.' + sortableProfile + '.layout.v1';
                let draggedPanel = null;
                let autoScrollFrame = null;
                let autoScrollDirection = 0;
                const autoScrollSpeed = 6;

                function sortablePanels() {
                    return Array.from(sortableGrid.querySelectorAll('[data-sortable-panel]'));
                }

                function scrollContainer() {
                    return document.querySelector('.content-panel') || document.scrollingElement || document.documentElement;
                }

                function stopAutoScroll() {
                    autoScrollDirection = 0;
                    if (autoScrollFrame !== null) {
                        window.cancelAnimationFrame(autoScrollFrame);
                        autoScrollFrame = null;
                    }
                }

                function runAutoScroll() {
                    if (autoScrollDirection === 0 || !draggedPanel) {
                        stopAutoScroll();
                        return;
                    }

                    const container = scrollContainer();
                    container.scrollTop += autoScrollDirection * autoScrollSpeed;
                    autoScrollFrame = window.requestAnimationFrame(runAutoScroll);
                }

                function updateAutoScroll(pointerY) {
                    const container = scrollContainer();
                    const bounds = container.getBoundingClientRect ? container.getBoundingClientRect() : {
                        top: 0,
                        bottom: window.innerHeight
                    };
                    const edgeSize = 120;
                    let direction = 0;

                    if (pointerY < bounds.top + edgeSize) {
                        direction = -1;
                    } else if (pointerY > bounds.bottom - edgeSize) {
                        direction = 1;
                    }

                    if (direction === autoScrollDirection) {
                        return;
                    }

                    stopAutoScroll();
                    autoScrollDirection = direction;
                    if (autoScrollDirection !== 0) {
                        autoScrollFrame = window.requestAnimationFrame(runAutoScroll);
                    }
                }

                function savePanelOrder() {
                    const order = sortablePanels()
                        .map(function (panel) { return panel.dataset.panelId || ''; })
                        .filter(Boolean);
                    window.localStorage.setItem(storageKey, JSON.stringify(order));
                }

                function applySavedPanelOrder() {
                    let order = [];
                    try {
                        order = JSON.parse(window.localStorage.getItem(storageKey) || '[]');
                    } catch (error) {
                        order = [];
                    }

                    if (!Array.isArray(order) || order.length === 0) {
                        return;
                    }

                    const panelsById = new Map(sortablePanels().map(function (panel) {
                        return [panel.dataset.panelId, panel];
                    }));

                    order.forEach(function (panelId) {
                        const panel = panelsById.get(panelId);
                        if (panel) {
                            sortableGrid.appendChild(panel);
                        }
                    });
                }

                function panelAfterPointer(yPosition) {
                    const candidates = sortablePanels().filter(function (panel) {
                        return panel !== draggedPanel;
                    });

                    return candidates.reduce(function (closest, panel) {
                        const box = panel.getBoundingClientRect();
                        const offset = yPosition - box.top - (box.height / 2);

                        if (offset < 0 && offset > closest.offset) {
                            return { offset: offset, element: panel };
                        }

                        return closest;
                    }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
                }

                applySavedPanelOrder();

                sortablePanels().forEach(function (panel) {
                    panel.addEventListener('dragstart', function (event) {
                        draggedPanel = panel;
                        panel.classList.add('is-dragging');
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', panel.dataset.panelId || '');
                    });

                    panel.addEventListener('dragend', function () {
                        panel.classList.remove('is-dragging');
                        sortablePanels().forEach(function (item) {
                            item.classList.remove('is-drag-over');
                        });
                        draggedPanel = null;
                        stopAutoScroll();
                        savePanelOrder();
                    });
                });

                sortableGrid.addEventListener('dragover', function (event) {
                    if (!draggedPanel) {
                        return;
                    }

                    event.preventDefault();
                    updateAutoScroll(event.clientY);
                    const afterPanel = panelAfterPointer(event.clientY);
                    sortablePanels().forEach(function (panel) {
                        panel.classList.toggle('is-drag-over', panel === afterPanel);
                    });

                    if (afterPanel) {
                        sortableGrid.insertBefore(draggedPanel, afterPanel);
                    } else {
                        sortableGrid.appendChild(draggedPanel);
                    }
                });

                document.addEventListener('dragover', function (event) {
                    if (!draggedPanel) {
                        return;
                    }

                    updateAutoScroll(event.clientY);
                });

                document.addEventListener('drop', stopAutoScroll);

                const resetButton = document.querySelector('[data-reset-dashboard-layout]');
                if (resetButton) {
                    resetButton.addEventListener('click', function () {
                        window.localStorage.removeItem(storageKey);
                        window.location.reload();
                    });
                }
            }
        });
    </script>
</body>
</html>
