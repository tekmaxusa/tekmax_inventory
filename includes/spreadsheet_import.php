<?php
/**
 * Shared CSV / XLSX import helpers (no Composer).
 */
declare(strict_types=1);

function import_normalize_header(string $header): string
{
    $key = strtolower(trim($header));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;
    return trim($key, '_');
}

/**
 * @return array<int, array<string, string>>
 */
function import_rows_from_csv_text(string $text): array
{
    $text = trim(str_replace("\r\n", "\n", str_replace("\r", "\n", $text)));
    if ($text === '') {
        return [];
    }

    $lines = explode("\n", $text);
    $rows = [];
    $headers = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $cols = str_getcsv($line);
        if ($cols === false || $cols === []) {
            continue;
        }
        $cols = array_map(static fn($v) => trim((string) $v), $cols);

        if ($headers === null) {
            $headers = array_map('import_normalize_header', $cols);
            if (count($headers) === 1 && $headers[0] === '') {
                $headers = ['value'];
                $rows[] = ['value' => $cols[0]];
            }
            continue;
        }

        $row = [];
        foreach ($headers as $i => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = $cols[$i] ?? '';
        }
        if (implode('', $row) !== '') {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * @return array<int, array<int, string>>
 */
function import_matrix_from_csv_text(string $text): array
{
    $text = trim(str_replace("\r\n", "\n", str_replace("\r", "\n", $text)));
    if ($text === '') {
        return [];
    }
    $matrix = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $cols = str_getcsv($line);
        if ($cols === false) {
            continue;
        }
        $matrix[] = array_map(static fn($v) => trim((string) $v), $cols);
    }
    return $matrix;
}

/**
 * Minimal XLSX reader — first worksheet only.
 *
 * @return array<int, array<int, string>>
 */
function import_matrix_from_xlsx(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('XLSX import requires the PHP Zip extension.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the Excel file.');
    }

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = @simplexml_load_string($sharedXml);
        if ($xml && isset($xml->si)) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } elseif (isset($si->r)) {
                    $parts = [];
                    foreach ($si->r as $run) {
                        $parts[] = (string) ($run->t ?? '');
                    }
                    $shared[] = implode('', $parts);
                } else {
                    $shared[] = '';
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Could not read the first worksheet in the Excel file.');
    }

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData->row)) {
        return [];
    }

    $matrix = [];
    foreach ($sheet->sheetData->row as $row) {
        $line = [];
        $colIndex = 0;
        foreach ($row->c as $cell) {
            $ref = (string) ($cell['r'] ?? '');
            $target = $colIndex;
            if ($ref !== '' && preg_match('/[A-Z]+/i', $ref, $m)) {
                $target = import_excel_col_to_index($m[0]);
            }

            $type = (string) ($cell['t'] ?? '');
            $value = '';
            if ($type === 's') {
                $idx = (int) ($cell->v ?? 0);
                $value = $shared[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            } else {
                $value = (string) ($cell->v ?? '');
            }

            while (count($line) <= $target) {
                $line[] = '';
            }
            $line[$target] = trim($value);
            $colIndex = max($colIndex, $target + 1);
        }
        if (implode('', $line) !== '') {
            $matrix[] = $line;
        }
    }

    return $matrix;
}

function import_excel_col_to_index(string $letters): int
{
    $letters = strtoupper($letters);
    $num = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $num - 1);
}

/**
 * @param array<int, array<int, string>> $matrix
 * @return array<int, array<string, string>>
 */
function import_assoc_rows_from_matrix(array $matrix): array
{
    if ($matrix === []) {
        return [];
    }
    $headers = array_map('import_normalize_header', $matrix[0]);
    $rows = [];
    for ($i = 1, $n = count($matrix); $i < $n; $i++) {
        $cols = $matrix[$i];
        $row = [];
        foreach ($headers as $j => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = trim((string) ($cols[$j] ?? ''));
        }
        if (implode('', $row) !== '') {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * @return array{ok:true,rows:array<int,array<string,string>>}|array{ok:false,error:string}
 */
function import_load_rows_from_request(): array
{
    $paste = trim((string) ($_POST['csv'] ?? ''));
    if ($paste !== '') {
        return ['ok' => true, 'rows' => import_rows_from_csv_text($paste)];
    }

    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        return ['ok' => false, 'error' => 'Upload a CSV/XLSX file or paste CSV text.'];
    }

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'File upload failed. Try again.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid uploaded file.'];
    }

    $name = strtolower((string) ($file['name'] ?? ''));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    try {
        if ($ext === 'xlsx') {
            $matrix = import_matrix_from_xlsx($tmp);
            return ['ok' => true, 'rows' => import_assoc_rows_from_matrix($matrix)];
        }

        $text = (string) file_get_contents($tmp);
        return ['ok' => true, 'rows' => import_rows_from_csv_text($text)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function import_row_value(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        $norm = import_normalize_header($key);
        if (array_key_exists($norm, $row) && trim((string) $row[$norm]) !== '') {
            return trim((string) $row[$norm]);
        }
    }
    return $default;
}

function import_send_csv_template(string $filename, array $headers, array $sampleRows = []): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    fputcsv($out, $headers);
    foreach ($sampleRows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
