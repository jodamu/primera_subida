<section class="welcome">
  <p class="eyebrow">Fase 2 · Cuentas</p>
  <h1>Tus cuentas</h1>
  <p class="muted">Los saldos iniciales son una referencia de apertura; no se contabilizan como ingresos.</p>
</section>

<section class="panel account-form-panel">
  <div class="panel-heading">
    <div><p class="eyebrow">NUEVA CUENTA</p><h2>Agrega dónde está tu dinero</h2></div>
  </div>
  <p class="muted">Registra una cuenta bancaria, billetera o efectivo. El saldo inicial es el monto actual de apertura, no un ingreso.</p>
  <form method="post" action="/accounts" class="form-grid account-form-grid">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <label>Nombre de la cuenta
      <input type="text" name="name" maxlength="100" required placeholder="Ej. Ahorros principal">
    </label>
    <label>Tipo de cuenta
      <select name="account_type_id" required>
        <option value="">Selecciona un tipo</option>
        <?php foreach ($types as $type): ?>
          <option value="<?= (int) $type['id'] ?>"><?= e($type['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Ámbito
      <select name="scope" data-household-scope required>
        <option value="personal">Personal</option>
        <?php if ($manageableHouseholds): ?><option value="household">Familiar</option><?php endif; ?>
      </select>
    </label>
    <?php if ($manageableHouseholds): ?>
      <label data-household-field hidden>Hogar
        <select name="household_id" data-household-select disabled>
          <option value="">Selecciona un hogar</option>
          <?php foreach ($manageableHouseholds as $household): ?>
            <option value="<?= (int) $household['id'] ?>"><?= e($household['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <label>Saldo inicial (COP)
      <input type="number" name="initial_balance" min="0" max="9999999999999.99" step="0.01" value="0.00" required>
    </label>
    <label>Fecha del saldo inicial
      <input type="date" name="initial_balance_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required>
    </label>
    <label class="checkbox-card form-grid-full">
      <input type="checkbox" name="overdraft_enabled" value="1">
      <span><strong>Permitir sobregiro autorizado</strong><small>Úsalo solo si tu entidad realmente permite saldo negativo.</small></span>
    </label>
    <label>Límite de sobregiro (COP)
      <input type="number" name="overdraft_limit" min="0" max="9999999999999.99" step="0.01" value="0.00">
    </label>
    <div class="form-actions form-grid-full">
      <button class="button button-primary" type="submit">Guardar cuenta</button>
    </div>
  </form>
</section>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">SALDOS ACTUALES</p><h2>Historial de cuentas</h2></div></div>
  <?php if (!$accounts): ?>
    <div class="empty-state"><strong>Aún no tienes cuentas</strong><p>Agrega una cuenta para empezar a registrar movimientos.</p></div>
  <?php else: ?>
    <div class="account-grid">
      <?php foreach ($accounts as $account): ?>
        <article class="account-card">
          <div class="account-card-heading">
            <div><span class="eyebrow"><?= e($account['type_name']) ?> · <?= $account['scope'] === 'household' ? 'Familiar' : 'Personal' ?></span><h3><?= e($account['name']) ?></h3></div>
            <span class="status-pill status-<?= e($account['status']) ?>"><?= $account['status'] === 'active' ? 'Activa' : 'Archivada' ?></span>
          </div>
          <p class="account-balance"><?= e(\App\Support\DecimalAmount::format((string) $account['current_balance'])) ?> <span>COP</span></p>
          <p class="account-detail">Saldo inicial: <?= e(\App\Support\DecimalAmount::format((string) $account['initial_balance'])) ?> · <?= e($account['initial_balance_date']) ?></p>
          <?php if ($account['overdraft_enabled']): ?>
            <p class="account-detail">Sobregiro autorizado: <?= e(\App\Support\DecimalAmount::format((string) $account['overdraft_limit'])) ?></p>
          <?php endif; ?>
          <?php if ($account['status'] === 'active' && $account['can_manage']): ?>
            <form method="post" action="/accounts/archive" data-confirm="¿Archivar esta cuenta? No se podrán registrar nuevos movimientos, pero el historial se conservará.">
              <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
              <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
              <button class="button button-quiet" type="submit">Archivar cuenta</button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
