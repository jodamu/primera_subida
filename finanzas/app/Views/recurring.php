<?php
$frequencyLabels = ['weekly' => 'Semanal', 'monthly' => 'Mensual', 'yearly' => 'Anual'];
$statusLabels = ['active' => 'Activa', 'paused' => 'Pausada', 'completed' => 'Completada', 'archived' => 'Archivada'];
?>

<section class="welcome">
  <p class="eyebrow">Fase 5 · Control con confirmación</p>
  <h1>Movimientos recurrentes</h1>
  <p class="muted">Programa ingresos o gastos para recibir un pendiente cuando llegue su fecha. Nada se registra en la cuenta hasta que lo confirmes.</p>
</section>

<?php if (!$activeAccounts): ?>
  <section class="panel empty-state">
    <strong>Primero agrega una cuenta activa</strong>
    <p>Necesitas una cuenta para programar un movimiento recurrente.</p>
    <a class="button button-primary" href="/accounts">Ir a cuentas</a>
  </section>
<?php else: ?>
  <section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">NUEVA PROGRAMACIÓN</p><h2>Crear movimiento recurrente</h2></div></div>
    <form method="post" action="/recurring" class="form-grid recurring-form">
      <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
      <label>Tipo
        <select name="operation_type" required data-recurring-type>
          <option value="expense">Gasto</option>
          <option value="income">Ingreso</option>
        </select>
      </label>
      <label>Cuenta
        <select name="account_id" required>
          <option value="">Selecciona una cuenta</option>
          <?php foreach ($activeAccounts as $account): ?>
            <option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?> · <?= $account['scope'] === 'household' ? 'Familiar' : 'Personal' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Importe (COP)
        <input type="number" name="amount" min="0.01" max="9999999999999.99" step="0.01" required>
      </label>
      <label>Categoría
        <select name="category_id" required data-recurring-category>
          <option value="">Selecciona una categoría</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= (int) $category['id'] ?>" data-kind="<?= e($category['kind']) ?>"><?= $category['kind'] === 'income' ? 'Ingreso' : 'Gasto' ?> · <?= e($category['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Frecuencia
        <select name="frequency" required>
          <option value="weekly">Semanal</option>
          <option value="monthly" selected>Mensual</option>
          <option value="yearly">Anual</option>
        </select>
      </label>
      <label>Repetir cada
        <span class="recurring-interval"><input type="number" name="interval_count" min="1" max="12" value="1" required><span>semana(s), mes(es) o año(s)</span></span>
      </label>
      <label>Primera fecha
        <input type="date" name="start_date" value="<?= e(date('Y-m-d')) ?>" required>
      </label>
      <label>Última fecha (opcional)
        <input type="date" name="end_date">
      </label>
      <label class="form-grid-full">Descripción
        <input type="text" name="description" maxlength="255" placeholder="Ej. Arriendo, salario o servicio" required>
      </label>
      <p class="form-grid-full field-help">Si la programación empieza el día 29, 30 o 31, en meses más cortos se usará el último día disponible. Cada fecha vencida aparecerá como pendiente para que decidas.</p>
      <button class="button button-primary" type="submit">Guardar programación</button>
    </form>
  </section>
<?php endif; ?>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">PROGRAMACIONES</p><h2>Próximos movimientos</h2></div></div>
  <?php if (!$schedules): ?>
    <div class="empty-state"><strong>Aún no hay movimientos programados</strong><p>Al crear una programación, su próxima fecha aparecerá aquí.</p></div>
  <?php else: ?>
    <div class="recurring-list">
      <?php foreach ($schedules as $schedule): ?>
        <article class="recurring-card">
          <div class="recurring-card-heading">
            <div>
              <p class="eyebrow"><?= $schedule['operation_type'] === 'income' ? 'INGRESO' : 'GASTO' ?> · <?= e($schedule['scope'] === 'household' ? 'Familiar' : 'Personal') ?></p>
              <h3><?= e($schedule['description']) ?></h3>
              <span class="table-subtitle"><?= e($schedule['account_name']) ?> · <?= e($schedule['category_name']) ?> · <?= e($frequencyLabels[$schedule['frequency']] ?? $schedule['frequency']) ?><?= (int) $schedule['interval_count'] > 1 ? ' · cada ' . (int) $schedule['interval_count'] : '' ?></span>
            </div>
            <div class="recurring-amount">
              <strong><?= e(\App\Support\DecimalAmount::format((string) $schedule['amount'])) ?></strong>
              <span class="status-pill status-<?= e($schedule['status']) ?>"><?= e($statusLabels[$schedule['status']] ?? $schedule['status']) ?></span>
            </div>
          </div>
          <p class="muted">Próxima fecha: <strong><?= e($schedule['next_due_date']) ?></strong><?= $schedule['end_date'] !== null ? ' · Hasta ' . e($schedule['end_date']) : '' ?></p>
          <div class="recurring-actions">
            <?php if (!empty($schedule['due_now']) && $schedule['status'] === 'active' && $schedule['occurrence_status'] !== 'confirmed'): ?>
              <form method="post" action="/recurring/confirm" data-confirm="¿Confirmas este movimiento? Se registrará en la cuenta seleccionada y afectará su saldo.">
                <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
                <input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>">
                <input type="hidden" name="due_date" value="<?= e($schedule['next_due_date']) ?>">
                <button class="button button-primary" type="submit">Revisar y confirmar</button>
              </form>
              <span class="status-pill status-pending">Pendiente de confirmación</span>
            <?php endif; ?>
            <?php if (in_array($schedule['status'], ['active', 'paused'], true)): ?>
              <form method="post" action="/recurring/status">
                <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
                <input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>">
                <input type="hidden" name="status" value="<?= $schedule['status'] === 'active' ? 'paused' : 'active' ?>">
                <button class="button button-quiet" type="submit"><?= $schedule['status'] === 'active' ? 'Pausar' : 'Reanudar' ?></button>
              </form>
              <form method="post" action="/recurring/status" data-confirm="¿Archivar esta programación? Su historial se conservará.">
                <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
                <input type="hidden" name="schedule_id" value="<?= (int) $schedule['id'] ?>">
                <input type="hidden" name="status" value="archived">
                <button class="button button-quiet" type="submit">Archivar</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
