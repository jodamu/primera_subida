<section class="welcome">
  <p class="eyebrow">Fase 3 · Obligaciones</p>
  <h1>Deudas y tarjetas</h1>
  <p class="muted">Las compras con tarjeta se registran como gasto al realizarlas. El pago reduce la deuda y el saldo bancario, sin duplicar el gasto.</p>
</section>

<section class="panel">
  <div class="panel-heading">
    <div><p class="eyebrow">NUEVA OBLIGACIÓN</p><h2>Agregar deuda o tarjeta</h2></div>
  </div>
  <form method="post" action="/liabilities" class="form-grid liability-form">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <label>Tipo
      <select name="kind" data-liability-kind required>
        <option value="loan">Préstamo</option>
        <option value="credit_card">Tarjeta de crédito</option>
        <option value="other">Otra deuda</option>
      </select>
    </label>
    <label>Nombre
      <input type="text" name="name" maxlength="100" required placeholder="Ej. Tarjeta principal">
    </label>
    <label>Entidad acreedora
      <input type="text" name="creditor" maxlength="120" placeholder="Banco o entidad">
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
    <label>Saldo actual inicial (COP)
      <input type="number" name="initial_balance" min="0" max="9999999999999.99" step="0.01" value="0.00" required>
    </label>
    <label>Fecha de ese saldo
      <input type="date" name="initial_balance_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required>
    </label>
    <label data-credit-card-field hidden>Cupo total (COP)
      <input type="number" name="credit_limit" min="0.01" max="9999999999999.99" step="0.01" data-credit-card-required disabled>
    </label>
    <label data-credit-card-field hidden>Día de corte
      <input type="number" name="statement_close_day" min="1" max="28" step="1" data-credit-card-required disabled>
    </label>
    <label data-credit-card-field hidden>Día límite de pago
      <input type="number" name="payment_due_day" min="1" max="28" step="1" data-credit-card-required disabled>
    </label>
    <label>Tasa anual (opcional, %)
      <input type="number" name="annual_interest_rate" min="0" max="100" step="0.001" placeholder="Ej. 24.500">
    </label>
    <div class="form-actions form-grid-full">
      <p class="field-help">La tasa se guarda como referencia; no se calcularán intereses automáticamente.</p>
      <button class="button button-primary" type="submit">Guardar deuda</button>
    </div>
  </form>
</section>

