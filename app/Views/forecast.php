<section class="welcome">
  <p class="eyebrow">Fase 6 · Estimación</p>
  <h1>Proyección de flujo de caja</h1>
  <p class="muted">Estimación informativa a partir de los saldos actuales y movimientos recurrentes activos. No registra movimientos ni cambia los saldos; las recurrencias futuras requieren confirmación.</p>
</section>

<section class="panel">
  <form method="get" action="/forecast" class="space-picker">
    <label>Espacio financiero
      <select name="space">
        <?php foreach ($spaces as $space): ?>
          <option value="<?= e($space['key']) ?>" <?= $projection['space']['key'] === $space['key'] ? 'selected' : '' ?>><?= e($space['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button button-primary" type="submit">Actualizar proyección</button>
  </form>
</section>

<section class="report-summary">
  <article class="report-card"><span>Saldo actual conjunto · <?= (int) $projection['account_count'] ?> cuentas</span><strong><?= e(\App\Support\DecimalAmount::format($projection['starting_balance'])) ?></strong></article>
  <?php foreach ($projection['checkpoints'] as $checkpoint): ?>
    <article class="report-card"><span>Estimado en <?= (int) $checkpoint['days'] ?> días · <?= e($checkpoint['date']) ?></span><strong><?= e(\App\Support\DecimalAmount::format($checkpoint['projected_balance'])) ?></strong></article>
  <?php endforeach; ?>
</section>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">HASTA 90 DÍAS</p><h2>Movimientos considerados</h2></div></div>
  <?php if (!$projection['events']): ?>
    <div class="empty-state"><strong>No hay recurrencias activas en este período</strong><p>El saldo estimado permanece igual al saldo actual.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Fecha estimada</th><th>Tipo</th><th>Descripción</th><th>Importe</th></tr></thead>
        <tbody>
          <?php foreach ($projection['events'] as $event): ?>
            <tr>
              <td><?= e($event['date']) ?></td>
              <td><?= $event['type'] === 'income' ? 'Ingreso' : 'Gasto' ?></td>
              <td><?= e($event['description']) ?></td>
              <td><?= e(\App\Support\DecimalAmount::format($event['amount'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($projection['events']) >= 100): ?><p class="field-help">Se muestran las primeras 100 recurrencias estimadas; los totales incluyen las fechas proyectadas para los 90 días.</p><?php endif; ?>
  <?php endif; ?>
</section>
