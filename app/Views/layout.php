<!doctype html>
<html lang="es-CO">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#10233f">
  <meta name="description" content="<?= e($description ?? 'Mi Finanzas: herramienta web para organizar las finanzas personales y del hogar con claridad y privacidad.') ?>">
  <meta name="robots" content="<?= e($robots ?? 'noindex, nofollow') ?>">
  <title><?= e($title ?? 'Mi Finanzas') ?> · Mi Finanzas</title>
  <?php if (!empty($canonical)): ?>
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta property="og:locale" content="es_CO">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Mi Finanzas">
    <meta property="og:title" content="<?= e($ogTitle ?? $title ?? 'Mi Finanzas') ?>">
    <meta property="og:description" content="<?= e($ogDescription ?? $description ?? '') ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= e($ogTitle ?? $title ?? 'Mi Finanzas') ?>">
    <meta name="twitter:description" content="<?= e($ogDescription ?? $description ?? '') ?>">
  <?php endif; ?>
  <?php if (!empty($structuredData)): ?>
    <script type="application/ld+json" nonce="<?= e($cspNonce) ?>"><?= json_encode($structuredData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script>
  <?php endif; ?>
  <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" integrity="sha384-CK2SzKma4jA5H/MXDUU7i1TqZlCFaD4T01vtyDFvPlD97JQyS+IsSh1nI2EFbpyk" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.10.0/dist/css/adminlte.min.css" integrity="sha384-UqRR9PaoK16nbf42Sqcd4FbL2CkbpbZuBJK7HfJZBUhZINJzdCZG/6lFkXWJZoB/" crossorigin="anonymous">
  <link rel="stylesheet" href="/assets/app.css?v=<?= (int) filemtime(dirname(__DIR__, 2) . '/assets/app.css') ?>">
</head>
<?php
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isCurrentRoute = static fn (string $route): bool => $currentPath === $route
    || ($route !== '/' && str_starts_with($currentPath, $route . '/'));
?>
<body class="<?= !empty($currentUser) ? 'layout-fixed sidebar-expand-lg bg-body-tertiary' : 'public-layout' ?>">
  <?php if (!empty($currentUser)): ?>
    <div class="app-wrapper">
      <nav class="app-header navbar navbar-expand bg-body" aria-label="Barra superior">
        <div class="container-fluid">
          <ul class="navbar-nav">
            <li class="nav-item">
              <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button" aria-label="Mostrar u ocultar el menú">
                <i class="bi bi-list" aria-hidden="true"></i>
              </a>
            </li>
          </ul>
          <div class="navbar-nav ms-auto align-items-center">
            <span class="nav-item d-none d-sm-flex align-items-center px-3 text-secondary">Hola, <?= e($currentUser['name']) ?></span>
            <div class="nav-item">
              <form method="post" action="/logout" class="mb-0">
                <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
                <button class="nav-link d-flex align-items-center gap-2" type="submit">
                  <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                  <span>Cerrar sesión</span>
                </button>
              </form>
            </div>
          </div>
        </div>
      </nav>

      <aside class="app-sidebar shadow" data-bs-theme="dark">
        <div class="sidebar-brand">
          <a href="/" class="brand-link">
            <img src="/assets/favicon.svg" alt="" class="brand-image opacity-100">
            <span class="brand-text fw-semibold">Mi Finanzas</span>
          </a>
        </div>
        <div class="sidebar-wrapper">
          <nav class="mt-2" aria-label="Navegación principal">
            <ul class="nav sidebar-menu flex-column" role="menu">
              <li class="nav-item">
                <a href="/" class="nav-link <?= $isCurrentRoute('/') ? 'active' : '' ?>" <?= $isCurrentRoute('/') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-grid-1x2-fill" aria-hidden="true"></i><p>Resumen</p>
                </a>
              </li>
              <li class="nav-header">FINANZAS</li>
              <li class="nav-item">
                <a href="/accounts" class="nav-link <?= $isCurrentRoute('/accounts') ? 'active' : '' ?>" <?= $isCurrentRoute('/accounts') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-wallet2" aria-hidden="true"></i><p>Cuentas</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/transactions" class="nav-link <?= $isCurrentRoute('/transactions') ? 'active' : '' ?>" <?= $isCurrentRoute('/transactions') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-arrow-left-right" aria-hidden="true"></i><p>Movimientos</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/liabilities" class="nav-link <?= $isCurrentRoute('/liabilities') ? 'active' : '' ?>" <?= $isCurrentRoute('/liabilities') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-credit-card-2-front" aria-hidden="true"></i><p>Deudas</p>
                </a>
              </li>
              <li class="nav-header">PLANIFICACIÓN</li>
              <li class="nav-item">
                <a href="/budgets" class="nav-link <?= $isCurrentRoute('/budgets') ? 'active' : '' ?>" <?= $isCurrentRoute('/budgets') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-pie-chart" aria-hidden="true"></i><p>Presupuestos</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/goals" class="nav-link <?= $isCurrentRoute('/goals') ? 'active' : '' ?>" <?= $isCurrentRoute('/goals') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-bullseye" aria-hidden="true"></i><p>Metas de ahorro</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/recurring" class="nav-link <?= $isCurrentRoute('/recurring') ? 'active' : '' ?>" <?= $isCurrentRoute('/recurring') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-arrow-repeat" aria-hidden="true"></i><p>Recurrentes</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/forecast" class="nav-link <?= $isCurrentRoute('/forecast') ? 'active' : '' ?>" <?= $isCurrentRoute('/forecast') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-graph-up-arrow" aria-hidden="true"></i><p>Proyección</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/debt-plan" class="nav-link <?= $isCurrentRoute('/debt-plan') ? 'active' : '' ?>" <?= $isCurrentRoute('/debt-plan') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-calculator" aria-hidden="true"></i><p>Plan de deudas</p>
                </a>
              </li>
              <li class="nav-header">ANÁLISIS</li>
              <li class="nav-item">
                <a href="/reports" class="nav-link <?= $isCurrentRoute('/reports') ? 'active' : '' ?>" <?= $isCurrentRoute('/reports') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-bar-chart-line" aria-hidden="true"></i><p>Informes</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/trends" class="nav-link <?= $isCurrentRoute('/trends') ? 'active' : '' ?>" <?= $isCurrentRoute('/trends') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-activity" aria-hidden="true"></i><p>Tendencias</p>
                </a>
              </li>
              <li class="nav-header">HERRAMIENTAS</li>
              <li class="nav-item">
                <a href="/imports" class="nav-link <?= $isCurrentRoute('/imports') ? 'active' : '' ?>" <?= $isCurrentRoute('/imports') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-file-earmark-arrow-up" aria-hidden="true"></i><p>Importar CSV</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/reconciliation" class="nav-link <?= $isCurrentRoute('/reconciliation') ? 'active' : '' ?>" <?= $isCurrentRoute('/reconciliation') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-check2-square" aria-hidden="true"></i><p>Conciliar extracto</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/exports/backup" class="nav-link <?= $isCurrentRoute('/exports/backup') ? 'active' : '' ?>">
                  <i class="nav-icon bi bi-download" aria-hidden="true"></i><p>Descargar respaldo</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/backups/restore" class="nav-link <?= $isCurrentRoute('/backups/restore') ? 'active' : '' ?>" <?= $isCurrentRoute('/backups/restore') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-upload" aria-hidden="true"></i><p>Restaurar respaldo</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/categories" class="nav-link <?= $isCurrentRoute('/categories') ? 'active' : '' ?>" <?= $isCurrentRoute('/categories') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-tags" aria-hidden="true"></i><p>Categorías</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/currencies" class="nav-link <?= $isCurrentRoute('/currencies') ? 'active' : '' ?>" <?= $isCurrentRoute('/currencies') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-currency-exchange" aria-hidden="true"></i><p>Monedas y tasas</p>
                </a>
              </li>
              <li class="nav-item">
                <a href="/settings" class="nav-link <?= $isCurrentRoute('/settings') ? 'active' : '' ?>" <?= $isCurrentRoute('/settings') ? 'aria-current="page"' : '' ?>>
                  <i class="nav-icon bi bi-gear" aria-hidden="true"></i><p>Configuración</p>
                </a>
              </li>
            </ul>
          </nav>
        </div>
      </aside>

      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <?php if (!empty($flash)): ?>
              <div class="notice notice-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
            <?php endif; ?>
            <div class="page"><?php require $contentView; ?></div>
          </div>
        </div>
      </main>
      <footer class="app-footer">
        <strong>Mi Finanzas</strong><span class="ms-1">· Colombia · <?= date('Y') ?></span>
      </footer>
    </div>
  <?php else: ?>
    <div class="public-shell">
      <header class="public-header">
        <a class="brand" href="/">
          <img src="/assets/favicon.svg" alt="" width="34" height="34">
          <span><strong>Mi Finanzas</strong><small>Juntos, hacia un mejor equilibrio financiero.</small></span>
        </a>
        <nav class="public-nav" aria-label="Navegación principal">
          <a class="btn btn-outline-primary" href="/login">Iniciar sesión</a>
          <a class="btn btn-primary" href="/register">Crear cuenta</a>
        </nav>
      </header>
      <main class="public-main">
        <?php if (!empty($flash)): ?>
          <div class="notice notice-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <div class="page"><?php require $contentView; ?></div>
      </main>
      <footer class="public-footer">Mi Finanzas · Colombia · <?= date('Y') ?></footer>
    </div>
  <?php endif; ?>
  <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" integrity="sha384-G/EV+4j2dNv+tEPo3++6LCgdCROaejBqfUeNjuKAiuXbjrxilcCdDz6ZAVfHWe1Y" crossorigin="anonymous" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/admin-lte@4.10.0/dist/js/adminlte.min.js" integrity="sha384-4ah0JNKzuIoSs74l3UZo0AC1jFbROkz6/LiwEuWtTBIHy53qLxRCg5ePPP05KLq1" crossorigin="anonymous" defer></script>
  <script src="/assets/app.js?v=<?= (int) filemtime(dirname(__DIR__, 2) . '/assets/app.js') ?>" defer></script>
</body>
</html>
