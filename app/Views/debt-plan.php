<?php
$spaceKey = $planData['space']['key'];
$debtQuery = http_build_query(['space' => $spaceKey]);
?>

<section class="welcome">
  <p class="eyebrow">Fase 6 · Simulación</p>
  <h1>Plan de pago de deudas</h1>
  <p class="muted">Prueba un pago mensual para estimar cuánto tardarías y cuánto interés aproximado pagarías. Este valor de simulación no se guarda ni registra; puedes guardar una cuota objetivo en Deudas para dar seguimiento a los pagos reales que ya registraste. El cálculo supone pagos al final de cada mes y tasa anual dividida entre 12; confirma condiciones con tu entidad.</p>
</section>

<section class="panel">
  <form method="get" action="/debt-plan" class="space-picker">
    <label>Espacio financiero
      <select name="space">
        <?php foreach ($spaces as $space): ?>
          <option value="<?= e($space['key']) ?>" <?= $spaceKey === $space['key'] ? 'selected' : '' ?>><?= e($space['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button button-primary" type="submit">Ver deudas</button>
  </form>
</section>

<?php if (!$planData['plans']): ?>
  <section class="panel section-gap empty-state"><strong>No hay deudas activas en este espacio</strong><p>Registra una deuda o tarjeta desde la sección de obligaciones.</p><a class="button button-secondary" href="/liabilities">Ver deudas</a></section>
<?php else: ?>
  <form method="get" action="/debt-plan" class="debt-plan-form">
    <input type="hidden" name="space" value="<?= e($spaceKey) ?>">
    <?php foreach ($planData['plans'] as $debt): ?>
      <section class="panel section-gap debt-plan-card">
        <div class="account-card-heading">
          <div>
            <p class="eyebrow"><?= $debt['kind'] === 'credit_card' ? 'Tarjeta' : ($debt['kind'] === 'loan' ? 'Préstamo' : 'Otra deuda') ?> · <?= e($debt['scope'] === 'household' ? 'Familiar' : 'Personal') ?></p>
            <h2><?= e($debt['name']) ?></h2>
            <?php if ($debt['creditor']): ?><span class="account-detail"><?= e($debt['creditor']) ?></span><?php endif; ?>
          </div>
          <strong class="debt-plan-balance"><?= e(\App\Support\DecimalAmount::format((string) $debt['current_balance'])) ?></strong>
        </div>
        <div class="debt-plan-meta">
          <span>Tasa anual registrada: <?= $debt['annual_interest_rate'] === null ? 'No especificada (la simulación asume 0%)' : e($debt['annual_interest_rate']) . '%' ?></span>
          <?php if ($debt['next_due_date'] !== null): ?><span>Próximo día límite de pago registrado: <?= e($debt['next_due_date']) ?></span><?php endif; ?>
        </div>
        <label>Pago mensual hipotético (COP)
          <input type="number" name="payments[<?= (int) $debt['id'] ?>]" min="0.01" max="9999999999999.99" step="0.01" value="<?= e($debt['payment_input']) ?>" placeholder="Escribe un valor para simular">
        </label>
        <?php if ($debt['plan'] !== null): ?>
          <div class="debt-plan-result">
            <?php if ($debt['plan']['status'] === 'paid'): ?>
              <strong>Saldo registrado en cero</strong>
            <?php elseif ($debt['plan']['status'] === 'payoff'): ?>
              <strong>Tiempo estimado: <?= (int) $debt['plan']['months'] ?> meses</strong>
              <span>Intereses aproximados: <?= e(\App\Support\DecimalAmount::format($debt['plan']['total_interest'])) ?></span>
            <?php elseif ($debt['plan']['status'] === 'insufficient_payment'): ?>
              <strong>Ese pago no cubre el interés mensual estimado.</strong>
              <span>Con estos supuestos el saldo no disminuiría; prueba otro importe y confirma la tasa real con la entidad.</span>
            <?php elseif ($debt['plan']['status'] === 'interest_limit'): ?>
              <strong>El interés estimado supera el límite representable.</strong>
              <span>Prueba un pago mensual mayor; el resultado no sustituye el estado de cuenta.</span>
            <?php else: ?>
              <strong>La proyección supera 600 meses.</strong>
              <span>Prueba un pago mensual mayor.</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
    <div class="debt-plan-submit"><button class="button button-primary" type="submit">Calcular simulación</button><a class="button button-quiet" href="/debt-plan?<?= e($debtQuery) ?>">Limpiar pagos</a></div>
  </form>
<?php endif; ?>
