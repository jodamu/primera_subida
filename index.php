<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Security\Session;
use App\Exceptions\DomainException;
use App\Exceptions\MailConfigurationException;
use App\Exceptions\MailDeliveryException;
use App\Repositories\AccountRepository;
use App\Repositories\CsvImportRepository;
use App\Repositories\FinancialPlanningRepository;
use App\Repositories\FinancialSettingsRepository;
use App\Repositories\LiabilityRepository;
use App\Repositories\OperationRepository;
use App\Repositories\RecurringMovementRepository;
use App\Services\AuthService;
use App\Services\AccountActionService;
use App\Services\AccountService;
use App\Services\CashFlowProjectionService;
use App\Services\DebtPlanService;
use App\Services\FinancialBackupService;
use App\Services\FinancialAlertService;
use App\Services\HouseholdService;
use App\Services\FinancialSettingsService;
use App\Services\FinancialPlanningService;
use App\Services\FinancialSpaceService;
use App\Services\CsvImportService;
use App\Services\LiabilityService;
use App\Services\MailService;
use App\Services\OperationService;
use App\Services\RecurringMovementService;
use App\Services\ReportService;
use App\Repositories\UserRepository;

require __DIR__ . '/config/bootstrap.php';

Session::start();

$cspNonce = base64_encode(random_bytes(18));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'self\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'; object-src \'none\'; script-src \'self\' \'nonce-' . $cspNonce . '\'; style-src \'self\'; img-src \'self\' data:');
header('Cache-Control: no-store, private');
if (Session::isHttps()) {
    header('Strict-Transport-Security: max-age=31536000');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function authenticated_user_id(): int
{
    if (empty($_SESSION['user']['id'])) {
        redirect('/login');
    }

    return (int) $_SESSION['user']['id'];
}

function render_page(string $page, array $data = []): void
{
    $currentUser = $_SESSION['user'] ?? null;
    $flash = $_SESSION['flash'] ?? null;
    $cspNonce = $GLOBALS['cspNonce'];
    unset($_SESSION['flash']);
    extract($data, EXTR_SKIP);
    $contentView = __DIR__ . '/app/Views/' . $page . '.php';
    require __DIR__ . '/app/Views/layout.php';
}

function require_csrf(): void
{
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        http_response_code(419);
        render_page('error', ['title' => 'Solicitud vencida', 'message' => 'Actualiza la página e inténtalo de nuevo.']);
        exit;
    }
}

