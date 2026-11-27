<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

$checks = [
    'PHP 8.3+' => PHP_VERSION_ID >= 80300,
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'Fileinfo para adjuntos' => extension_loaded('fileinfo'),
    'Mbstring' => extension_loaded('mbstring'),
    'Symfony Mailer instalado' => class_exists(\Symfony\Component\Mailer\Mailer::class),
];

$mailHost = app_config('MAIL_HOST');
$mailPort = filter_var(app_config('MAIL_PORT'), FILTER_VALIDATE_INT);
$mailFrom = app_config('MAIL_FROM_ADDRESS');
$appUrl = parse_url((string) app_config('APP_URL', ''));
$checks['Configuración SMTP y URL HTTPS'] = (bool) (
    $mailHost
    && (filter_var($mailHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || filter_var($mailHost, FILTER_VALIDATE_IP))
    && $mailPort
    && $mailPort >= 1
    && $mailPort <= 65535
    && app_config('MAIL_USERNAME')
    && app_config('MAIL_PASSWORD')
    && in_array(app_config('MAIL_ENCRYPTION', 'tls'), ['tls', 'ssl'], true)
    && $mailFrom
    && filter_var($mailFrom, FILTER_VALIDATE_EMAIL)
    && is_array($appUrl)
    && ($appUrl['scheme'] ?? null) === 'https'
    && !empty($appUrl['host'])
    && in_array($appUrl['path'] ?? '', ['', '/'], true)
    && !isset($appUrl['query'])
    && !isset($appUrl['fragment'])
);

try {
    $db = app_db();
    $checks['Conexión a MySQL'] = $db->query('SELECT 1')->fetchColumn() == 1;
    $checks['Migraciones aplicadas'] = (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() > 0;
    $migration = $db->prepare(
        'SELECT 1 FROM schema_migrations WHERE migration = :migration LIMIT 1'
    );
    $migration->execute(['migration' => '003_debts_reports_auth.sql']);
    $checks['Migración de Fase 3'] = (bool) $migration->fetchColumn();
    $migration->execute(['migration' => '004_financial_configuration_and_planning.sql']);
    $checks['Migración de Fase 4'] = (bool) $migration->fetchColumn();
    $migration->execute(['migration' => '005_recurring_movements.sql']);
    $checks['Migración de Fase 5'] = (bool) $migration->fetchColumn();
    $migration->execute(['migration' => '006_phase_seven_financial_tools.sql']);
    $checks['Migración de Fase 7'] = (bool) $migration->fetchColumn();
    $migration->execute(['migration' => '007_custom_categories.sql']);
    $checks['Migración de categorías propias (Fase 8)'] = (bool) $migration->fetchColumn();
    $migration->execute(['migration' => '008_currency_attachments_notifications_demo.sql']);
    $checks['Migración de monedas, adjuntos y demo (Fase 8)'] = (bool) $migration->fetchColumn();
} catch (Throwable $exception) {
    $checks['Conexión/esquema de MySQL'] = false;
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
}

$failed = false;
foreach ($checks as $label => $passed) {
    echo sprintf("[%s] %s\n", $passed ? 'OK' : 'FALLO', $label);
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
