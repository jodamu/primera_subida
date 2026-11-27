<?php
$monthNames = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$expenseCategories = array_values(array_filter(
    $report['categories'],
    static fn (array $category): bool => $category['kind'] === 'expense'
));
$maxExpense = 0.0;
foreach ($expenseCategories as $category) {
    $maxExpense = max($maxExpense, (float) $category['total']);
}
$expenseCategories = array_slice($expenseCategories, 0, 6);
$reportQuery = http_build_query([
    'year' => $report['year'],
    'month' => $report['month'],
    'household_id' => $report['household_id'] ?? 'personal',
]);
?>

<section class="welcome">
  <p class="eyebrow">Tu espacio financiero</p>
  <h1>Hola, <?= e($currentUser['name']) ?></h1>
  <p class="muted">Un resumen claro de tus ingresos, gastos y cuentas personales o compartidas.</p>
</section>

<section class="panel">
  <div class="panel-heading">
    <div><p class="eyebrow">FLUJO DE CAJA</p><h2>Resumen mensual</h2></div>
    <a class="button button-quiet" href="/reports?<?= e($reportQuery) ?>">Ver informe completo</a>
  </div>
  <form method="get" action="/" class="report-filters">
    <label>Mes
      <select name="month">
        <?php for ($number = 1; $number <= 12; $number++): ?>
          <option value="<?= $number ?>" <?= $number === $report['month'] ? 'selected' : '' ?>><?= e($monthNames[$number - 1]) ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <label>Año
      <input type="number" name="year" min="2000" max="2100" value="<?= (int) $report['year'] ?>" required>
    </label>
    <label>Espacio
      <select name="household_id">
        <option value="personal" <?= $report['household_id'] === null ? 'selected' : '' ?>>Personal</option>
        <?php foreach ($households as $household): ?>
          <option value="<?= (int) $household['id'] ?>" <?= $report['household_id'] === (int) $household['id'] ? 'selected' : '' ?>><?= e($household['name']) ?> · Familiar</option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button button-primary" type="submit">Actualizar</button>
  </form>
</section>

<section class="report-summary">
  <article class="report-card">
    <span>Ingresos</span>
    <strong><?= e(\App\Support\DecimalAmount::format($report['income_total'])) ?></strong>
  </article>
  <article class="report-card">
    <span>Gastos</span>
    <strong><?= e(\App\Support\DecimalAmount::format($report['expense_total'])) ?></strong>
  </article>
  <article class="report-card">
    <span>Balance del período</span>
    <strong><?= e(\App\Support\DecimalAmount::format($report['net_total'])) ?></strong>
  </article>
</section>

<section class="panel section-gap">
  <div class="panel-heading">
    <div><p class="eyebrow">AVISOS</p><h2>Próximos vencimientos y alertas</h2></div>
  </div>
  <?php if (!$financialAlerts): ?>
    <div class="empty-state"><strong>No hay alertas próximas</strong><p>Te avisaremos aquí cuando una programación, presupuesto o fecha de tarjeta requiera atención.</p></div>
  <?php else: ?>
    <div class="financial-alert-list">
      <?php foreach ($financialAlerts as $alert): ?>
        <article class="financial-alert financial-alert-<?= e($alert['severity']) ?>">
          <div><strong><?= e($alert['title']) ?></strong><p><?= e($alert['message']) ?> <span>· <?= e($alert['date']) ?></span></p></div>
          <a class="button button-quiet" href="<?= e($alert['href']) ?>">Revisar</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">GASTOS DEL PERÍODO</p><h2>Principales categorías</h2></div></div>
  <?php if (!$expenseCategories): ?>
    <div class="empty-state"><strong>Aún no hay gastos en este período</strong><p>Cuando registres movimientos, verás su distribución aquí.</p></div>
  <?php else: ?>
    <div class="category-chart" aria-label="Gastos por categoría">
      <?php foreach ($expenseCategories as $category): ?>
        <?php $barWidth = $maxExpense > 0 ? max(2, (int) round(((float) $category['total'] / $maxExpense) * 100)) : 0; ?>
        <div class="category-chart-row">
          <div class="category-chart-label"><span><?= e($category['name']) ?></span><strong><?= e(\App\Support\DecimalAmount::format($category['total'])) ?></strong></div>
          <progress class="category-chart-progress" value="<?= $barWidth ?>" max="100" aria-label="<?= e($category['name']) ?>: <?= e(\App\Support\DecimalAmount::format($category['total'])) ?>"><?= $barWidth ?>%</progress>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">CUENTAS ACTIVAS</p><h2>Tus saldos</h2></div><a href="/accounts">Administrar cuentas</a></div>
  <?php if (!$activeAccounts): ?>
    <div class="empty-state"><strong>Aún no tienes cuentas activas</strong><p>Agrega una cuenta para organizar tus movimientos.</p><a class="button button-primary" href="/accounts">Crear cuenta</a></div>
  <?php else: ?>
    <div class="account-grid">
      <?php foreach ($activeAccounts as $account): ?>
        <article class="account-card">
          <div class="account-card-heading"><h3><?= e($account['name']) ?></h3><span class="role"><?= $account['scope'] === 'household' ? 'Familiar' : 'Personal' ?></span></div>
          <p class="account-balance"><?= e(\App\Support\DecimalAmount::format((string) $account['current_balance'])) ?> <span><?= e($account['currency']) ?></span></p>
          <span class="account-detail"><?= e($account['type_name']) ?></span>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="panel section-gap">
  <div class="panel-heading">
    <div><p class="eyebrow">FINANZAS COMPARTIDAS</p><h2>Tus hogares</h2></div>
  </div>
  <?php if (!$households): ?>
    <p class="muted">Todavía no perteneces a un hogar compartido.</p>
  <?php else: ?>
    <div class="household-list">
      <?php foreach ($households as $household): ?>
        <article class="household-item">
          <div><strong><?= e($household['name']) ?></strong><span class="role"><?= e(ucfirst($household['role'])) ?></span></div>
          <?php if (in_array($household['role'], ['owner', 'admin'], true)): ?>
            <div class="household-management">
              <a class="button button-quiet" href="/households/members?household_id=<?= (int) $household['id'] ?>">Integrantes</a>
              <form method="post" action="/invitations" class="invite-form">
                <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
                <input type="hidden" name="household_id" value="<?= (int) $household['id'] ?>">
                <label class="sr-only" for="invite-email-<?= (int) $household['id'] ?>">Correo de la persona a invitar</label>
                <input id="invite-email-<?= (int) $household['id'] ?>" type="email" name="email" maxlength="254" placeholder="correo de tu pareja" required>
                <button class="button button-secondary" type="submit">Crear invitación</button>
              </form>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($invitationUrl)): ?>
    <div class="invite-result">
      <strong>Invitación lista</strong>
      <p>Comparte este enlace con la otra persona. Vence en 7 días y solo puede aceptarlo la cuenta del correo invitado.</p>
      <input type="text" readonly value="<?= e($invitationUrl) ?>" aria-label="Enlace de invitación">
    </div>
  <?php endif; ?>
</section>