function audit_request_error(Throwable $exception): void
{
    error_log(sprintf(
        '[Mi Finanzas] %s: %s in %s:%d',
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($path === '/' && $method === 'GET' && empty($_SESSION['user']['id'])) {
    $siteUrl = rtrim((string) app_config('APP_URL', 'https://finanzas.jodamuover.com'), '/');
    $title = 'Finanzas personales y del hogar en Colombia';
    $description = 'Conoce Mi Finanzas, una aplicación web en evolución para organizar cuentas, movimientos, tarjetas y deudas personales o compartidas con informes mensuales.';

    render_page('landing', [
        'title' => $title,
        'description' => $description,
        'robots' => 'index, follow, max-image-preview:large',
        'canonical' => $siteUrl . '/',
        'ogTitle' => 'Mi Finanzas | Orden para tus finanzas personales y del hogar',
        'ogDescription' => $description,
        'structuredData' => [
            '@context' => 'https://schema.org',
            '@type' => 'WebApplication',
            'name' => 'Mi Finanzas',
            'url' => $siteUrl . '/',
            'description' => $description,
            'applicationCategory' => 'FinanceApplication',
            'operatingSystem' => 'Web',
            'inLanguage' => 'es-CO',
        ],
    ]);
    exit;
}

try {
    $db = app_db();
    $users = new UserRepository($db);
    $auth = new AuthService($db, $users);
    $accountActions = new AccountActionService($db, $users, new MailService());
    $households = new HouseholdService($db);
    $financialSpaces = new FinancialSpaceService($households);
    $financialSettingsRepository = new FinancialSettingsRepository($db);
    $financialSettings = new FinancialSettingsService(
        $financialSpaces,
        $financialSettingsRepository
    );
    $financialPlanningRepository = new FinancialPlanningRepository($db);
    $financialPlanning = new FinancialPlanningService(
        $db,
        $financialSpaces,
        $financialSettings,
        $financialPlanningRepository
    );
    $accountRepository = new AccountRepository($db);
    $liabilityRepository = new LiabilityRepository($db);
    $operationRepository = new OperationRepository($db);
    $accountService = new AccountService($db, $accountRepository);
    $operationService = new OperationService($db, $accountRepository, $operationRepository, $liabilityRepository);
    $liabilityService = new LiabilityService($db, $accountRepository, $liabilityRepository, $operationRepository);
    $reportService = new ReportService($db, $households);
    $recurringRepository = new RecurringMovementRepository($db);
    $recurringService = new RecurringMovementService(
        $recurringRepository,
        $accountService,
        $operationService,
        $financialSpaces
    );
    $backupService = new FinancialBackupService($db, $households);
    $projectionService = new CashFlowProjectionService(
        $accountService,
        $recurringRepository,
        $financialSpaces
    );
    $debtPlanService = new DebtPlanService($liabilityService, $financialSpaces);
    $financialAlerts = new FinancialAlertService(
        $financialSpaces,
        $financialSettings,
        $financialPlanningRepository,
        $liabilityService,
        $recurringRepository
    );
    $csvImportService = new CsvImportService(
        $db,
        $accountRepository,
        $operationRepository,
        new CsvImportRepository($db),
        $operationService
    );

    if (!empty($_SESSION['user']['id'])) {
        $freshUser = $users->findById((int) $_SESSION['user']['id']);
        if (!$freshUser || (int) ($freshUser['auth_version'] ?? 0) !== (int) ($_SESSION['user']['auth_version'] ?? 0)) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['user'] = $freshUser;
        }
    }

    if ($path === '/health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($path === '/register' && $method === 'GET') {
        if (!empty($_SESSION['user'])) {
            redirect('/');
        }
        render_page('register', ['title' => 'Crear cuenta']);
        exit;
    }

    if ($path === '/register' && $method === 'POST') {
        require_csrf();
        $name = (string) ($_POST['name'] ?? '');
        $email = (string) ($_POST['email'] ?? '');

        try {
            $auth->register($name, $email, (string) ($_POST['password'] ?? ''));
            try {
                $accountActions->sendVerificationForEmail($email);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Revisa tu correo y confirma la cuenta antes de iniciar sesión.'];
            } catch (MailConfigurationException | MailDeliveryException $exception) {
                audit_request_error($exception);
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'La cuenta se creó, pero no se pudo enviar el correo. Configura SMTP y usa “Reenviarlo” en la pantalla de acceso.'];
            }
            redirect('/login');
        } catch (DomainException $exception) {
            render_page('register', [
                'title' => 'Crear cuenta',
                'name' => $name,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);
            exit;
        }
    }

    if (($path === '/email/resend' || $path === '/password/forgot') && $method === 'GET') {
        $purpose = $path === '/email/resend' ? 'verify_email' : 'reset_password';
        render_page('email-request', [
            'title' => $purpose === 'verify_email' ? 'Reenviar verificación' : 'Recuperar contraseña',
            'purpose' => $purpose,
        ]);
        exit;
    }

    if (($path === '/email/resend' || $path === '/password/forgot') && $method === 'POST') {
        require_csrf();
        $purpose = $path === '/email/resend' ? 'verify_email' : 'reset_password';
        $email = $_POST['email'] ?? '';
        if (!is_string($email)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Ingresa un correo electrónico válido.'];
            redirect($path);
        }

        try {
            if ($purpose === 'verify_email') {
                $accountActions->sendVerificationForEmail($email);
            } else {
                $accountActions->requestPasswordReset($email);
            }
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Si el correo corresponde a una cuenta elegible, recibirás instrucciones en breve.',
            ];
        } catch (MailConfigurationException | MailDeliveryException $exception) {
            audit_request_error($exception);
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'No fue posible enviar el correo. Inténtalo más tarde o contacta al administrador.'];
        }
        redirect($path);
    }

    if ($path === '/email/verify' && $method === 'GET') {
        $token = $_GET['token'] ?? '';
        render_page('email-verify', [
            'title' => 'Verificar correo',
            'token' => is_string($token) ? $token : '',
        ]);
        exit;
    }

    if ($path === '/email/verify' && $method === 'POST') {
        require_csrf();
        $token = $_POST['token'] ?? '';
        try {
            if (!is_string($token)) {
                throw new DomainException('El enlace no es válido o ya venció. Solicita uno nuevo.');
            }
            $accountActions->verifyEmail($token);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Correo verificado. Ya puedes iniciar sesión.'];
            redirect('/login');
        } catch (DomainException $exception) {
            render_page('email-verify', [
                'title' => 'Verificar correo',
                'token' => is_string($token) ? $token : '',
                'error' => $exception->getMessage(),
            ]);
            exit;
        }
    }

    if ($path === '/password/reset' && $method === 'GET') {
        $token = $_GET['token'] ?? '';
        render_page('password-reset', [
            'title' => 'Crear nueva contraseña',
            'token' => is_string($token) ? $token : '',
        ]);
        exit;
    }

    if ($path === '/password/reset' && $method === 'POST') {
        require_csrf();
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmation = $_POST['password_confirmation'] ?? '';
        try {
            if (!is_string($token) || !is_string($password) || !is_string($confirmation)) {
                throw new DomainException('Revisa los datos e inténtalo nuevamente.');
            }
            if (!hash_equals($password, $confirmation)) {
                throw new DomainException('Las contraseñas no coinciden.');
            }
            $accountActions->resetPassword($token, $password);
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Contraseña actualizada. Inicia sesión nuevamente.'];
            redirect('/login');
        } catch (DomainException $exception) {
            render_page('password-reset', [
                'title' => 'Crear nueva contraseña',
                'token' => is_string($token) ? $token : '',
                'error' => $exception->getMessage(),
            ]);
            exit;
        }
    }

    if ($path === '/login' && $method === 'GET') {
        if (!empty($_SESSION['user'])) {
            redirect('/');
        }
        render_page('login', ['title' => 'Iniciar sesión']);
        exit;
    }

    if ($path === '/login' && $method === 'POST') {
        require_csrf();
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $user = $auth->authenticate($email, $password, $ipAddress);

        if (!$user) {
            render_page('login', [
                'title' => 'Iniciar sesión',
                'email' => $email,
                'error' => 'No fue posible iniciar sesión. Revisa tus datos o inténtalo más tarde.',
            ]);
            exit;
        }

        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        redirect('/');
    }

    if ($path === '/logout' && $method === 'POST') {
        require_csrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        redirect('/login');
    }

    if ($path === '/invitations' && $method === 'POST') {
        require_csrf();
        if (empty($_SESSION['user']['id'])) {
            redirect('/login');
        }

        try {
            $token = $households->createInvitation(
                (int) ($_POST['household_id'] ?? 0),
                (int) $_SESSION['user']['id'],
                (string) ($_POST['email'] ?? '')
            );
            $baseUrl = rtrim((string) app_config('APP_URL', ''), '/');
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Invitación creada. El enlace se muestra una sola vez; compártelo con la persona indicada.',
            ];
            $_SESSION['invitation_url'] = $baseUrl . '/invitations/accept?token=' . rawurlencode($token);
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/');
    }

    if ($path === '/invitations/accept' && $method === 'GET') {
        $token = (string) ($_GET['token'] ?? '');
        if (empty($_SESSION['user']['id'])) {
            $_SESSION['pending_invitation'] = $token;
            redirect('/login');
        }
        render_page('accept-invitation', ['title' => 'Aceptar invitación', 'token' => $token]);
        exit;
    }

    if ($path === '/invitations/accept' && $method === 'POST') {
        require_csrf();
        if (empty($_SESSION['user']['id'])) {
            redirect('/login');
        }

        try {
            $households->acceptInvitation((int) $_SESSION['user']['id'], (string) ($_POST['token'] ?? ''));
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Ahora formas parte del hogar compartido.'];
            unset($_SESSION['pending_invitation']);
            redirect('/');
        } catch (DomainException $exception) {
            render_page('accept-invitation', [
                'title' => 'Aceptar invitación',
                'token' => (string) ($_POST['token'] ?? ''),
                'error' => $exception->getMessage(),
            ]);
            exit;
        }
    }

    if ($path === '/households/members' && $method === 'GET') {
        $userId = authenticated_user_id();
        $householdId = filter_var($_GET['household_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if (!$householdId || $householdId < 1) {
                throw new DomainException('Selecciona un hogar válido.');
            }
            $userHouseholds = $households->forUser($userId);
            $household = null;
            foreach ($userHouseholds as $item) {
                if ((int) $item['id'] === (int) $householdId) {
                    $household = $item;
                    break;
                }
            }
            if (!$household) {
                throw new DomainException('No tienes acceso a ese hogar.');
            }
            render_page('household-members', [
                'title' => 'Integrantes del hogar',
                'household' => $household,
                'members' => $households->membersForManagement((int) $householdId, $userId),
                'currentUserId' => $userId,
            ]);
        } catch (DomainException $exception) {
            http_response_code(403);
            render_page('error', ['title' => 'Acceso no disponible', 'message' => $exception->getMessage()]);
        }
        exit;
    }

    if (($path === '/households/members/role' || $path === '/households/members/status') && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $householdId = filter_var($_POST['household_id'] ?? null, FILTER_VALIDATE_INT);
        $memberId = filter_var($_POST['member_id'] ?? null, FILTER_VALIDATE_INT);
        $redirectPath = '/households/members?household_id=' . max(0, (int) $householdId);

        try {
            if (!$householdId || $householdId < 1 || !$memberId || $memberId < 1) {
                throw new DomainException('Selecciona un integrante válido.');
            }
            if ($path === '/households/members/role') {
                $role = $_POST['role'] ?? '';
                if (!is_string($role)) {
                    throw new DomainException('Selecciona un rol válido.');
                }
                $households->changeMemberRole((int) $householdId, (int) $memberId, $userId, $role);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'El rol del integrante fue actualizado.'];
            } else {
                $status = $_POST['status'] ?? '';
                if (!is_string($status)) {
                    throw new DomainException('Selecciona un estado válido.');
                }
                $households->setMemberStatus((int) $householdId, (int) $memberId, $userId, $status);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'El estado del integrante fue actualizado.'];
            }
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect($redirectPath);
    }

    if ($path === '/accounts' && $method === 'GET') {
        $userId = authenticated_user_id();
        $userHouseholds = $households->forUser($userId);
        $manageableHouseholds = array_values(array_filter(
            $userHouseholds,
            static fn (array $household): bool => in_array($household['role'], ['owner', 'admin'], true)
        ));

        render_page('accounts', [
            'title' => 'Cuentas',
            'accounts' => $accountService->listForUser($userId),
            'types' => $accountService->types(),
            'manageableHouseholds' => $manageableHouseholds,
        ]);
        exit;
    }

    if ($path === '/accounts' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();

        try {
            $accountService->create($userId, $_POST);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La cuenta quedó registrada.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/accounts');
    }

    if ($path === '/accounts/archive' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);

        try {
            if (!$accountId || $accountId < 1) {
                throw new DomainException('Selecciona una cuenta válida.');
            }
            $accountService->archive($userId, (int) $accountId);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La cuenta fue archivada; su historial se conserva.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/accounts');
    }

    if ($path === '/liabilities' && $method === 'GET') {
        $userId = authenticated_user_id();
        $userHouseholds = $households->forUser($userId);
        $manageableHouseholds = array_values(array_filter(
            $userHouseholds,
            static fn (array $household): bool => in_array($household['role'], ['owner', 'admin'], true)
        ));
        render_page('liabilities', [
            'title' => 'Deudas y tarjetas',
            'liabilities' => $liabilityService->listForUser($userId),
            'activeLiabilities' => $liabilityService->listForUser($userId, true),
            'activeAccounts' => $accountService->listForUser($userId, true),
            'categories' => $operationService->categories(),
            'manageableHouseholds' => $manageableHouseholds,
            'chargeKey' => bin2hex(random_bytes(32)),
            'paymentKey' => bin2hex(random_bytes(32)),
            'disbursementKey' => bin2hex(random_bytes(32)),
        ]);
        exit;
    }

    if ($path === '/liabilities' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        try {
            $liabilityService->create($userId, $_POST);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La deuda quedó registrada.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/liabilities');
    }

    if (
        in_array($path, ['/liabilities/charge', '/liabilities/payment', '/liabilities/disbursement'], true)
        && $method === 'POST'
    ) {
        require_csrf();
        $userId = authenticated_user_id();
        try {
            if ($path === '/liabilities/charge') {
                $liabilityService->charge($userId, $_POST);
                $successMessage = 'El cargo quedó registrado como gasto y aumentó el saldo de la deuda.';
            } elseif ($path === '/liabilities/payment') {
                $liabilityService->payment($userId, $_POST);
                $successMessage = 'El pago quedó registrado; solo los intereses se cuentan como gasto.';
            } else {
                $liabilityService->disburse($userId, $_POST);
                $successMessage = 'El desembolso se registró sin contabilizarlo como ingreso.';
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => $successMessage];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/liabilities');
    }

    if ($path === '/liabilities/archive' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $liabilityId = filter_var($_POST['liability_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if (!$liabilityId || $liabilityId < 1) {
                throw new DomainException('Selecciona una deuda válida.');
            }
            $liabilityService->archive($userId, (int) $liabilityId);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La deuda fue archivada; su historial se conserva.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/liabilities');
    }

    if ($path === '/reports/export.csv' && $method === 'GET') {
        $userId = authenticated_user_id();
        try {
            $report = $reportService->monthly(
                $userId,
                $_GET['year'] ?? date('Y'),
                $_GET['month'] ?? date('n'),
                $_GET['household_id'] ?? 'personal'
            );
            header('Content-Type: text/csv; charset=utf-8');
            header(sprintf(
                'Content-Disposition: attachment; filename="mi-finanzas-informe-%04d-%02d.csv"',
                $report['year'],
                $report['month']
            ));
            header('Cache-Control: no-store, private');
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new RuntimeException('No se pudo preparar la descarga CSV.');
            }
            fwrite($output, "\xEF\xBB\xBF");
            $writeCsvRow = static function ($stream, array $row): void {
                if (fputcsv($stream, $row, ';', '"', '\\') === false) {
                    throw new RuntimeException('No se pudo generar el archivo CSV.');
                }
            };
            $safeText = static function (string $value): string {
                return preg_match('/\A[\s]*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
            };
            $writeCsvRow($output, ['Informe mensual', sprintf('%04d-%02d', $report['year'], $report['month'])]);
            $writeCsvRow($output, ['Ingresos', $report['income_total']]);
            $writeCsvRow($output, ['Gastos', $report['expense_total']]);
            $writeCsvRow($output, ['Balance del período', $report['net_total']]);
            $writeCsvRow($output, []);
            $writeCsvRow($output, ['Tipo', 'Categoría', 'Total']);
            foreach ($report['categories'] as $category) {
                $writeCsvRow($output, [
                    $category['kind'] === 'income' ? 'Ingreso' : 'Gasto',
                    $safeText((string) $category['name']),
                    (string) $category['total'],
                ]);
            }
            fclose($output);
        } catch (DomainException $exception) {
            http_response_code(422);
            render_page('error', ['title' => 'Informe no disponible', 'message' => $exception->getMessage()]);
        }
        exit;
    }

    if ($path === '/forecast' && $method === 'GET') {
        $userId = authenticated_user_id();
        try {
            $projection = $projectionService->project($userId, $_GET['space'] ?? 'personal');
            render_page('forecast', [
                'title' => 'Proyección de flujo de caja',
                'projection' => $projection,
                'spaces' => $financialSpaces->listForUser($userId),
            ]);
        } catch (DomainException $exception) {
            http_response_code(422);
            render_page('error', ['title' => 'Proyección no disponible', 'message' => $exception->getMessage()]);
        }
        exit;
    }

    if ($path === '/debt-plan' && $method === 'GET') {
        $userId = authenticated_user_id();
        try {
            $planData = $debtPlanService->plan(
                $userId,
                $_GET['space'] ?? 'personal',
                $_GET['payments'] ?? []
            );
            render_page('debt-plan', [
                'title' => 'Plan de pago de deudas',
                'planData' => $planData,
                'spaces' => $financialSpaces->listForUser($userId),
            ]);
        } catch (DomainException $exception) {
            http_response_code(422);
            render_page('error', ['title' => 'Simulación no disponible', 'message' => $exception->getMessage()]);
        }
        exit;
    }

    if ($path === '/reports/print' && $method === 'GET') {
        $userId = authenticated_user_id();
        try {
            $report = $reportService->monthly(
                $userId,
                $_GET['year'] ?? date('Y'),
                $_GET['month'] ?? date('n'),
                $_GET['household_id'] ?? 'personal'
            );
            render_page('reports', [
                'title' => 'Informe para imprimir',
                'report' => $report,
                'households' => $reportService->spacesForUser($userId),
                'printOnly' => true,
            ]);
        } catch (DomainException $exception) {
            http_response_code(422);
            render_page('error', ['title' => 'Informe no disponible', 'message' => $exception->getMessage()]);
        }
        exit;
    }

    if ($path === '/reports' && $method === 'GET') {
        $userId = authenticated_user_id();
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('n');
        $householdId = $_GET['household_id'] ?? 'personal';
        try {
            $report = $reportService->monthly($userId, $year, $month, $householdId);
            render_page('reports', [
                'title' => 'Resumen financiero',
                'report' => $report,
                'households' => $reportService->spacesForUser($userId),
            ]);
        } catch (DomainException $exception) {
            http_response_code(422);
            render_page('error', ['title' => 'Informe no disponible', 'message' => $exception->getMessage()]);
        }
        exit;
    }

    if ($path === '/exports/backup' && $method === 'GET') {
        $userId = authenticated_user_id();
        $backup = $backupService->forUser($userId);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mi-finanzas-respaldo-' . date('Y-m-d') . '.json"');
        header('Cache-Control: no-store, private');
        echo json_encode(
            $backup,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        exit;
    }

    if ($path === '/recurring' && $method === 'GET') {
        $userId = authenticated_user_id();
        render_page('recurring', [
            'title' => 'Movimientos recurrentes',
            'schedules' => $recurringService->listForUser($userId),
            'activeAccounts' => $accountService->listForUser($userId, true),
            'categories' => $operationService->categories(),
        ]);
        exit;
    }

    if ($path === '/recurring' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        try {
            $recurringService->create($userId, $_POST);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La programación quedó guardada. Los movimientos requerirán tu confirmación.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/recurring');
    }

    if ($path === '/recurring/confirm' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $scheduleId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT);
        $dueDate = $_POST['due_date'] ?? null;
        try {
            if (!$scheduleId || $scheduleId < 1 || !is_string($dueDate)) {
                throw new DomainException('Selecciona una programación válida.');
            }
            $recurringService->confirmDue($userId, (int) $scheduleId, $dueDate);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'El movimiento vencido fue confirmado y registrado.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/recurring');
    }

    if ($path === '/recurring/status' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        try {
            $recurringService->changeStatus(
                $userId,
                $_POST['schedule_id'] ?? null,
                $_POST['status'] ?? null
            );
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'El estado de la programación fue actualizado.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/recurring');
    }

    if ($path === '/transactions' && $method === 'GET') {
        $userId = authenticated_user_id();
        render_page('transactions', [
            'title' => 'Movimientos',
            'activeAccounts' => $accountService->listForUser($userId, true),
            'categories' => $operationService->categories(),
            'recentOperations' => $operationService->recentForUser($userId),
            'singleIdempotencyKey' => bin2hex(random_bytes(32)),
            'transferIdempotencyKey' => bin2hex(random_bytes(32)),
            'operationLabels' => [
                'income' => 'Ingreso',
                'expense' => 'Gasto',
                'transfer' => 'Transferencia',
                'adjustment' => 'Ajuste',
                'reversal' => 'Anulación',
                'liability_payment' => 'Pago de deuda',
                'liability_disbursement' => 'Desembolso',
            ],
        ]);
        exit;
    }

    if ($path === '/transactions' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();

        try {
            $operationService->createSingle($userId, $_POST);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'El movimiento fue confirmado y registrado una sola vez.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/transactions');
    }

    if ($path === '/transactions/transfer' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();

        try {
            $operationService->transfer($userId, $_POST);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La transferencia quedó confirmada; no se contabilizó como ingreso ni gasto.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/transactions');
    }

    if ($path === '/transactions/reverse' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $operationId = filter_var($_POST['operation_id'] ?? null, FILTER_VALIDATE_INT);

        try {
            if (!$operationId || $operationId < 1) {
                throw new DomainException('Selecciona una operación válida.');
            }
            $operationService->reverse(
                $userId,
                (int) $operationId,
                $_POST['idempotency_key'] ?? null
            );
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La anulación compensatoria quedó registrada. El historial original se conserva.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/transactions');
    }

    if ($path === '/budgets' && $method === 'GET') {
        $userId = authenticated_user_id();
        $spaceKey = $_GET['space'] ?? 'personal';
        try {
            $budgetData = $financialPlanning->budgets($userId, $spaceKey);
            render_page('budgets', [
                'title' => 'Presupuestos mensuales',
                'spaces' => $financialSpaces->listForUser($userId),
                'data' => $budgetData,
            ]);
        } catch (DomainException $exception) {
            http_response_code(422);
            render_page('error', ['title' => 'Presupuestos no disponibles', 'message' => $exception->getMessage()]);
        }
        exit;
    }

    if ($path === '/budgets' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $spaceKey = $_POST['space'] ?? 'personal';
        try {
            $financialPlanning->saveBudget($userId, $_POST);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'El presupuesto quedó guardado para el ciclo actual.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/budgets?space=' . rawurlencode(is_string($spaceKey) ? $spaceKey : 'personal'));
    }

    if ($path === '/goals' && $method === 'GET') {
        $userId = authenticated_user_id();
        $spaceKey = $_GET['space'] ?? 'personal';
        try {
            $goalData = $financialPlanning->goals($userId, $spaceKey);
            $entryKeys = [];
            foreach ($goalData['goals'] as $goal) {
                $entryKeys[(int) $goal['id']] = bin2hex(random_bytes(32));
            }
            render_page('goals', [
                'title' => 'Metas de ahorro',
                'spaces' => $financialSpaces->listForUser($userId),
                'data' => $goalData,
                'entryKeys' => $entryKeys,
            ]);
        } catch (DomainException $exception) {
            http_response_code(422);
            render_page('error', ['title' => 'Metas no disponibles', 'message' => $exception->getMessage()]);
        }
        exit;
    }

    if ($path === '/goals' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $spaceKey = $_POST['space'] ?? 'personal';
        try {
            $financialPlanning->createGoal($userId, $_POST);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La meta de ahorro quedó creada.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/goals?space=' . rawurlencode(is_string($spaceKey) ? $spaceKey : 'personal'));
    }

    if ($path === '/goals/entry' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $spaceKey = $_POST['space'] ?? 'personal';
        try {
            $financialPlanning->addGoalEntry($userId, $_POST);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'El aporte o retiro quedó registrado, sin afectar cuentas ni informes de ingresos y gastos.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/goals?space=' . rawurlencode(is_string($spaceKey) ? $spaceKey : 'personal'));
    }

    if ($path === '/goals/archive' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $spaceKey = $_POST['space'] ?? 'personal';
        try {
            $financialPlanning->archiveGoal($userId, $spaceKey, $_POST['goal_id'] ?? null);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La meta quedó archivada; su historial se conserva.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        redirect('/goals?space=' . rawurlencode(is_string($spaceKey) ? $spaceKey : 'personal'));
    }

    if ($path === '/imports' && $method === 'GET') {
        $userId = authenticated_user_id();
        $preview = null;
        if (isset($_GET['batch'])) {
            try {
                $preview = $csvImportService->previewForUser($userId, $_GET['batch']);
            } catch (DomainException $exception) {
                http_response_code(422);
                render_page('error', ['title' => 'Importación no disponible', 'message' => $exception->getMessage()]);
                exit;
            }
        }
        $categories = $operationService->categories();
        $categoryNames = [];
        foreach ($categories as $category) {
            $categoryNames[(int) $category['id']] = $category['name'];
        }
        render_page('imports', [
            'title' => 'Importar movimientos CSV',
            'activeAccounts' => $accountService->listForUser($userId, true),
            'categories' => $categories,
            'categoryNames' => $categoryNames,
            'recentImports' => $csvImportService->recentForUser($userId),
            'preview' => $preview,
        ]);
        exit;
    }

    if ($path === '/imports/preview' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        try {
            $batchId = $csvImportService->createPreview($userId, $_POST, $_FILES['csv_file'] ?? null);
            redirect('/imports?batch=' . $batchId);
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
            redirect('/imports');
        }
    }

    if ($path === '/imports/confirm' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $batchId = $_POST['batch_id'] ?? null;
        try {
            $result = $csvImportService->confirm($userId, $batchId);
            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => 'Importación confirmada: ' . $result['processed'] . ' filas procesadas; ' . $result['duplicates'] . ' duplicadas omitidas.',
            ];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        $redirectBatch = filter_var($batchId, FILTER_VALIDATE_INT);
        redirect('/imports' . ($redirectBatch && $redirectBatch > 0 ? '?batch=' . $redirectBatch : ''));
    }

    if ($path === '/imports/cancel' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $batchId = $_POST['batch_id'] ?? null;
        try {
            $csvImportService->cancel($userId, $batchId);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La vista previa se canceló; no se registró ningún movimiento.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        $redirectBatch = filter_var($batchId, FILTER_VALIDATE_INT);
        redirect('/imports' . ($redirectBatch && $redirectBatch > 0 ? '?batch=' . $redirectBatch : ''));
    }

    if ($path === '/settings' && $method === 'GET') {
        $userId = authenticated_user_id();
        $spaceKey = $_GET['space'] ?? 'personal';
        try {
            $settingsData = $financialSettings->get($userId, $spaceKey);
            $timezones = [
                'America/Bogota' => 'Colombia · Bogotá',
                'America/Lima' => 'Perú · Lima',
                'America/Guayaquil' => 'Ecuador · Guayaquil',
                'America/Panama' => 'Panamá',
                'America/Mexico_City' => 'México · Ciudad de México',
                'America/New_York' => 'Estados Unidos · Nueva York',
                'UTC' => 'UTC',
            ];
            if (!isset($timezones[$settingsData['settings']['timezone']])) {
                $timezones[$settingsData['settings']['timezone']] = $settingsData['settings']['timezone'];
            }
            render_page('settings', [
                'title' => 'Configuración financiera',
                'spaces' => $financialSpaces->listForUser($userId),
                'space' => $settingsData['space'],
                'settings' => $settingsData['settings'],
                'canManage' => $settingsData['can_manage'],
                'timezones' => $timezones,
            ]);
        } catch (DomainException $exception) {
            http_response_code(422);
            render_page('error', ['title' => 'Configuración no disponible', 'message' => $exception->getMessage()]);
        }
        exit;
    }

    if ($path === '/settings' && $method === 'POST') {
        require_csrf();
        $userId = authenticated_user_id();
        $spaceKey = $_POST['space'] ?? 'personal';
        try {
            $financialSettings->save($userId, $spaceKey, $_POST);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'La configuración del espacio quedó guardada.'];
        } catch (DomainException $exception) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        }
        $redirectSpace = is_string($spaceKey) ? $spaceKey : 'personal';
        redirect('/settings?space=' . rawurlencode($redirectSpace));
    }

    if ($path === '/' && $method === 'GET') {
        if (empty($_SESSION['user']['id'])) {
            redirect('/login');
        }

        if (!empty($_SESSION['pending_invitation'])) {
            $pendingToken = $_SESSION['pending_invitation'];
            unset($_SESSION['pending_invitation']);
            redirect('/invitations/accept?token=' . rawurlencode($pendingToken));
        }

        $invitationUrl = $_SESSION['invitation_url'] ?? null;
        unset($_SESSION['invitation_url']);
        $userId = (int) $_SESSION['user']['id'];
        try {
            $report = $reportService->monthly(
                $userId,
                $_GET['year'] ?? date('Y'),
                $_GET['month'] ?? date('n'),
                $_GET['household_id'] ?? 'personal'
            );
            $selectedHouseholdId = $report['household_id'];
            $activeAccounts = array_values(array_filter(
                $accountService->listForUser($userId, true),
                static fn (array $account): bool => $selectedHouseholdId === null
                    ? $account['scope'] === 'personal'
                    : $account['scope'] === 'household'
                        && (int) $account['household_id'] === $selectedHouseholdId
            ));
            render_page('dashboard', [
                'title' => 'Inicio',
                'households' => $households->forUser($userId),
                'invitationUrl' => $invitationUrl,
                'report' => $report,
                'activeAccounts' => $activeAccounts,
                'financialAlerts' => $financialAlerts->forUser($userId),
            ]);
        } catch (DomainException $exception) {
            http_response_code(422);
            render_page('error', ['title' => 'Panel no disponible', 'message' => $exception->getMessage()]);
        }
        exit;
    }

    http_response_code(404);
    render_page('error', ['title' => 'Página no encontrada', 'message' => 'La página solicitada no existe.']);
} catch (Throwable $exception) {
    audit_request_error($exception);
    http_response_code(500);
    render_page('error', [
        'title' => 'No pudimos completar la solicitud',
        'message' => 'Ocurrió un error interno. Inténtalo más tarde.',
    ]);
}
