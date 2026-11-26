<section class="welcome">
  <p class="eyebrow">Planificación mensual</p>
  <h1>Presupuestos por categoría</h1>
  <p class="muted">Compara tus límites con los gastos confirmados del ciclo. Las anulaciones reducen el gasto y los aportes a metas no se incluyen.</p>
</section>

<section class="panel">
  <form method="get" action="/budgets" class="space-picker">
    <label>Espacio
      <select name="space">
        <?php foreach ($spaces as $candidate): ?>
          <option value="<?= e($candidate['key']) ?>" <?= $candidate['key'] === $data['space']['key'] ? 'selected' : '' ?>>
            <?= e($candidate['name']) ?><?= $candidate['scope'] === 'household' ? ' · Familiar' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button button-secondary" type="submit">Ver espacio</button>
  </form>

  <div class="panel-heading section-gap">
    <div><p class="eyebrow"><?= e($data['space']['name']) ?></p><h2>Ciclo <?= e($data['period_start']) ?> al <?= e($data['period_end']) ?></h2></div>
  </div>
  <?php if (!$data['can_manage']): ?>
    <p class="notice notice-info">Solo el propietario o un administrador puede cambiar los presupuestos de este hogar.</p>
  <?php else: ?>
    <form method="post" action="/budgets" class="form-grid budget-form">
      <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
      <input type="hidden" name="space" value="<?= e($data['space']['key']) ?>">
      <label>Categoría de gasto
        <select name="category_id" required>
          <option value="">Selecciona una categoría</option>
          <?php foreach ($data['categories'] as $category): ?>
            <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Límite del ciclo (COP)
        <input type="number" name="amount_limit" min="0.01" step="0.01" required placeholder="Ej. 500000">
      </label>
      <div class="form-grid-full form-actions">
        <p class="field-help">Si la categoría ya tiene un límite en este ciclo, se actualizará. Aviso configurado al <?= (int) $data['alert_percent'] ?>%.</p>
        <button class="button button-primary" type="submit">Guardar presupuesto</button>
      </div>
    </form>
  <?php endif; ?>
</section>

<section class="budget-list section-gap">
  <?php if (!$data['budgets']): ?>
    <div class="panel empty-state"><strong>Aún no hay presupuestos para este ciclo.</strong><p>Elige una categoría y define un límite mensual.</p></div>
  <?php endif; ?>
  <?php foreach ($data['budgets'] as $budget): ?>
    <?php
      $limitCents = (int) str_replace('.', '', (string) $budget['amount_limit']);
      $spentCents = (int) str_replace('.', '', (string) $budget['spent']);
      $percent = $limitCents > 0 ? max(0, ($spentCents / $limitCents) * 100) : 0;
      $isAlert = $percent >= $data['alert_percent'];
    ?>
    <article class="panel budget-card">
      <div class="budget-heading">
        <div><p class="eyebrow"><?= e($data['space']['name']) ?></p><h2><?= e($budget['category_name']) ?></h2></div>
        <span class="budget-percent <?= $isAlert ? 'budget-alert' : '' ?>"><?= number_format($percent, 0) ?>%</span>
      </div>
      <progress class="budget-progress <?= $isAlert ? 'budget-progress-alert' : '' ?>" value="<?= e((string) min(100, $percent)) ?>" max="100" aria-label="Uso del presupuesto: <?= number_format($percent, 0) ?> por ciento"></progress>
      <p class="budget-amounts"><strong><?= e(\App\Support\DecimalAmount::format((string) $budget['spent'])) ?></strong> usados de <?= e(\App\Support\DecimalAmount::format((string) $budget['amount_limit'])) ?></p>
      <?php if ($isAlert): ?><p class="field-help">Alcanzaste el umbral de aviso de este espacio.</p><?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>
