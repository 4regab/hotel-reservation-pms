<?php
declare(strict_types=1);

// Writes docs/database/DDR.md (data dictionary: every table, column, constraint, view and stored procedure) and
// docs/database/LMR.md (logical model: the relationships, as diagrams) from the SQL Server catalog, so the documents
// always match the real databases. Run after the migrations: php scripts/gen-data-dictionary.php
// Env: DB_ADMIN_HOST, DB_ADMIN_PORT, DB_ADMIN_USER, DB_ADMIN_PASS, plus <SERVICE>_DB_NAME like scripts/migrate.php.
require dirname(__DIR__) . '/autoload.php';

$root = dirname(__DIR__);
$services = ['booking' => 'Booking', 'roomops' => 'Room Operations', 'billing' => 'Billing', 'portal' => 'Portal'];

$admin = new PDO(Lib\Db::dsn(getenv('DB_ADMIN_HOST') ?: '127.0.0.1', getenv('DB_ADMIN_PORT') ?: '1433'), (string) getenv('DB_ADMIN_USER'), (string) getenv('DB_ADMIN_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

const SQL_COLUMNS = "SELECT t.name AS tbl, c.name AS col,
    CASE WHEN ty.name IN ('nvarchar','nchar','varchar','char','varbinary','binary')
           THEN ty.name + '(' + CASE WHEN c.max_length = -1 THEN 'MAX' WHEN ty.name IN ('nvarchar','nchar') THEN CAST(c.max_length / 2 AS VARCHAR(10)) ELSE CAST(c.max_length AS VARCHAR(10)) END + ')'
         WHEN ty.name IN ('decimal','numeric') THEN ty.name + '(' + CAST(c.precision AS VARCHAR(5)) + ',' + CAST(c.scale AS VARCHAR(5)) + ')'
         WHEN ty.name IN ('datetime2','time','datetimeoffset') THEN ty.name + '(' + CAST(c.scale AS VARCHAR(5)) + ')'
         ELSE ty.name END AS data_type,
    c.is_nullable, c.is_identity, dc.definition AS default_def
  FROM sys.tables t
  JOIN sys.columns c ON c.object_id = t.object_id
  JOIN sys.types ty ON ty.user_type_id = c.user_type_id
  LEFT JOIN sys.default_constraints dc ON dc.parent_object_id = t.object_id AND dc.parent_column_id = c.column_id
 WHERE t.is_ms_shipped = 0 AND t.name <> 'schema_migrations'
 ORDER BY t.name, c.column_id";

const SQL_KEYS = "SELECT t.name AS tbl, kc.name AS cname, kc.type AS ctype, STRING_AGG(c.name, ', ') WITHIN GROUP (ORDER BY ic.key_ordinal) AS cols
  FROM sys.key_constraints kc
  JOIN sys.tables t ON t.object_id = kc.parent_object_id
  JOIN sys.index_columns ic ON ic.object_id = kc.parent_object_id AND ic.index_id = kc.unique_index_id
  JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
 WHERE t.name <> 'schema_migrations'
 GROUP BY t.name, kc.name, kc.type
 ORDER BY t.name, kc.name";

const SQL_CHECKS = "SELECT t.name AS tbl, cc.name AS cname, cc.definition
  FROM sys.check_constraints cc JOIN sys.tables t ON t.object_id = cc.parent_object_id
 ORDER BY t.name, cc.name";

const SQL_FKS = "SELECT fk.name AS cname, pt.name AS tbl, rt.name AS ref_tbl,
    STRING_AGG(pc.name, ', ') WITHIN GROUP (ORDER BY fkc.constraint_column_id) AS cols,
    STRING_AGG(rc.name, ', ') WITHIN GROUP (ORDER BY fkc.constraint_column_id) AS ref_cols,
    fk.delete_referential_action_desc AS on_delete
  FROM sys.foreign_keys fk
  JOIN sys.tables pt ON pt.object_id = fk.parent_object_id
  JOIN sys.tables rt ON rt.object_id = fk.referenced_object_id
  JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
  JOIN sys.columns pc ON pc.object_id = fkc.parent_object_id AND pc.column_id = fkc.parent_column_id
  JOIN sys.columns rc ON rc.object_id = fkc.referenced_object_id AND rc.column_id = fkc.referenced_column_id
 GROUP BY fk.name, pt.name, rt.name, fk.delete_referential_action_desc
 ORDER BY pt.name, fk.name";

const SQL_MODULES = "SELECT o.name, o.type AS otype
  FROM sys.objects o WHERE o.is_ms_shipped = 0 AND o.type IN ('V', 'P') ORDER BY o.type DESC, o.name";

const SQL_PARAMS = "SELECT o.name AS obj, p.name AS pname, TYPE_NAME(p.user_type_id) AS ptype, p.max_length, p.has_default_value
  FROM sys.parameters p JOIN sys.objects o ON o.object_id = p.object_id
 WHERE o.is_ms_shipped = 0 AND o.type = 'P' AND p.parameter_id > 0
 ORDER BY o.name, p.parameter_id";

const SQL_DEPS = "SELECT o.name AS obj, d.referenced_entity_name AS ent, MAX(CAST(d.is_selected AS INT)) AS sel, MAX(CAST(d.is_updated AS INT)) AS upd
  FROM sys.objects o
 CROSS APPLY sys.dm_sql_referenced_entities(N'dbo.' + o.name, N'OBJECT') d
 WHERE o.is_ms_shipped = 0 AND o.type IN ('V', 'P') AND d.referenced_entity_name IS NOT NULL
 GROUP BY o.name, d.referenced_entity_name
 ORDER BY o.name, d.referenced_entity_name";

/** @return list<array<string,mixed>> */
$rows = static fn (PDO $db, string $sql): array => $db->query($sql)->fetchAll();
$esc = static fn (string $s): string => str_replace(['|', "\n"], ['\\|', ' '], $s);

$model = [];
foreach ($services as $svc => $label) {
    $name = (string) getenv(strtoupper($svc) . '_DB_NAME');
    if ($name === '') {
        fwrite(STDERR, "missing " . strtoupper($svc) . "_DB_NAME\n");
        exit(2);
    }
    $admin->exec('USE [' . str_replace(']', ']]', $name) . ']');
    $m = ['label' => $label, 'db' => $name, 'tables' => [], 'keys' => [], 'checks' => [], 'fks' => [], 'objects' => [], 'params' => [], 'deps' => []];
    foreach ($rows($admin, SQL_COLUMNS) as $r) {
        $m['tables'][$r['tbl']][] = $r;
    }
    foreach ($rows($admin, SQL_KEYS) as $r) {
        $m['keys'][$r['tbl']][] = $r;
    }
    foreach ($rows($admin, SQL_CHECKS) as $r) {
        $m['checks'][$r['tbl']][] = $r;
    }
    foreach ($rows($admin, SQL_FKS) as $r) {
        $m['fks'][$r['tbl']][] = $r;
    }
    $m['objects'] = $rows($admin, SQL_MODULES);
    foreach ($rows($admin, SQL_PARAMS) as $r) {
        $m['params'][$r['obj']][] = $r;
    }
    try {
        foreach ($rows($admin, SQL_DEPS) as $r) {
            $m['deps'][$r['obj']][] = $r;
        }
    } catch (PDOException $e) {
        fwrite(STDERR, "[{$svc}] could not read what the procedures use: {$e->getMessage()}\n");
    }
    $model[$svc] = $m;
}

$kindOf = static function (array $m, string $entity): string {
    if (isset($m['tables'][$entity])) {
        return 'table';
    }
    foreach ($m['objects'] as $o) {
        if ($o['name'] === $entity) {
            return trim((string) $o['otype']) === 'V' ? 'view' : 'procedure';
        }
    }
    return 'other';
};

// ---------------------------------------------------------------- DDR.md
$ddr = "# Data Dictionary Report (DDR)\n\nGenerated from the SQL Server catalog by `php scripts/gen-data-dictionary.php`. Do not edit by hand.\n\n";
$ddr .= "Each service has its own database. Tables, columns, constraints, views and stored procedures below are exactly what the migrations built.\n\n";
$totals = ['tables' => 0, 'pk' => 0, 'fk' => 0, 'uq' => 0, 'df' => 0, 'ck' => 0, 'views' => 0, 'procs' => 0];
foreach ($model as $svc => $m) {
    $pk = $fk = $uq = $df = $ck = 0;
    foreach ($m['keys'] as $list) {
        foreach ($list as $k) {
            trim((string) $k['ctype']) === 'PK' ? $pk++ : $uq++;
        }
    }
    foreach ($m['fks'] as $list) {
        $fk += count($list);
    }
    foreach ($m['checks'] as $list) {
        $ck += count($list);
    }
    foreach ($m['tables'] as $cols) {
        foreach ($cols as $c) {
            $df += $c['default_def'] !== null ? 1 : 0;
        }
    }
    $views = count(array_filter($m['objects'], static fn (array $o): bool => trim((string) $o['otype']) === 'V'));
    $procs = count($m['objects']) - $views;
    $m['counts'] = compact('pk', 'fk', 'uq', 'df', 'ck', 'views', 'procs');
    $model[$svc] = $m;
    $totals['tables'] += count($m['tables']);
    foreach (['pk', 'fk', 'uq', 'df', 'ck', 'views', 'procs'] as $k) {
        $totals[$k] += $m['counts'][$k];
    }
}
$ddr .= "## Summary\n\n| Service | Database | Tables | Primary keys | Foreign keys | Unique | Defaults | Checks | Views | Stored procedures |\n| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |\n";
foreach ($model as $m) {
    $c = $m['counts'];
    $ddr .= "| {$m['label']} | `{$m['db']}` | " . count($m['tables']) . " | {$c['pk']} | {$c['fk']} | {$c['uq']} | {$c['df']} | {$c['ck']} | {$c['views']} | {$c['procs']} |\n";
}
$ddr .= "| **All** | | **{$totals['tables']}** | **{$totals['pk']}** | **{$totals['fk']}** | **{$totals['uq']}** | **{$totals['df']}** | **{$totals['ck']}** | **{$totals['views']}** | **{$totals['procs']}** |\n\n";

foreach ($model as $svc => $m) {
    $ddr .= "## {$m['label']} (`{$m['db']}`)\n\n";
    foreach ($m['tables'] as $table => $cols) {
        $pkCols = [];
        foreach ($m['keys'][$table] ?? [] as $k) {
            if (trim((string) $k['ctype']) === 'PK') {
                $pkCols = array_map('trim', explode(',', (string) $k['cols']));
            }
        }
        $fkCols = [];
        foreach ($m['fks'][$table] ?? [] as $f) {
            foreach (array_map('trim', explode(',', (string) $f['cols'])) as $c) {
                $fkCols[$c] = $f['ref_tbl'] . '.' . $f['ref_cols'];
            }
        }
        $ddr .= "### `{$table}`\n\n| Column | Type | Null | Default | Notes |\n| --- | --- | --- | --- | --- |\n";
        foreach ($cols as $c) {
            $notes = [];
            if (in_array($c['col'], $pkCols, true)) {
                $notes[] = 'primary key';
            }
            if (isset($fkCols[$c['col']])) {
                $notes[] = 'foreign key to ' . $fkCols[$c['col']];
            }
            if ($c['is_identity']) {
                $notes[] = 'identity';
            }
            $ddr .= "| `{$c['col']}` | {$c['data_type']} | " . ($c['is_nullable'] ? 'yes' : 'no') . ' | ' . ($c['default_def'] !== null ? '`' . $esc((string) $c['default_def']) . '`' : '') . ' | ' . implode(', ', $notes) . " |\n";
        }
        $rules = [];
        foreach ($m['keys'][$table] ?? [] as $k) {
            $rules[] = (trim((string) $k['ctype']) === 'PK' ? 'PRIMARY KEY' : 'UNIQUE') . " `{$k['cname']}` ({$k['cols']})";
        }
        foreach ($m['fks'][$table] ?? [] as $f) {
            $rules[] = "FOREIGN KEY `{$f['cname']}` ({$f['cols']}) references `{$f['ref_tbl']}` ({$f['ref_cols']}), on delete " . strtolower(str_replace('_', ' ', (string) $f['on_delete']));
        }
        foreach ($m['checks'][$table] ?? [] as $k) {
            $rules[] = "CHECK `{$k['cname']}` `" . $esc((string) $k['definition']) . '`';
        }
        if ($rules) {
            $ddr .= "\nConstraints:\n\n- " . implode("\n- ", $rules) . "\n";
        }
        $ddr .= "\n";
    }
    $ddr .= "### Views and stored procedures\n\n| Object | Kind | Parameters | Reads | Writes |\n| --- | --- | --- | --- | --- |\n";
    foreach ($m['objects'] as $o) {
        $kind = trim((string) $o['otype']) === 'V' ? 'view' : 'procedure';
        $params = array_map(static fn (array $p): string => $p['pname'] . ' ' . $p['ptype'] . ($p['has_default_value'] ? ' = default' : ''), $m['params'][$o['name']] ?? []);
        $reads = $writes = [];
        foreach ($m['deps'][$o['name']] ?? [] as $d) {
            $label = '`' . $d['ent'] . '` (' . $kindOf($m, (string) $d['ent']) . ')';
            if ((int) $d['upd'] === 1) {
                $writes[] = $label;
            } elseif ((int) $d['sel'] === 1 || $kind === 'view') {
                $reads[] = $label;
            }
        }
        $ddr .= "| `{$o['name']}` | {$kind} | " . $esc(implode(', ', $params)) . ' | ' . implode(', ', $reads) . ' | ' . implode(', ', $writes) . " |\n";
    }
    $ddr .= "\n";
}
if (!is_dir($root . '/docs/database')) {
    mkdir($root . '/docs/database', 0777, true);
}
file_put_contents($root . '/docs/database/DDR.md', $ddr);

// ---------------------------------------------------------------- LMR.md
$lmr = "# Logical Model of Relationships (LMR)\n\nGenerated from the SQL Server catalog by `php scripts/gen-data-dictionary.php`. Do not edit by hand.\n\n";
$lmr .= "Four small services, each with its own database. A line in a diagram is a real FOREIGN KEY constraint inside one database. Links between services are by id only and are listed after the diagrams.\n\n";
$pkOwner = [];
foreach ($model as $svc => $m) {
    foreach ($m['keys'] as $table => $list) {
        foreach ($list as $k) {
            if (trim((string) $k['ctype']) === 'PK' && !str_contains((string) $k['cols'], ',')) {
                $pkOwner[(string) $k['cols']][] = [$svc, $table];
            }
        }
    }
}
foreach ($model as $svc => $m) {
    $lmr .= "## {$m['label']} (`{$m['db']}`)\n\n```mermaid\nerDiagram\n";
    $fkSet = [];
    foreach ($m['fks'] as $table => $list) {
        foreach ($list as $f) {
            foreach (array_map('trim', explode(',', (string) $f['cols'])) as $c) {
                $fkSet[$table][$c] = true;
            }
        }
    }
    $pkSet = [];
    foreach ($m['keys'] as $table => $list) {
        foreach ($list as $k) {
            foreach (array_map('trim', explode(',', (string) $k['cols'])) as $c) {
                $pkSet[$table][$c] = trim((string) $k['ctype']) === 'PK' ? 'PK' : 'UK';
            }
        }
    }
    foreach ($m['tables'] as $table => $cols) {
        $lmr .= "  {$table} {\n";
        foreach ($cols as $c) {
            $mark = [];
            if (isset($pkSet[$table][$c['col']])) {
                $mark[] = $pkSet[$table][$c['col']];
            }
            if (isset($fkSet[$table][$c['col']])) {
                $mark[] = 'FK';
            }
            if ($mark) {
                $lmr .= '    ' . preg_replace('/[^a-z0-9_]/', '', strtolower((string) preg_replace('/\(.*$/', '', (string) $c['data_type']))) . ' ' . $c['col'] . ' ' . implode(',', $mark) . "\n";
            }
        }
        $lmr .= "  }\n";
    }
    foreach ($m['fks'] as $table => $list) {
        foreach ($list as $f) {
            $lmr .= "  {$f['ref_tbl']} ||--o{ {$table} : \"{$f['cname']}\"\n";
        }
    }
    $lmr .= "```\n\n";
    $lmr .= "| Table | Foreign key | Parent table | Columns |\n| --- | --- | --- | --- |\n";
    foreach ($m['fks'] as $table => $list) {
        foreach ($list as $f) {
            $lmr .= "| `{$table}` | `{$f['cname']}` | `{$f['ref_tbl']}` | {$f['cols']} → {$f['ref_cols']} |\n";
        }
    }
    $lmr .= "\n";
}
$lmr .= "## Links between services (by id, no database foreign key)\n\nA database cannot reference another database's table, so these columns hold the id of a row that another service owns. The owning service is the one whose table has that id as its primary key.\n\n| Service | Table | Column | Owner |\n| --- | --- | --- | --- |\n";
foreach ($model as $svc => $m) {
    foreach ($m['tables'] as $table => $cols) {
        foreach ($cols as $c) {
            $owners = array_filter($pkOwner[$c['col']] ?? [], static fn (array $o): bool => $o[0] !== $svc);
            $ownHere = array_filter($pkOwner[$c['col']] ?? [], static fn (array $o): bool => $o[0] === $svc);
            if ($owners && !$ownHere) {
                $o = array_values($owners)[0];
                $lmr .= "| {$m['label']} | `{$table}` | `{$c['col']}` | {$model[$o[0]]['label']} `{$o[1]}` |\n";
            }
        }
    }
}
$lmr .= "\n";
file_put_contents($root . '/docs/database/LMR.md', $lmr);
echo "wrote docs/database/DDR.md and docs/database/LMR.md\n";
