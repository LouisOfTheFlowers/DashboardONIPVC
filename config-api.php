<?php
declare(strict_types=1);

const CONFIG_API_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'alertasconfig';

header('Content-Type: application/json; charset=utf-8');

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        handleGet();
        exit;
    }

    if ($method === 'POST') {
        handlePost();
        exit;
    }

    respond(['error' => 'Método não suportado.'], 405);
} catch (Throwable $exception) {
    respond(['error' => $exception->getMessage()], 500);
}

function handleGet(): void
{
    $file = trim((string) ($_GET['file'] ?? ''));

    if ($file === '') {
        $files = array_map(
            static fn (string $path): array => [
                'file' => basename($path),
                'label' => fileLabel(basename($path)),
                'modified_at' => date(DATE_ATOM, (int) filemtime($path)),
            ],
            listConfigFiles()
        );

        respond(['files' => $files]);
    }

    $path = resolveConfigPath($file);
    $config = readConfigFile($path);

    respond([
        'file' => basename($path),
        'label' => fileLabel(basename($path)),
        'config' => $config,
        'modified_at' => date(DATE_ATOM, (int) filemtime($path)),
    ]);
}

function handlePost(): void
{
    $input = file_get_contents('php://input');
    if ($input === false || trim($input) === '') {
        respond(['error' => 'Pedido sem conteúdo.'], 400);
    }

    $payload = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        respond(['error' => 'Pedido inválido.'], 400);
    }

    $file = trim((string) ($payload['file'] ?? ''));
    $config = $payload['config'] ?? null;

    if ($file === '' || !is_array($config)) {
        respond(['error' => 'Ficheiro ou configuração inválidos.'], 400);
    }

    $path = resolveConfigPath($file);
    writeConfigFile($path, $config);

    respond([
        'file' => basename($path),
        'label' => fileLabel(basename($path)),
        'config' => readConfigFile($path),
        'modified_at' => date(DATE_ATOM, (int) filemtime($path)),
        'message' => 'Configuração guardada.',
    ]);
}

function listConfigFiles(): array
{
    $paths = glob(CONFIG_API_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [];
    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

    return array_values(array_filter($paths, 'is_file'));
}

function resolveConfigPath(string $file): string
{
    $baseName = basename($file);
    if ($baseName !== $file || !preg_match('/^alerts[A-Za-z0-9]+config\.json$/', $baseName)) {
        respond(['error' => 'Ficheiro de configuração inválido.'], 400);
    }

    $path = CONFIG_API_DIR . DIRECTORY_SEPARATOR . $baseName;
    if (!is_file($path)) {
        respond(['error' => 'Ficheiro de configuração não encontrado.'], 404);
    }

    return $path;
}

function readConfigFile(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        respond(['error' => 'Não foi possível ler o ficheiro.'], 500);
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

function writeConfigFile(string $path, array $config): void
{
    $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        respond(['error' => 'Não foi possível codificar a configuração.'], 500);
    }

    $encoded .= PHP_EOL;
    $temporaryPath = $path . '.tmp';

    $handle = fopen($temporaryPath, 'wb');
    if ($handle === false) {
        respond(['error' => 'Não foi possível preparar a gravação.'], 500);
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            respond(['error' => 'Não foi possível bloquear o ficheiro.'], 500);
        }

        if (fwrite($handle, $encoded) === false) {
            respond(['error' => 'Não foi possível escrever o ficheiro.'], 500);
        }

        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    if (!rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        respond(['error' => 'Não foi possível substituir o ficheiro.'], 500);
    }
}

function fileLabel(string $file): string
{
    $label = preg_replace('/^alerts|config\.json$/', '', $file);
    $label = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', (string) $label);

    return trim($label) !== '' ? trim($label) : $file;
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
