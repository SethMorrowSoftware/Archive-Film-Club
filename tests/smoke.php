<?php
/**
 * Minimal no-database smoke test.
 *
 * Boots bootstrap.php (env loader, hand-rolled autoloader, session, CSRF
 * helpers) and then force-loads every service/db/cache class so PHP has to
 * resolve inheritance, interfaces and traits. This catches fatal *link*
 * errors (a missing parent class, a trait conflict, an abstract-method
 * signature mismatch, a duplicated symbol across files) that a per-file
 * `php -l` syntax check cannot see.
 *
 * It deliberately does NOT connect to a database: classes are only loaded,
 * never instantiated, so no MySQL server or network access is required.
 * That keeps it runnable in CI and locally with no fixtures:
 *
 *     php tests/smoke.php
 *
 * Exit code 0 = everything loaded; non-zero = something failed (details on
 * stderr).
 */

error_reporting(E_ALL);
@ini_set('display_errors', '1');

$root = dirname(__DIR__);
$failures = [];

// ---------------------------------------------------------------------------
// 1) bootstrap.php must load without a fatal error.
// ---------------------------------------------------------------------------
try {
    require $root . '/bootstrap.php';
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: bootstrap.php failed to load: {$e->getMessage()}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 2) The helper functions bootstrap.php promises must exist.
// ---------------------------------------------------------------------------
$helpers = [
    'env', 'base_path', 'is_https', 'safe_host', 'safe_base_url',
    'app_cookie_path', 'csrf_token', 'csrf_verify', 'csrf_meta_tag',
    'afc_css_color',
];
foreach ($helpers as $fn) {
    if (!function_exists($fn)) {
        $failures[] = "bootstrap helper missing: {$fn}()";
    }
}

// afc_css_color() guards the inline <style> color interpolation on every
// page: only a hex color may pass, everything else collapses to the fallback.
if (function_exists('afc_css_color')) {
    $colorCases = [
        ['#ff0000', '#ff0000'], ['#FFF', '#FFF'], ['#abcd', '#abcd'], ['#11223344', '#11223344'],
        ['red', '#000'], ['', '#000'], ['#ff0000; background:url(x)', '#000'],
        ['#ff00', '#ff00'], ['#ggg', '#000'], ['#123456789', '#000'],
    ];
    foreach ($colorCases as $case) {
        list($in, $want) = $case;
        $got = afc_css_color($in, '#000');
        if ($got !== $want) {
            $failures[] = "afc_css_color('{$in}'): expected '{$want}', got '{$got}'";
        }
    }
}

// The autoloader must have been registered.
if (!defined('ARCHIVE_FILM_CLUB_BOOTSTRAPPED')) {
    $failures[] = 'ARCHIVE_FILM_CLUB_BOOTSTRAPPED was not defined by bootstrap.php';
}

// ---------------------------------------------------------------------------
// 3) Force-load every class so PHP links its parents/interfaces/traits.
//    We discover classes by filename (the autoloader maps ClassName.php ->
//    ClassName), and only touch files that actually declare that symbol so
//    procedural files (db/config.php, admin/controllers/AdminBootstrap.php,
//    which run DB code on include) are skipped.
// ---------------------------------------------------------------------------
$classDirs = ['/services', '/db', '/cache', '/admin/controllers'];
$loaded = 0;

foreach ($classDirs as $dir) {
    $base = $root . $dir;
    if (!is_dir($base)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $name = $file->getBasename('.php');
        $src = file_get_contents($file->getPathname());

        // Only treat it as a loadable type if it declares `class|interface|
        // trait <Name>` matching the filename.
        $pattern = '/^\s*(?:abstract\s+|final\s+)*(?:class|interface|trait)\s+'
                 . preg_quote($name, '/') . '\b/m';
        if (!preg_match($pattern, $src)) {
            continue;
        }

        try {
            // autoload=true forces the file to be required and linked.
            $exists = class_exists($name, true)
                   || interface_exists($name, true)
                   || trait_exists($name, true);
            if (!$exists) {
                $failures[] = "could not load declared type: {$name} ({$file->getPathname()})";
            } else {
                $loaded++;
            }
        } catch (\Throwable $e) {
            $failures[] = "fatal loading {$name}: {$e->getMessage()} @ "
                        . "{$e->getFile()}:{$e->getLine()}";
        }
    }
}

if ($loaded === 0) {
    $failures[] = 'no classes were loaded — discovery is broken';
}

// ---------------------------------------------------------------------------
// 4) MaintenanceService::splitSqlStatements — the quote/comment-aware SQL
//    splitter that restore relies on. Static + pure, so it runs with no DB.
//    A backup's data is full of semicolons inside string literals; splitting
//    on those would corrupt every restore, so this is the piece worth testing.
// ---------------------------------------------------------------------------
if (class_exists('MaintenanceService') && method_exists('MaintenanceService', 'splitSqlStatements')) {
    $cases = [
        ["SELECT 1;\nSELECT 2;", 2, 'two simple statements'],
        ["INSERT INTO t VALUES ('a;b'),('c');\nSELECT 9;", 2, 'semicolon inside a string literal'],
        ["INSERT INTO t VALUES ('O\\'Brien; x');", 1, 'backslash-escaped quote + semicolon'],
        ["INSERT INTO t VALUES ('it''s; fine');", 1, 'doubled-quote escape + semicolon'],
        ["-- drop; everything\nSELECT 3;", 1, 'line comment containing a semicolon'],
        ["CREATE TABLE `a;b` (id INT);", 1, 'semicolon inside a backtick identifier'],
        ["", 0, 'empty input'],
    ];
    foreach ($cases as $case) {
        list($sqlIn, $want, $label) = $case;
        $got = count(MaintenanceService::splitSqlStatements($sqlIn));
        if ($got !== $want) {
            $failures[] = "splitSqlStatements [{$label}]: expected {$want} statement(s), got {$got}";
        }
    }
    $stmts = MaintenanceService::splitSqlStatements("INSERT INTO t VALUES ('a;b');");
    if (!isset($stmts[0]) || strpos($stmts[0], "'a;b'") === false) {
        $failures[] = 'splitSqlStatements garbled a string literal containing a semicolon';
    }
} else {
    $failures[] = 'MaintenanceService::splitSqlStatements not found (restore splitter missing)';
}

