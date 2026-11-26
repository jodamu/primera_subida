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
  <link rel="stylesheet" href="/assets/app.css?v=<?= (int) filemtime(dirname(__DIR__, 2) . '/assets/app.css') ?>">
</head>
<body>
  <?php $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'; ?>
  <header class="topbar">
    <a class="brand" href="/">Mi Finanzas<span>Juntos, hacia un mejor equilibrio financiero.</span></a>
    <?php if (!empty($currentUser)): ?>
      <nav class="app-nav" aria-label="Navegación principal">
        <a href="/accounts" <?= $currentPath === '/accounts' ? 'aria-current="page"' : '' ?>>Cuentas</a>
        <a href="/transactions" <?= $currentPath === '/transactions' ? 'aria-current="page"' : '' ?>>Movimientos</a>
        <a href="/liabilities" <?= $currentPath === '/liabilities' ? 'aria-current="page"' : '' ?>>Deudas</a>
        <a href="/reports" <?= $currentPath === '/reports' ? 'aria-current="page"' : '' ?>>Informes</a>
        <details class="nav-group" <?= in_array($currentPath, ['/budgets', '/goals', '/imports', '/settings', '/recurring', '/exports/backup'], true) ? 'open' : '' ?>>
          <summary>Planificación</summary>
          <div class="nav-dropdown">
            <a href="/budgets" <?= $currentPath === '/budgets' ? 'aria-current="page"' : '' ?>>Presupuestos</a>
            <a href="/goals" <?= $currentPath === '/goals' ? 'aria-current="page"' : '' ?>>Metas de ahorro</a>
            <a href="/imports" <?= $currentPath === '/imports' ? 'aria-current="page"' : '' ?>>Importar CSV</a>
            <a href="/recurring" <?= $currentPath === '/recurring' ? 'aria-current="page"' : '' ?>>Recurrentes</a>
            <a href="/settings" <?= $currentPath === '/settings' ? 'aria-current="page"' : '' ?>>Configuración</a>
            <a href="/exports/backup">Descargar respaldo</a>
          </div>
        </details>
      </nav>
      <div class="topbar-user">
        <span><?= e($currentUser['name']) ?></span>
        <form method="post" action="/logout">
          <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
          <button class="button button-quiet" type="submit">Cerrar sesión</button>
        </form>
      </div>
    <?php endif; ?>
  </header>
  <main class="page">
    <?php if (!empty($flash)): ?>
      <div class="notice notice-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?php require $contentView; ?>
  </main>
  <footer class="footer">Mi Finanzas · Colombia · <?= date('Y') ?></footer>
  <script src="/assets/app.js?v=<?= (int) filemtime(dirname(__DIR__, 2) . '/assets/app.js') ?>" defer></script>
</body>
</html>
