<section class="welcome">
  <p class="eyebrow">Ahorro planificado</p>
  <h1>Metas de ahorro</h1>
  <p class="muted">Registra aportes y retiros para seguir el progreso. Estos movimientos se guardan por separado y no cambian los saldos de tus cuentas ni los informes de ingresos y gastos.</p>
</section>

<section class="panel">
  <form method="get" action="/goals" class="space-picker">
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
    <div><p class="eyebrow"><?= e($data['space']['name']) ?></p><h2>Crear una meta</h2></div>
  </div>
  <form method="post" action="/goals" class="form-grid">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <input type="hidden" name="space" value="<?= e($data['space']['key']) ?>">
    <label>Nombre
      <input type="text" name="name" maxlength="120" required placeholder="Ej. Fondo de emergencias">
    </label>
    <label>Objetivo (COP)
      <input type="number" name="target_amount" min="0.01" step="0.01" required placeholder="Ej. 3000000">
    </label>
    <label>Fecha objetivo (opcional)
      <input type="date" name="target_date" min="<?= e(date('Y-m-d')) ?>">
    </label>
    <div class="form-actions"><span class="field-help">Los aportes se registran aparte de tus movimientos de cuenta.</span><button class="button button-primary" type="submit">Crear meta</button></div>
  </form>
</section>

<section class="goal-list section-gap">
  <?php if (!$data['goals']): ?>
    <div class="panel empty-state"><strong>Aún no tienes metas.</strong><p>Crea una meta para empezar a registrar tu progreso.</p></div>
  <?php endif; ?>
  <?php foreach ($data['goals'] as $goal): ?>
    <?php
      $targetCents = (int) str_replace('.', '', (string) $goal['target_amount']);
      $savedCents = (int) str_replace('.', '', (string) $goal['saved_amount']);
      $progress = $targetCents > 0 ? max(0, ($savedCents / $targetCents) * 100) : 0;
    ?>
    <article class="panel goal-card">
      <div class="budget-heading">
        <div><p class="eyebrow"><?= e($data['space']['name']) ?></p><h2><?= e($goal['name']) ?></h2></div>
        <span class="status-pill <?= $goal['status'] === 'archived' ? 'status-archived' : '' ?>"><?= $goal['status'] === 'active' ? 'Activa' : 'Archivada' ?></span>
      </div>
      <p class="budget-amounts"><strong><?= e(\App\Support\DecimalAmount::format((string) $goal['saved_amount'])) ?></strong> ahorrados de <?= e(\App\Support\DecimalAmount::format((string) $goal['target_amount'])) ?></p>
      <?php if ($goal['target_date']): ?><p class="field-help">Fecha objetivo: <?= e($goal['target_date']) ?></p><?php endif; ?>
      <progress class="budget-progress" value="<?= e((string) min(100, $progress)) ?>" max="100" aria-label="Progreso de la meta: <?= number_format($progress, 0) ?> por ciento"></progress>

      <?php if ($goal['status'] === 'active'): ?>
        <form method="post" action="/goals/entry" class="form-grid goal-entry-form">
          <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
          <input type="hidden" name="space" value="<?= e($data['space']['key']) ?>">
          <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
          <input type="hidden" name="idempotency_key" value="<?= e($entryKeys[(int) $goal['id']]) ?>">
          <label>Tipo
            <select name="direction">
              <option value="contribution">Aporte</option>
              <option value="withdrawal">Retiro</option>
            </select>
          </label>
          <label>Valor (COP)
            <input type="number" name="amount" min="0.01" step="0.01" required>
          </label>
          <label>Fecha
            <input type="date" name="effective_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
          </label>
          <label>Descripción
            <input type="text" name="description" maxlength="255" required placeholder="Ej. Aporte quincenal">
          </label>
          <div class="form-actions form-grid-full"><span class="field-help">El retiro no puede superar lo ahorrado.</span><button class="button button-secondary" type="submit">Registrar</button></div>
        </form>
        <?php if ($data['can_manage']): ?>
          <form method="post" action="/goals/archive" class="goal-archive-form">
            <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
            <input type="hidden" name="space" value="<?= e($data['space']['key']) ?>">
            <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
            <button class="button button-quiet" type="submit">Archivar meta</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>