// ---------------------------------------------------------------------------
// 5) Every migration file must split cleanly. Both migration runners
//    (install.php and the admin "refresh schema" action) feed db/migrations
//    through splitSqlStatements, so a comment form the splitter doesn't
//    understand, or a stray `;`, would surface as a 1064 syntax error on a
//    fresh install. Assert: at least one statement per file, no empty
//    statement, every statement starts with a SQL keyword (i.e. no comment
//    prose leaked into a statement), and 007 — the dynamic-SQL migration —
//    splits into exactly the statement count its structure implies.
// ---------------------------------------------------------------------------
if (class_exists('MaintenanceService') && method_exists('MaintenanceService', 'splitSqlStatements')) {
    $migrationFiles = glob($root . '/db/migrations/*.sql') ?: [];
    if (!$migrationFiles) {
        $failures[] = 'no migration files found under db/migrations';
    }
    $leadKeyword = '/^(ALTER|CREATE|INSERT|UPDATE|DELETE|DROP|SET|PREPARE|EXECUTE|DEALLOCATE|SELECT)\b/i';
    foreach ($migrationFiles as $mf) {
        $name = basename($mf);
        $stmts = MaintenanceService::splitSqlStatements((string)file_get_contents($mf));
        if (count($stmts) === 0) {
            $failures[] = "migration {$name}: splitter produced no statements";
            continue;
        }
        foreach ($stmts as $i => $s) {
            if (trim($s) === '') {
                $failures[] = "migration {$name}: statement #{$i} is empty";
            } elseif (!preg_match($leadKeyword, ltrim($s))) {
                $failures[] = "migration {$name}: statement #{$i} does not start with a SQL keyword: "
                            . substr(ltrim($s), 0, 40);
            }
            // Both runners execute statements with PDO::exec(), which never
            // reads a result set: a SELECT/SHOW leaves its rows pending and
            // the NEXT statement dies with MySQL error 2014 ("Cannot execute
            // queries while other unbuffered queries are active"). That
            // includes dynamic SQL — a `SET @sql := IF(..., 'SELECT 1', ...)`
            // no-op broke every fresh install once. Use `DO 0` instead.
            if (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $s)) {
                $failures[] = "migration {$name}: statement #{$i} returns a result set (PDO::exec would leave it pending): "
                            . substr(ltrim($s), 0, 40);
            }
            if (preg_match('/^\s*SET\s+@/i', $s) && preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $s, $m)) {
                foreach ($m[1] as $literal) {
                    if (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $literal)) {
                        $failures[] = "migration {$name}: statement #{$i} builds dynamic SQL that returns a result set: '"
                                    . substr($literal, 0, 40) . "' — use DO 0 as the no-op";
                    }
                }
            }
        }
        if (strpos($name, '007_') === 0) {
            // 1 MODIFY + 3 blocks of (SET @fk, SET @sql, PREPARE, EXECUTE, DEALLOCATE)
            $want = 1 + 3 * 5;
            if (count($stmts) !== $want) {
                $failures[] = "migration {$name}: expected {$want} statements, got " . count($stmts);
            }
            // The CONCAT() literal carries backticks inside single quotes; make
            // sure the splitter kept that whole SET intact rather than opening
            // a backtick-identifier state and swallowing the following `;`.
            $joined = implode("\n", $stmts);
            if (strpos($joined, "DROP FOREIGN KEY `', @fk, '`'") === false) {
                $failures[] = "migration {$name}: dynamic DROP FOREIGN KEY literal was garbled by the splitter";
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 6) Database::exec() must exist — migration 007's PREPARE/EXECUTE cannot go
//    through query()'s server-side prepared statements, so both runners
//    depend on it. Static check only (no connection is made).
// ---------------------------------------------------------------------------
if (class_exists('Database') && !method_exists('Database', 'exec')) {
    $failures[] = 'Database::exec() missing — migration runners need it for PREPARE/EXECUTE statements';
}
if (class_exists('MaintenanceService') && !method_exists('MaintenanceService', 'isIgnorableMigrationError')) {
    $failures[] = 'MaintenanceService::isIgnorableMigrationError() missing — install.php runner depends on it';
}

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
if ($failures) {
    fwrite(STDERR, "SMOKE TEST FAILED (" . count($failures) . "):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

fwrite(STDOUT, "smoke OK: bootstrap loaded, " . count($helpers)
             . " helpers present, {$loaded} classes linked, no DB required\n");
exit(0);
