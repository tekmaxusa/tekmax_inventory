<?php
/**
 * Shared product lookup by SKU, primary barcode, alternate barcodes, or RFID tag.
 */
declare(strict_types=1);

function product_code_sql(string $alias = 'p'): string
{
    return "(LOWER({$alias}.sku) = ?
            OR LOWER({$alias}.barcode) = ?
            OR ({$alias}.barcode IS NOT NULL AND {$alias}.barcode <> '' AND LOWER(TRIM(LEADING '0' FROM {$alias}.barcode)) = TRIM(LEADING '0' FROM ?))
            OR ({$alias}.rfid_tag IS NOT NULL AND {$alias}.rfid_tag <> '' AND LOWER({$alias}.rfid_tag) = ?)
            OR EXISTS (
                 SELECT 1 FROM product_barcodes pb
                 WHERE pb.product_id = {$alias}.product_id
                   AND (
                     LOWER(pb.barcode) = ?
                     OR LOWER(TRIM(LEADING '0' FROM pb.barcode)) = TRIM(LEADING '0' FROM ?)
                   )
               ))";
}

/** @return list<string> */
function product_code_params(string $code): array
{
    $needle = strtolower(trim($code));
    return [$needle, $needle, $needle, $needle, $needle, $needle];
}

/**
 * Normalize a list of barcode strings: trim, drop empties, unique (case-insensitive).
 *
 * @param mixed $raw
 * @return list<string>
 */
function normalize_barcode_list($raw): array
{
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        } else {
            $raw = preg_split('/[\r\n,;]+/', $raw) ?: [];
        }
    }
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    $seen = [];
    foreach ($raw as $item) {
        $code = trim((string) $item);
        if ($code === '') {
            continue;
        }
        $key = strtolower($code);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $code;
    }
    return $out;
}

/**
 * Parse barcodes from request body (barcodes[] / barcodes JSON + optional single barcode).
 *
 * @param array<string,mixed> $body
 * @return list<string>
 */
function barcodes_from_request(array $body): array
{
    $list = [];
    if (isset($body['barcodes'])) {
        $list = normalize_barcode_list($body['barcodes']);
    }
    $single = trim((string) ($body['barcode'] ?? ''));
    if ($single !== '') {
        $list = normalize_barcode_list(array_merge([$single], $list));
    }
    return $list;
}

/**
 * @return list<string>
 */
function product_barcodes_for(PDO $pdo, int $productId): array
{
    if ($productId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT barcode FROM product_barcodes WHERE product_id = ? ORDER BY is_primary DESC, id ASC'
    );
    $stmt->execute([$productId]);
    return array_map(static fn ($r) => (string) $r['barcode'], $stmt->fetchAll());
}

/**
 * Replace all barcodes for a product and sync products.barcode (primary).
 *
 * @param list<string> $barcodes
 */
function sync_product_barcodes(PDO $pdo, int $storeId, int $productId, array $barcodes): void
{
    $barcodes = normalize_barcode_list($barcodes);
    $pdo->prepare('DELETE FROM product_barcodes WHERE product_id = ?')->execute([$productId]);

    $primary = $barcodes[0] ?? null;
    $ins = $pdo->prepare(
        'INSERT INTO product_barcodes (store_id, product_id, barcode, is_primary) VALUES (?, ?, ?, ?)'
    );
    foreach ($barcodes as $i => $code) {
        $ins->execute([$storeId, $productId, $code, $i === 0 ? 1 : 0]);
    }

    $pdo->prepare('UPDATE products SET barcode = ? WHERE product_id = ? AND store_id = ?')
        ->execute([$primary, $productId, $storeId]);
}

/**
 * Attach barcodes[] (+ keep barcode as primary) onto a product row for JSON responses.
 *
 * @param array<string,mixed>|false|null $row
 * @return array<string,mixed>|null
 */
function with_product_barcodes(PDO $pdo, $row): ?array
{
    if (!$row || !is_array($row)) {
        return $row ?: null;
    }
    $id = (int) ($row['product_id'] ?? 0);
    $list = product_barcodes_for($pdo, $id);
    if ($list === []) {
        $primary = trim((string) ($row['barcode'] ?? ''));
        if ($primary !== '') {
            $list = [$primary];
        }
    }
    $row['barcodes'] = $list;
    $row['barcode'] = $list[0] ?? ($row['barcode'] ?? null);
    return $row;
}

/**
 * Resolve SKU + barcode list for create/update.
 * Rule: product name required elsewhere; here require SKU and/or at least one barcode.
 *
 * @param list<string> $barcodes
 * @return array{ok:bool,error?:string,sku:?string,barcodes:list<string>,primary:?string}
 */
function resolve_product_identifiers(string $sku, array $barcodes): array
{
    $sku = trim($sku);
    $barcodes = normalize_barcode_list($barcodes);

    if ($sku === '' && $barcodes === []) {
        return [
            'ok' => false,
            'error' => 'Provide a SKU and/or at least one barcode.',
            'sku' => null,
            'barcodes' => [],
            'primary' => null,
        ];
    }

    return [
        'ok' => true,
        'sku' => $sku === '' ? null : $sku,
        'barcodes' => $barcodes,
        'primary' => $barcodes[0] ?? null,
    ];
}
