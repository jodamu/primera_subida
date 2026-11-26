<?php
$monthNames = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$reportQuery = http_build_query([
    'year' => $report['year'],
    'month' => $report['month'],
    'household_id' => $report['household_id'] ?? 'personal',
]);
$spaceName = 'Personal';
if ($report['household_id'] !== null) {
    foreach ($households as $household) {
        if ((int) $household['id'] === $report['household_id']) {
            $spaceName = $household['name'];
            break;
        }
    }
}
?>

<section class="welcome">
  <p class="eyebrow">Fase 5 · Informes</p>
  <h1>Resumen financiero</h1>
  <p class="muted">Las compras con tarjeta e intereses se cuentan como gastos; los pagos al capital y desembolsos no se cuentan dos veces.</p>
</section>

<?php if (!empty($printOnly)): ?>
  <section class="print-heading">
    <p><?= e($monthNames[$report['month'] - 1]) ?> <?= (int) $report['year'] ?> · <?= e($spaceName) ?></p>
    <div class="print-controls">
      <a class="button button-quiet" href="/reports?<?= e($reportQuery) ?>">Volver al informe</a>
      <button class="button button-primary" type="button" data-print-report>Imprimir / Guardar como PDF</button>
    </div>
  </section>
<?php else: ?>
<section class="panel">
  <form method="get" action="/reports" class="report-filters">
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
    <button class="button button-primary" type="submit">Ver informe</button>
  </form>
  <div class="report-export-actions">
    <a class="button button-secondary" href="/reports/export.csv?<?= e($reportQuery) ?>">Descargar CSV</a>
    <a class="button button-secondary" href="/reports/print?<?= e($reportQuery) ?>">Imprimir / Guardar como PDF</a>
  </div>
</section>
<?php endif; ?>

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
  <div class="panel-heading"><div><p class="eyebrow">DESGLOSE</p><h2>Totales por categoría</h2></div></div>
  <?php if (!$report['categories']): ?>
    <div class="empty-state"><strong>Sin movimientos en este período</strong><p>Al registrar movimientos, aparecerán aquí.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Tipo</th><th>Categoría</th><th>Total</th></tr></thead>
        <tbody>
          <?php foreach ($report['categories'] as $category): ?>
            <tr>
              <td><?= $category['kind'] === 'income' ? 'Ingreso' : 'Gasto' ?></td>
              <td><?= e($category['name']) ?></td>
              <td><?= e(\App\Support\DecimalAmount::format($category['total'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