<section class="panel section-gap">
  <div class="panel-heading">
    <div><p class="eyebrow">SALDOS PENDIENTES</p><h2>Tus obligaciones</h2></div>
  </div>
  <?php if (!$liabilities): ?>
    <div class="empty-state"><strong>Aún no registras deudas</strong><p>Agrega una tarjeta o préstamo para llevar su saldo.</p></div>
  <?php else: ?>
    <div class="account-grid">
      <?php foreach ($liabilities as $liability): ?>
        <article class="account-card">
          <div class="account-card-heading">
            <div>
              <span class="eyebrow">
                <?= $liability['kind'] === 'credit_card' ? 'Tarjeta de crédito' : ($liability['kind'] === 'loan' ? 'Préstamo' : 'Otra deuda') ?>
                · <?= $liability['scope'] === 'household' ? 'Familiar' : 'Personal' ?>
              </span>
              <h3><?= e($liability['name']) ?></h3>
              <?php if ($liability['creditor']): ?><p class="account-detail"><?= e($liability['creditor']) ?></p><?php endif; ?>
            </div>
            <span class="status-pill status-<?= e($liability['status']) ?>"><?= $liability['status'] === 'active' ? 'Activa' : 'Archivada' ?></span>
          </div>
          <p class="account-balance"><?= e(\App\Support\DecimalAmount::format((string) $liability['current_balance'])) ?> <span>COP</span></p>
          <?php if ($liability['kind'] === 'credit_card'): ?>
            <p class="account-detail">Cupo: <?= e(\App\Support\DecimalAmount::format((string) $liability['credit_limit'])) ?></p>
            <p class="account-detail">Disponible: <?= e(\App\Support\DecimalAmount::format((string) $liability['available_credit'])) ?></p>
            <p class="account-detail">Corte día <?= (int) $liability['statement_close_day'] ?> · Pago hasta el día <?= (int) $liability['payment_due_day'] ?></p>
          <?php endif; ?>
          <?php if ($liability['annual_interest_rate'] !== null): ?>
            <p class="account-detail">Tasa anual de referencia: <?= e($liability['annual_interest_rate']) ?>%</p>
          <?php endif; ?>
          <p class="account-detail">Saldo inicial: <?= e(\App\Support\DecimalAmount::format((string) $liability['initial_balance'])) ?> · <?= e($liability['initial_balance_date']) ?></p>
          <?php if ($liability['planned_monthly_payment'] !== null): ?>
            <?php
            $targetCents = (int) str_replace('.', '', $liability['planned_monthly_payment']);
            $paidCents = (int) str_replace('.', '', $liability['current_month_paid']);
            $progressPercent = $targetCents > 0
                ? max(0, min(100, (int) floor($paidCents * 100 / $targetCents)))
                : 0;
            ?>
            <p class="account-detail">
              Pagado este mes: <?= e(\App\Support\DecimalAmount::format((string) $liability['current_month_paid'])) ?>
              de <?= e(\App\Support\DecimalAmount::format((string) $liability['planned_monthly_payment'])) ?> objetivo
            </p>
            <progress max="100" value="<?= $progressPercent ?>" aria-label="Avance de cuota objetivo"><?= $progressPercent ?>%</progress>
          <?php endif; ?>
          <?php if ($liability['status'] === 'active' && $liability['can_manage']): ?>
            <form method="post" action="/liabilities/payment-target" class="form-grid">
              <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
              <input type="hidden" name="liability_id" value="<?= (int) $liability['id'] ?>">
              <label>Cuota objetivo mensual (COP)
                <input
                  type="number"
                  name="planned_monthly_payment"
                  min="0.01"
                  max="9999999999999.99"
                  step="0.01"
                  value="<?= $liability['planned_monthly_payment'] === null ? '' : e($liability['planned_monthly_payment']) ?>"
                  placeholder="Opcional"
                >
              </label>
              <div class="form-actions"><button class="button button-secondary" type="submit">Guardar cuota objetivo</button></div>
              <p class="field-help">Solo sirve para seguimiento. No programa ni registra pagos.</p>
            </form>
          <?php endif; ?>
          <?php if ($liability['status'] === 'active' && $liability['can_manage']): ?>
            <form method="post" action="/liabilities/archive" data-confirm="¿Archivar esta deuda? Solo se permite si su saldo está en cero.">
              <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
              <input type="hidden" name="liability_id" value="<?= (int) $liability['id'] ?>">
              <button class="button button-quiet" type="submit">Archivar</button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($activeLiabilities): ?>
  <section class="panel section-gap">
    <div class="panel-heading"><div><p class="eyebrow">COMPRAS, INTERESES Y CARGOS</p><h2>Registrar cargo</h2></div></div>
    <p class="muted">El cargo aumenta la deuda y se contabiliza como gasto en su categoría.</p>
    <form method="post" action="/liabilities/charge" class="form-grid">
      <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
      <input type="hidden" name="idempotency_key" value="<?= e($chargeKey) ?>">
      <label>Deuda o tarjeta
        <select name="liability_id" required>
          <option value="">Selecciona una obligación</option>
          <?php foreach ($activeLiabilities as $liability): ?>
            <option value="<?= (int) $liability['id'] ?>"><?= e($liability['name']) ?> · <?= $liability['kind'] === 'credit_card' ? 'Tarjeta' : ($liability['kind'] === 'loan' ? 'Préstamo' : 'Otra deuda') ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Categoría del gasto
        <select name="category_id" required>
          <option value="">Selecciona una categoría</option>
          <?php foreach ($categories as $category): ?>
            <?php if ($category['kind'] === 'expense'): ?>
              <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Importe (COP)
        <input type="number" name="amount" min="0.01" max="9999999999999.99" step="0.01" required>
      </label>
      <label>Fecha
        <input type="date" name="effective_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required>
      </label>
      <label class="form-grid-full">Descripción
        <input type="text" name="description" maxlength="255" required placeholder="Ej. Compra o interés cargado">
      </label>
      <div class="form-actions form-grid-full"><button class="button button-primary" type="submit">Registrar cargo</button></div>
    </form>
  </section>

  <?php if ($activeAccounts): ?>
    <section class="panel section-gap">
      <div class="panel-heading"><div><p class="eyebrow">PAGOS Y DESEMBOLSOS</p><h2>Registrar pago o desembolso</h2></div></div>
      <form method="post" action="/liabilities/payment" class="form-grid">
        <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
        <input type="hidden" name="idempotency_key" value="<?= e($paymentKey) ?>">
        <label>Deuda o tarjeta
          <select name="liability_id" data-payment-liability required>
            <option value="">Selecciona una obligación</option>
            <?php foreach ($activeLiabilities as $liability): ?>
              <option value="<?= (int) $liability['id'] ?>" data-kind="<?= e($liability['kind']) ?>"><?= e($liability['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Cuenta desde la que pagas
          <select name="account_id" required>
            <option value="">Selecciona una cuenta</option>
            <?php foreach ($activeAccounts as $account): ?>
              <option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?> · <?= $account['scope'] === 'household' ? 'Familiar' : 'Personal' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Abono a capital (COP)
          <input type="number" name="principal_amount" min="0" max="9999999999999.99" step="0.01" value="0.00" required>
        </label>
        <label data-loan-interest-field>Interés pagado (COP)
          <input type="number" name="interest_amount" min="0" max="9999999999999.99" step="0.01" value="0.00" required>
        </label>
        <label>Fecha
          <input type="date" name="effective_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required>
        </label>
        <label class="form-grid-full">Descripción
          <input type="text" name="description" maxlength="255" required placeholder="Ej. Pago mensual">
        </label>
        <div class="form-actions form-grid-full"><button class="button button-secondary" type="submit">Registrar pago</button></div>
      </form>

      <?php $disbursableLiabilities = array_values(array_filter($activeLiabilities, static fn (array $item): bool => $item['kind'] !== 'credit_card')); ?>
      <?php if ($disbursableLiabilities): ?>
        <div class="subsection-divider"></div>
        <h3>Registrar desembolso de deuda</h3>
        <p class="muted">El desembolso aumenta el saldo de la cuenta y la deuda, pero no se cuenta como ingreso.</p>
        <form method="post" action="/liabilities/disbursement" class="form-grid">
          <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
          <input type="hidden" name="idempotency_key" value="<?= e($disbursementKey) ?>">
          <label>Deuda
            <select name="liability_id" required>
              <?php foreach ($disbursableLiabilities as $liability): ?>
                <option value="<?= (int) $liability['id'] ?>"><?= e($liability['name']) ?> · <?= $liability['kind'] === 'loan' ? 'Préstamo' : 'Otra deuda' ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Cuenta de destino
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
          <label>Fecha
            <input type="date" name="effective_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required>
          </label>
          <label class="form-grid-full">Descripción
            <input type="text" name="description" maxlength="255" required placeholder="Ej. Desembolso inicial">
          </label>
          <div class="form-actions form-grid-full"><button class="button button-quiet" type="submit">Registrar desembolso</button></div>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>
<?php endif; ?>
