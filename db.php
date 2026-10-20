<?php
declare(strict_types=1);

function cfg(string $key) {
    static $c = null;
    $c ??= require __DIR__ . '/config.php';
    return $c[$key];
}

function db_arg($v): array {
    if ($v === null)  return ['type' => 'null'];
    if (is_bool($v))  return ['type' => 'integer', 'value' => (string)(int)$v];
    if (is_int($v))   return ['type' => 'integer', 'value' => (string)$v];
    if (is_float($v)) return ['type' => 'float', 'value' => $v];
    return ['type' => 'text', 'value' => (string)$v];
}

/**
 * Run one statement against Turso via the Hrana-over-HTTP pipeline endpoint.
 * Returns ['rows' => list<assoc>, 'affected' => int, 'last_id' => ?int]
 */
function db(string $sql, array $args = []): array {
    $base = preg_replace('#^libsql://#', 'https://', rtrim(cfg('turso_url'), '/'));
    $body = json_encode([
        'requests' => [
            ['type' => 'execute', 'stmt' => ['sql' => $sql, 'args' => array_map('db_arg', array_values($args))]],
            ['type' => 'close'],
        ],
    ], JSON_THROW_ON_ERROR);

    $ch = curl_init($base . '/v2/pipeline');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . cfg('turso_token'),
            'Content-Type: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) throw new RuntimeException('Turso request failed: ' . curl_error($ch));
    curl_close($ch);
    if ($code !== 200) throw new RuntimeException("Turso HTTP $code: $raw");

    $res = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['results'][0] ?? null;
    if (!$res) throw new RuntimeException('Empty Turso response');
    if ($res['type'] === 'error') throw new RuntimeException($res['error']['message'] ?? 'Turso error');

    $r    = $res['response']['result'];
    $cols = array_column($r['cols'], 'name');
    $rows = [];
    foreach ($r['rows'] as $row) {
        $o = [];
        foreach ($row as $i => $cell) {
            $o[$cols[$i]] = match ($cell['type']) {
                'null'    => null,
                'integer' => (int)$cell['value'],
                'float'   => (float)$cell['value'],
                'blob'    => base64_decode($cell['base64'] ?? ''),
                default   => $cell['value'],
            };
        }
        $rows[] = $o;
    }
    return [
        'rows'     => $rows,
        'affected' => (int)($r['affected_row_count'] ?? 0),
        'last_id'  => isset($r['last_insert_rowid']) ? (int)$r['last_insert_rowid'] : null,
    ];
}
