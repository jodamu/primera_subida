<?php
$monthNames = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
?>

<section class="welcome">
  <p class="eyebrow">ANÁLISIS FINANCIERO</p>
  <h1>Tendencias de 12 meses</h1>
  <p class="muted">Compara ingresos, gastos y balance de los últimos 12 meses con los 12 meses anteriores. El mes actual puede estar incompleto.</p>
</section>

<section class="panel">
  <form method="get" action="/trends" class="report-filters">
    <label>Espacio
      <select name="household_id">
        <option value="personal" <?= $trends['household_id'] === null ? 'selected' : '' ?>>Personal</option>
        <?php foreach ($households as $household): ?>
          <option value="<?= (int) $household['id'] ?>" <?= $trends['household_id'] === (int) $household['id'] ? 'selected' : '' ?>><?= e($household['name']) ?> · Familiar</option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button button-primary" type="submit">Ver tendencias</button>
  </form>
</section>

<section class="report-summary section-gap">
  <?php foreach (['income' => 'Ingresos', 'expense' => 'Gastos', 'net' => 'Balance'] as $kind => $label): ?>
    <article class="report-card">
      <span><?= e($label) ?> · últimos 12 meses</span>
      <strong><?= e(\App\Support\DecimalAmount::format($trends['current_totals'][$kind])) ?></strong>
      <span>Período anterior: <?= e(\App\Support\DecimalAmount::format($trends['previous_totals'][$kind])) ?></span>
    </article>
  <?php endforeach; ?>
</section>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">MES A MES</p><h2>Actividad financiera</h2></div></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Mes</th><th>Ingresos</th><th>Gastos</th><th>Balance</th></tr></thead>
      <tbody>
        <?php foreach ($trends['periods'] as $period): ?>
          <tr>
            <th scope="row"><?= e($monthNames[$period['month'] - 1]) ?> <?= (int) $period['year'] ?></th>
            <td><?= e(\App\Support\DecimalAmount::format($period['income'])) ?></td>
            <td><?= e(\App\Support\DecimalAmount::format($period['expense'])) ?></td>
            <td><?= e(\App\Support\DecimalAmount::format($period['net'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
