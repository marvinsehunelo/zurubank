<?php

$rootDir = realpath(__DIR__);

$ignore = [
    '.git',
    'vendor',
    'node_modules'
];

function shouldIgnore(string $relativePath, array $ignore): bool
{
    $relativePath = str_replace('\\', '/', $relativePath);

    foreach ($ignore as $ignored) {
        if (
            $relativePath === $ignored ||
            str_starts_with($relativePath, $ignored . '/')
        ) {
            return true;
        }
    }

    return false;
}

function listFiles(string $rootDir, string $currentDir, array $ignore): array
{
    $files = [];

    foreach (scandir($currentDir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $fullPath = $currentDir . DIRECTORY_SEPARATOR . $item;

        $relativePath = ltrim(
            str_replace($rootDir, '', $fullPath),
            DIRECTORY_SEPARATOR
        );

        $relativePath = str_replace('\\', '/', $relativePath);

        if (shouldIgnore($relativePath, $ignore)) {
            continue;
        }

        if (is_dir($fullPath)) {
            $files = array_merge(
                $files,
                listFiles($rootDir, $fullPath, $ignore)
            );
        } elseif (is_file($fullPath)) {
            $files[] = $relativePath;
        }
    }

    return $files;
}

try {

    $files = listFiles($rootDir, $rootDir, $ignore);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'status' => 'success',
        'count'  => count($files),
        'root'   => basename($rootDir),
        'files'  => $files
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
