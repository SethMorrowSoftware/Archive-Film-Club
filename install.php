<?php
/**
 * Archive Film Club - Installation Script
 *
 * This script helps cPanel users set up the MySQL database
 * Run this script once after uploading files to your server
 *
 * Access: https://yourdomain.com/install.php
 *
 * After install completes, either DELETE this file or leave the deny
 * block in .htaccess uncommented. The installer also self-locks once a
 * `.installed` file exists OR an admin user is present in the database.
 */

// Bootstrap session + CSRF so the form POSTs are protected against
// cross-site forgery during install. We can't autoload Database here
// because .env may not exist yet -- the bootstrap is .env-tolerant.
require_once __DIR__ . '/bootstrap.php';

// Security: Check if already installed
$envFile = __DIR__ . '/.env';
$lockFile = __DIR__ . '/.installed';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

// Build install-safe URLs that work even in subdirectories or renamed installer files.
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/install.php';
$scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
$installBasePath = ($scriptDir === '' || $scriptDir === '.') ? '' : $scriptDir;
$installScript = basename($scriptName) ?: 'install.php';

$buildLocalUrl = function (string $relative = '') use ($installBasePath): string {
    $relative = ltrim($relative, '/');
    if ($relative == '') {
        return $installBasePath === '' ? '/' : $installBasePath . '/';
    }
    return ($installBasePath === '' ? '' : $installBasePath . '/') . $relative;
};

$installStepUrl = function (int $targetStep) use ($buildLocalUrl, $installScript): string {
    return $buildLocalUrl($installScript . '?step=' . $targetStep);
};

/**
 * Defensive install guard.
 *
 * Primary check: the `.installed` lock file. Secondary check: even if the
 * lock file has been deleted (accidentally via cPanel file manager, or
 * maliciously), we refuse to re-run the installer when an admin user
 * already exists in the database. This prevents credential-reset attacks
 * that rely on removing the lock file.
 */
$alreadyInstalled = file_exists($lockFile);
if (!$alreadyInstalled && file_exists($envFile)) {
    try {
        if (class_exists('Database')) {
            $db = Database::getInstance();
            $adminCount = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM users WHERE role IN ('admin','editor') AND is_guest = 0"
            );
            if ($adminCount > 0) {
                $alreadyInstalled = true;
            }
        }
    } catch (Throwable $e) {
        // DB not reachable yet -- normal on first run. Fall through.
    }
}

