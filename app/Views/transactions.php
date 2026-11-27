<section class="welcome">
  <p class="eyebrow">Fase 2 · Motor de movimientos</p>
  <h1>Movimientos</h1>
  <p class="muted">Los movimientos confirmados actualizan los saldos. Las transferencias cambian la distribución entre cuentas, no el total del espacio financiero.</p>
</section>

<?php if (!$activeAccounts): ?>
  <section class="panel empty-state">
    <strong>Primero agrega una cuenta</strong>
    <p>Necesitas una cuenta activa para registrar ingresos, gastos o transferencias.</p>
    <a class="button button-primary" href="/accounts">Ir a cuentas</a>
  </section>
<?php else: ?>
  <section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">INGRESO, GASTO O AJUSTE</p><h2>Registrar movimiento</h2></div></div>
    <form method="post" action="/transactions" class="form-grid">
      <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
      <input type="hidden" name="idempotency_key" value="<?= e($singleIdempotencyKey) ?>">
      <label>Tipo
        <select name="operation_type" required>
          <option value="income">Ingreso recibido</option>
          <option value="expense">Gasto confirmado</option>
          <option value="adjustment">Ajuste de saldo</option>
        </select>
      </label>
      <label>Cuenta
        <select name="account_id" required>
          <option value="">Selecciona una cuenta</option>
          <?php foreach ($activeAccounts as $account): ?>
            <option value="<?= (int) $account['id'] ?>">            <?= e($account['name']) ?> [<?= e($account['currency']) ?>] · <?= $account['scope'] === 'household' ? 'Familiar' : 'Personal' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Importe (moneda de la cuenta)
        <input type="number" name="amount" min="0.01" max="9999999999999.99" step="0.01" required>
      </label>
      <label>Categoría (requerida para ingreso o gasto)
        <select name="category_id">
          <option value="">Sin categoría</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= (int) $category['id'] ?>"><?= $category['kind'] === 'income' ? 'Ingreso' : 'Gasto' ?> · <?= e($category['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Dirección del ajuste (solo para ajustes)
        <select name="direction">
          <option value="inflow">Aumentar saldo</option>
          <option value="outflow">Disminuir saldo</option>
        </select>
      </label>
      <label>Fecha efectiva
        <input type="date" name="effective_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required>
      </label>
      <label class="form-grid-full">Descripción
        <input type="text" name="description" maxlength="255" required placeholder="Describe el movimiento">
      </label>
      <button class="button button-primary" type="submit">Confirmar movimiento</button>
    </form>
  </section>

  <section class="panel section-gap">
    <div class="panel-heading"><div><p class="eyebrow">ENTRE CUENTAS</p><h2>Transferir</h2></div></div>
    <p class="muted">Origen y destino deben pertenecer al mismo espacio (personal o al mismo hogar).</p>
    <form method="post" action="/transactions/transfer" class="form-grid">
      <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
      <input type="hidden" name="idempotency_key" value="<?= e($transferIdempotencyKey) ?>">
      <label>Cuenta de origen
        <select name="source_account_id" required>
          <option value="">Selecciona una cuenta</option>
          <?php foreach ($activeAccounts as $account): ?>
            <option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?> · <?= $account['scope'] === 'household' ? 'Familiar' : 'Personal' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Cuenta de destino
        <select name="destination_account_id" required>
          <option value="">Selecciona una cuenta</option>
          <?php foreach ($activeAccounts as $account): ?>
            <option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?> · <?= $account['scope'] === 'household' ? 'Familiar' : 'Personal' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Importe enviado (moneda de la cuenta de origen)
        <input type="number" name="amount" min="0.01" max="9999999999999.99" step="0.01" required>
      </label>
      <label>Importe recibido (solo si las monedas son distintas)
        <input type="number" name="destination_amount" min="0.01" max="9999999999999.99" step="0.01">
      </label>
      <label>Fecha efectiva
        <input type="date" name="effective_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required>
      </label>
      <label class="form-grid-full">Descripción
        <input type="text" name="description" maxlength="255" required placeholder="Ej. Movimiento entre mis cuentas">
      </label>
      <button class="button button-secondary" type="submit">Confirmar transferencia</button>
    </form>
  </section>
<?php endif; ?>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">ÚLTIMOS 50</p><h2>Historial confirmado</h2></div></div>
  <?php if (!$recentOperations): ?>
    <div class="empty-state"><strong>No hay movimientos confirmados</strong><p>Cuando registres uno, aparecerá aquí.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Cuenta(s)</th><th>Importe</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($recentOperations as $operation): ?>
            <tr>
              <td><?= e($operation['effective_date']) ?></td>
              <td><?= e($operationLabels[$operation['operation_type']] ?? $operation['operation_type']) ?></td>
              <td><?= e($operation['description']) ?><?php if ($operation['category_name']): ?><span class="table-subtitle"><?= e($operation['category_name']) ?></span><?php endif; ?></td>
              <td><?= e($operation['account_summary']) ?></td>
              <td><?= e(\App\Support\DecimalAmount::format((string) $operation['amount'])) ?></td>
              <td><span class="status-pill status-<?= e($operation['status']) ?>"><?= $operation['status'] === 'cancelled' ? 'Anulada' : 'Confirmada' ?></span></td>
              <td>
                <a class="button button-quiet" href="/attachments?operation_id=<?= (int) $operation['id'] ?>">Adjuntos<?= (int) ($operation['attachment_count'] ?? 0) > 0 ? ' (' . (int) $operation['attachment_count'] . ')' : '' ?></a>
                <?php if ($operation['status'] === 'confirmed' && $operation['operation_type'] !== 'reversal'): ?>
                  <form method="post" action="/transactions/reverse" data-confirm="¿Anular esta operación? Se creará un movimiento compensatorio y el registro original se conservará.">
                    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
                    <input type="hidden" name="operation_id" value="<?= (int) $operation['id'] ?>">
                    <input type="hidden" name="idempotency_key" value="<?= e(bin2hex(random_bytes(32))) ?>">
                    <button class="button button-quiet" type="submit">Anular</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
