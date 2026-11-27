<?php

declare(strict_types=1);

use App\Repositories\FinancialPlanningRepository;
use App\Repositories\FinancialSettingsRepository;
use App\Services\MailService;
use App\Services\ReminderService;
use App\Services\ReportService;
use App\Services\HouseholdService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/bootstrap.php';

try {
    $db = app_db();
    $reminders = new ReminderService(
        $db,
        new FinancialSettingsRepository($db),
        new FinancialPlanningRepository($db),
        new MailService(),
        new ReportService($db, new HouseholdService($db))
    );
    $result = $reminders->sendDue();
    fwrite(STDOUT, sprintf("Recordatorios enviados: %d; fallidos: %d\n", $result['sent'], $result['failed']));
    exit($result['failed'] > 0 ? 1 : 0);
} catch (Throwable $exception) {
    error_log('[Mi Finanzas] Falló el envío programado de recordatorios: ' . get_class($exception));
    fwrite(STDERR, "No se pudo completar el envío programado de recordatorios.\n");
    exit(1);
}