if ($alreadyInstalled) {
    die('
    <html>
    <head><title>Already Installed</title></head>
    <body style="font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px;">
        <h1>Already Installed</h1>
        <p>Archive Film Club is already installed.</p>
        <p><strong>You should delete <code>install.php</code> now</strong> (or uncomment the install.php deny block in <code>.htaccess</code>). If you really need to reinstall from scratch, drop the database tables and remove the <code>.installed</code> file.</p>
        <p><a href="' . htmlspecialchars($buildLocalUrl('index.php')) . '">Go to Site</a> | <a href="' . htmlspecialchars($buildLocalUrl('admin.php')) . '">Go to Admin</a></p>
    </body>
    </html>
    ');
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF: every POST step must carry a valid token. Prevents an attacker
    // who knows the install URL from triggering DB creation / admin signup
    // via a CSRF gadget on an unrelated tab.
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        $error = 'Session expired. Please reload and try again.';
    }

    if (!$error && $step === 1) {
        // Step 1: Save database configuration
        $config = [
            'DB_HOST' => trim($_POST['db_host'] ?? 'localhost'),
            'DB_PORT' => trim($_POST['db_port'] ?? '3306'),
            'DB_DATABASE' => trim($_POST['db_name'] ?? ''),
            'DB_USERNAME' => trim($_POST['db_user'] ?? ''),
            'DB_PASSWORD' => $_POST['db_pass'] ?? '',
        ];

        // Validate: DB name is interpolated into SQL identifiers below, so it
        // must match the MySQL identifier charset exactly. This also blocks
        // backtick-injection attacks (e.g. db_name="foo`; DROP DATABASE x; --").
        if (empty($config['DB_DATABASE']) || empty($config['DB_USERNAME'])) {
            $error = 'Database name and username are required.';
        } elseif (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $config['DB_DATABASE'])) {
            $error = 'Database name may only contain letters, numbers, and underscores (1–64 characters). On cPanel the name is usually prefixed with your account name and an underscore, e.g. cpaneluser_filmclub.';
        } elseif (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $config['DB_USERNAME'])) {
            $error = 'Database username may only contain letters, numbers, and underscores (1–32 characters).';
        } elseif (!preg_match('/^[0-9]{1,5}$/', $config['DB_PORT']) || (int)$config['DB_PORT'] < 1 || (int)$config['DB_PORT'] > 65535) {
            $error = 'Database port must be a number between 1 and 65535.';
        } elseif (strpos($config['DB_PASSWORD'], "\n") !== false || strpos($config['DB_PASSWORD'], "\r") !== false) {
            $error = 'Database password may not contain newline characters.';
        } else {
            // Test connection against the target database directly. cPanel
            // users do not have CREATE DATABASE privilege, so we never try
            // to create the DB -- the operator must create it first via
            // cPanel → MySQL Databases.
            try {
                $dsn = sprintf(
                    "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
                    $config['DB_HOST'],
                    $config['DB_PORT'],
                    $config['DB_DATABASE']
                );
                $pdo = new PDO($dsn, $config['DB_USERNAME'], $config['DB_PASSWORD'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                // Connection successful - save .env file
                $envContent = "# Archive Film Club - Database Configuration\n";
                $envContent .= "# Generated by install.php on " . date('Y-m-d H:i:s') . "\n\n";
                foreach ($config as $key => $value) {
                    // Quote values containing spaces or '#' so the .env parser handles them.
                    $needsQuoting = preg_match('/[\s#"\']/', $value);
                    $written = $needsQuoting
                        ? '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"'
                        : $value;
                    $envContent .= "$key=$written\n";
                }
                $envContent .= "\n# Cache settings\n";
                $envContent .= "CACHE_SEARCH_TTL=1800\n";
                $envContent .= "CACHE_METADATA_TTL=86400\n";
                $envContent .= "CACHE_THUMBNAIL_TTL=604800\n";
                $envContent .= "\n# Feature flags\n";
                $envContent .= "ENABLE_THUMBNAIL_CACHING=true\n";
                $envContent .= "ENABLE_SEARCH_CACHING=true\n";
                $envContent .= "ENABLE_USER_SESSIONS=true\n";
                $envContent .= "ENABLE_API_LOGGING=true\n";
                $envContent .= "\n# Install path for subdirectory deployments\n";
                $envContent .= "APP_BASE_PATH={$installBasePath}\n";
                $envContent .= "\n# APP_URL pins canonical hostnames in emails/OG tags so a forged\n";
                $envContent .= "# Host header can't poison password-reset links. Set this once SSL is\n";
                $envContent .= "# installed and the public domain is final, e.g. APP_URL=https://example.com\n";
                $envContent .= "# APP_URL=\n";

                if (file_put_contents($envFile, $envContent, LOCK_EX) === false) {
                    $error = 'Could not write .env file. Please check file permissions on the install directory.';
                } else {
                    // Restrict permissions so other users on the shared host
                    // cannot read database credentials via the filesystem.
                    // chmod may be a no-op on some cPanel configurations;
                    // suppress errors and continue.
                    @chmod($envFile, 0600);

                    header('Location: ' . $installStepUrl(2));
                    exit;
                }
            } catch (PDOException $e) {
                // Log the raw driver message (it carries the DB host/user/name)
                // and show a generic message: install.php is reachable by anyone
                // on a not-yet-installed instance, so the raw error must not leak
                // those details to a passer-by. The cPanel guidance below is
                // generic advice, not request-specific data.
                $msg = $e->getMessage();
                error_log('[install] DB connection failed: ' . $msg);
                if (stripos($msg, 'access denied') !== false || stripos($msg, "unknown database") !== false) {
                    $error = 'Could not connect to the database.'
                        . '<br><br><strong>On cPanel:</strong> Make sure you created the database AND the user under cPanel → MySQL Databases, then added the user to the database with ALL PRIVILEGES. Database names are usually prefixed with your cPanel username (e.g. <code>cpaneluser_filmclub</code>).';
                } else {
                    $error = 'Database connection failed. Double-check the host, database name, username, and password, then try again.';
                }
            }
        }
    }

    elseif (!$error && $step === 2) {
        // Step 2: Run ALL database migrations (001 → latest)
        try {
            require_once __DIR__ . '/db/Database.php';
            $db = Database::getInstance();

            $migrationsDir = __DIR__ . '/db/migrations';
            $migrationFiles = glob($migrationsDir . '/*.sql');
            if (!$migrationFiles) {
                throw new Exception('No migration files found');
            }
            sort($migrationFiles); // 001_*, 002_*, 003_*, 004_*

            // The admin "refresh schema" action runs the same files through
            // MaintenanceService::runMigrations(); the statement splitter is
            // shared so both runners agree on where one statement ends.
            require_once __DIR__ . '/services/Admin/MaintenanceService.php';

            foreach ($migrationFiles as $migrationFile) {
                $sql = file_get_contents($migrationFile);

                // Split into individual statements with the quote/comment-
                // aware splitter (a naive explode(';') breaks on semicolons
                // inside `--` comments and inside the string literals that
                // migration 007 builds its dynamic ALTERs from).
                $statements = MaintenanceService::splitSqlStatements($sql);

                foreach ($statements as $statement) {
                    if (trim($statement) === '') continue;
                    try {
                        // exec(), NOT query(): migration 007 uses
                        // PREPARE/EXECUTE, which MySQL refuses inside the
                        // server-side prepared-statement protocol query() uses.
                        $db->exec($statement);
                    } catch (Throwable $e) {
                        // Swallow "already exists / duplicate column" errors
                        // so re-runs and partially-applied migrations don't
                        // block the installer. The strings below cover both
                        // MySQL and MariaDB phrasing. Must stay in sync with
                        // MaintenanceService::isIgnorableMigrationError().
                        if (!MaintenanceService::isIgnorableMigrationError($e->getMessage())) {
                            throw $e;
                        }
                    }
                }
            }

            header('Location: ' . $installStepUrl(3));
            exit;

        } catch (Exception $e) {
            $error = 'Migration failed: ' . htmlspecialchars($e->getMessage());
        }
    }

    elseif (!$error && $step === 3) {
        // Step 3: Create admin user in the unified `users` table
        // (migration 003). This matches what UserAuthService::login() reads
        // from, so the same credentials work for both admin.php and the
        // front-end login flow.
        $username = trim($_POST['admin_user'] ?? '');
        $password = $_POST['admin_pass'] ?? '';
        $passwordConfirm = $_POST['admin_pass_confirm'] ?? '';
        $email = trim($_POST['admin_email'] ?? '');

        if (empty($username) || empty($password)) {
            $error = 'Username and password are required.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
            $error = 'Username must be 3–50 characters, letters/numbers/underscore/dot/hyphen only.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email address is not valid.';
        } else {
            try {
                require_once __DIR__ . '/db/Database.php';
                $db = Database::getInstance();

                // Reject if an admin account already exists in either table.
                $existingUnified = $db->fetchColumn(
                    "SELECT COUNT(*) FROM users WHERE role IN ('admin','editor') AND is_guest = 0"
                );
                $existingLegacy = 0;
                try {
                    $existingLegacy = $db->fetchColumn("SELECT COUNT(*) FROM admin_users");
                } catch (Throwable $e) { /* table may have been dropped */ }

                if ((int)$existingUnified > 0 || (int)$existingLegacy > 0) {
                    $error = 'An admin user already exists.';
                } else {
                    // Uniqueness guard on username inside `users`.
                    $collision = $db->fetchOne(
                        "SELECT id FROM users WHERE username = ?",
                        [$username]
                    );
                    if ($collision) {
                        $error = 'That username is already taken.';
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $db->insert('users', [
                            'username'      => $username,
                            'email'         => $email !== '' ? $email : null,
                            'password_hash' => $hash,
                            'display_name'  => $username,
                            'role'          => 'admin',
                            'is_guest'      => 0,
                            'session_id'    => null,
                            'preferences'   => '{}',
                        ]);

                        // Write the install lock NOW so the installer can't
                        // be re-run even if the operator skips remaining
                        // steps. This used to happen at step 4 which let an
                        // attacker who deleted .installed re-walk through
                        // the wizard (admin guard above caught them too,
                        // but belt-and-suspenders).
                        @file_put_contents($lockFile, date('Y-m-d H:i:s'), LOCK_EX);
                        @chmod($lockFile, 0644);

                        header('Location: ' . $installStepUrl(4));
                        exit;
                    }
                }
            } catch (Exception $e) {
                $error = 'Error creating admin: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    elseif (!$error && $step === 4) {
        // Step 4: Migrate existing JSON data (if any)
        try {
            require_once __DIR__ . '/services/SettingsService.php';
            $settingsService = new SettingsService();

            // Migrate site settings
            $settingsFile = __DIR__ . '/site-settings.json';
            if (file_exists($settingsFile)) {
                $content = file_get_contents($settingsFile);
                $data = json_decode($content, true);
                if ($data) {
                    $settingsService->updateSettings($data);
                }
            }

            // Migrate recommendations
            $recommendationsFile = __DIR__ . '/recommendations.json';
            if (file_exists($recommendationsFile)) {
                $content = file_get_contents($recommendationsFile);
                $data = json_decode($content, true);
                if ($data) {
                    $settingsService->updateRecommendations($data);
                }
            }

            // Migrate featured sections
            $sectionsFile = __DIR__ . '/featured-sections.json';
            if (file_exists($sectionsFile)) {
                $content = file_get_contents($sectionsFile);
                $data = json_decode($content, true);
                if ($data) {
                    $settingsService->updateFeaturedSections($data);
                }
            }

            // Lock file already written in step 3; this step is purely
            // additive data migration.

            header('Location: ' . $installStepUrl(5));
            exit;

        } catch (Exception $e) {
            $error = 'Migration failed: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Detect whether the operator already has JSON data to migrate. If not,
// step 4 is a confusing no-op for a fresh install.
$hasLegacyJson = file_exists(__DIR__ . '/site-settings.json')
              || file_exists(__DIR__ . '/recommendations.json')
              || file_exists(__DIR__ . '/featured-sections.json');

// HTML Output
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title>Install Archive Film Club</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1a2e;
            color: #eee;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #16213e;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        h1 {
            color: #ff0000;
            margin-top: 0;
            font-size: 24px;
        }
        h2 {
            font-size: 18px;
            margin-top: 0;
        }
        .steps {
            display: flex;
            margin-bottom: 30px;
            gap: 5px;
        }
        .step-indicator {
            flex: 1;
            padding: 10px;
            background: #0f3460;
            text-align: center;
            font-size: 12px;
            border-radius: 6px;
        }
        .step-indicator.active {
            background: #ff0000;
            color: white;
        }
        .step-indicator.completed {
            background: #27ae60;
            color: white;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        input[type="text"],
        input[type="password"],
        input[type="email"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #333;
            border-radius: 6px;
            background: #0f3460;
            color: #fff;
            font-size: 14px;
        }
        input:focus {
            outline: none;
            border-color: #ff0000;
        }
        button {
            background: #ff0000;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
        }
        button:hover {
            background: #cc0000;
        }
        .error {
            background: #c0392b;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .success {
            background: #27ae60;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .info {
            background: #2980b9;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        code {
            background: #0f3460;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
        }
        a {
            color: #ff0000;
        }
        .btn-secondary {
            background: #0f3460;
            margin-left: 10px;
        }
        .btn-secondary:hover {
            background: #1a4b8c;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Archive Film Club Installation</h1>

        <!-- Progress Steps -->
        <div class="steps">
            <div class="step-indicator <?= $step >= 1 ? ($step > 1 ? 'completed' : 'active') : '' ?>">1. Database</div>
            <div class="step-indicator <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : '' ?>">2. Tables</div>
            <div class="step-indicator <?= $step >= 3 ? ($step > 3 ? 'completed' : 'active') : '' ?>">3. Admin</div>
            <div class="step-indicator <?= $step >= 4 ? ($step > 4 ? 'completed' : 'active') : '' ?>">4. Migrate</div>
            <div class="step-indicator <?= $step >= 5 ? 'active' : '' ?>">5. Done!</div>
        </div>

        <?php if ($error): ?>
        <div class="error"><?= $error /* already escaped at the source */ ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
        <!-- Step 1: Database Configuration -->
        <h2>Step 1: Database Configuration</h2>
        <div class="info">
            <strong>Detected install path:</strong> <code><?= htmlspecialchars($installBasePath === '' ? '/' : $installBasePath . '/') ?></code><br>
            <strong>cPanel Users:</strong> First create your database and user via <strong>cPanel → MySQL Databases</strong> (most cPanel users can't create databases from PHP). Add the user to the database with <em>ALL PRIVILEGES</em>, then enter the exact names below — they're usually prefixed with your cPanel account name and an underscore.
        </div>

        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
            <div class="form-group">
                <label for="db_host">Database Host</label>
                <input id="db_host" type="text" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label for="db_port">Database Port</label>
                <input id="db_port" type="text" name="db_port" value="3306" required pattern="[0-9]{1,5}">
            </div>
            <div class="form-group">
                <label for="db_name">Database Name</label>
                <input id="db_name" type="text" name="db_name" placeholder="e.g., cpaneluser_filmclub" required pattern="[A-Za-z0-9_]{1,64}">
            </div>
            <div class="form-group">
                <label for="db_user">Database Username</label>
                <input id="db_user" type="text" name="db_user" placeholder="e.g., cpaneluser_filmuser" required pattern="[A-Za-z0-9_]{1,32}">
            </div>
            <div class="form-group">
                <label for="db_pass">Database Password</label>
                <input id="db_pass" type="password" name="db_pass" required autocomplete="off">
            </div>
            <button type="submit">Test Connection &amp; Continue</button>
        </form>

        <?php elseif ($step === 2): ?>
        <!-- Step 2: Run Migrations -->
        <h2>Step 2: Create Database Tables</h2>
        <p>Click the button below to create all required database tables.</p>

        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
            <button type="submit">Create Tables</button>
        </form>

        <?php elseif ($step === 3): ?>
        <!-- Step 3: Create Admin User -->
        <h2>Step 3: Create Admin Account</h2>
        <p>Create your administrator account to manage the site.</p>

        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
            <div class="form-group">
                <label for="admin_user">Admin Username</label>
                <input id="admin_user" type="text" name="admin_user" required autocomplete="username" pattern="[A-Za-z0-9_.\-]{3,50}">
            </div>
            <div class="form-group">
                <label for="admin_email">Admin Email (optional)</label>
                <input id="admin_email" type="email" name="admin_email" autocomplete="email">
            </div>
            <div class="form-group">
                <label for="admin_pass">Password (min 8 characters)</label>
                <input id="admin_pass" type="password" name="admin_pass" required minlength="8" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="admin_pass_confirm">Confirm Password</label>
                <input id="admin_pass_confirm" type="password" name="admin_pass_confirm" required autocomplete="new-password">
            </div>
            <button type="submit">Create Admin Account</button>
        </form>

        <?php elseif ($step === 4): ?>
        <!-- Step 4: Migrate Data -->
        <h2>Step 4: Migrate Existing Data</h2>

        <?php if ($hasLegacyJson): ?>
        <p>Click below to migrate your existing JSON data (settings, recommendations, sections) to the database.</p>

        <div class="info">
            Detected legacy files to import:
            <ul style="margin: 10px 0;">
                <?php if (file_exists(__DIR__ . '/site-settings.json')): ?>
                <li><code>site-settings.json</code></li>
                <?php endif; ?>
                <?php if (file_exists(__DIR__ . '/recommendations.json')): ?>
                <li><code>recommendations.json</code></li>
                <?php endif; ?>
                <?php if (file_exists(__DIR__ . '/featured-sections.json')): ?>
                <li><code>featured-sections.json</code></li>
                <?php endif; ?>
            </ul>
            Your original JSON files will not be deleted.
        </div>

        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
            <button type="submit">Migrate Data</button>
            <a href="<?= htmlspecialchars($installStepUrl(5)) ?>" class="btn-secondary" style="display:inline-block; padding:12px 24px; background:#0f3460; color:white; text-decoration:none; border-radius:6px; margin-left:10px;">Skip</a>
        </form>
        <?php else: ?>
        <div class="info">
            No legacy JSON files were detected (this is normal for a fresh install). You can skip to the final step.
        </div>
        <p><a href="<?= htmlspecialchars($installStepUrl(5)) ?>"><button type="button">Continue</button></a></p>
        <?php endif; ?>

        <?php elseif ($step === 5): ?>
        <!-- Step 5: Complete -->
        <h2>Installation Complete!</h2>
        <div class="success">
            Archive Film Club has been successfully installed and configured.
        </div>

        <div class="info">
            <strong>Security: lock down the installer now.</strong>
            <ol style="margin: 10px 0 0 18px; padding: 0;">
                <li><strong>Delete <code>install.php</code></strong> via cPanel File Manager, <em>or</em></li>
                <li>Edit <code>.htaccess</code> and uncomment the <code>&lt;FilesMatch "^install\.php$"&gt;</code> deny block near the bottom.</li>
            </ol>
            <p style="margin: 12px 0 0;">The installer also self-locks via the <code>.installed</code> file, but a server-side block is the safest defense.</p>
        </div>

        <div class="info">
            <strong>Recommended next steps</strong>
            <ul style="margin: 10px 0 0 18px; padding: 0;">
                <li>Set <code>APP_URL=https://yourdomain.com</code> in <code>.env</code> once your SSL certificate is in place. This pins canonical hostnames in password-reset emails so a forged Host header can't poison them.</li>
                <li>Configure SMTP (<code>SMTP_HOST</code>, <code>SMTP_USERNAME</code>, etc.) in <code>.env</code> for reliable transactional email — the PHP <code>mail()</code> fallback is often spam-filtered on shared hosts.</li>
                <li>Set up the cron jobs documented in the README so the cache stays trim.</li>
                <li>Delete <code>api/diagnose.php</code> once you've confirmed everything works.</li>
            </ul>
        </div>

        <p style="margin-top: 30px;">
            <a href="<?= htmlspecialchars($buildLocalUrl('index.php')) ?>"><button>Go to Site</button></a>
            <a href="<?= htmlspecialchars($buildLocalUrl('admin.php')) ?>"><button class="btn-secondary">Go to Admin</button></a>
        </p>

        <?php endif; ?>
    </div>
</body>
</html>
