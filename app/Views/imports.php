<section class="welcome">
  <p class="eyebrow">Importación segura</p>
  <h1>Importar movimientos CSV</h1>
  <p class="muted">Carga hasta 1.000 ingresos o gastos en una cuenta. Revisa las filas y los duplicados antes de confirmar; la importación no admite transferencias ni integra automáticamente con bancos.</p>
</section>

<section class="panel">
  <div class="panel-heading">
    <div><p class="eyebrow">Paso 1</p><h2>Selecciona el archivo y asigna columnas</h2></div>
  </div>
  <?php if (!$activeAccounts): ?>
    <div class="empty-state"><strong>No hay cuentas activas.</strong><p>Registra o activa una cuenta antes de importar movimientos.</p><a class="button button-secondary" href="/accounts">Ir a cuentas</a></div>
  <?php else: ?>
    <p class="field-help">El CSV debe tener encabezados en la primera fila. Las columnas de fecha, valor y descripción son obligatorias. La fecha admite AAAA-MM-DD, DD/MM/AAAA o DD-MM-AAAA; los importes admiten coma o punto decimal.</p>
    <form method="post" action="/imports/preview" enctype="multipart/form-data" class="form-grid import-form">
      <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
      <label>Cuenta
        <select name="account_id" required>
          <?php foreach ($activeAccounts as $account): ?>
            <option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?> · <?= e($account['scope'] === 'household' ? 'Familiar' : 'Personal') ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Archivo CSV (máximo 2 MB)
        <input type="file" name="csv_file" accept=".csv,text/csv" required>
      </label>
      <label>Separador
        <select name="delimiter">
          <option value=",">Coma (,)</option>
          <option value=";">Punto y coma (;)</option>
          <option value="&#9;">Tabulador</option>
        </select>
      </label>
      <label>Encabezado de fecha
        <input type="text" name="date_column" maxlength="80" required placeholder="Fecha">
      </label>
      <label>Encabezado de valor
        <input type="text" name="amount_column" maxlength="80" required placeholder="Valor">
      </label>
      <label>Encabezado de descripción
        <input type="text" name="description_column" maxlength="80" required placeholder="Descripción">
      </label>
      <label>Encabezado de categoría (opcional)
        <input type="text" name="category_column" maxlength="80" placeholder="Categoría">
        <span class="field-help">Si la columna no está en el CSV, elige una categoría predeterminada.</span>
      </label>
      <label>Categoría predeterminada
        <select name="default_category_id">
          <option value="">Usar la columna de categoría</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= (int) $category['id'] ?>"><?= e($category['kind'] === 'income' ? 'Ingreso · ' : 'Gasto · ') ?><?= e($category['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="form-grid-full form-actions">
        <p class="field-help">Las categorías del archivo deben coincidir con las categorías activas de Mi Finanzas.</p>
        <button class="button button-primary" type="submit">Crear vista previa</button>
      </div>
    </form>
  <?php endif; ?>
</section>

<?php if ($preview): ?>
  <section class="panel section-gap">
    <div class="panel-heading">
      <div>
        <p class="eyebrow">Paso 2 · <?= e($preview['batch']['account_name']) ?></p>
        <h2>Vista previa de importación #<?= (int) $preview['batch']['id'] ?></h2>
      </div>
      <span class="status-pill <?= $preview['batch']['status'] !== 'preview' ? 'status-archived' : '' ?>">
        <?= $preview['batch']['status'] === 'preview' ? 'Pendiente de confirmar' : ($preview['batch']['status'] === 'confirmed' ? 'Confirmada' : 'Cancelada') ?>
      </span>
    </div>
    <?php $duplicateCount = count(array_filter($preview['rows'], static fn (array $row): bool => (int) $row['is_duplicate'] === 1)); ?>
    <p class="field-help"><?= count($preview['rows']) ?> filas; <?= $duplicateCount ?> duplicadas que se omitirán.</p>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Fila</th><th>Fecha</th><th>Tipo</th><th>Categoría</th><th>Descripción</th><th>Valor</th><th>Estado</th></tr></thead>
        <tbody>
          <?php foreach ($preview['rows'] as $row): ?>
            <tr>
              <td><?= (int) $row['line_number'] ?></td>
              <td><?= e($row['effective_date']) ?></td>
              <td><?= $row['operation_type'] === 'income' ? 'Ingreso' : 'Gasto' ?></td>
              <td><?= e($categoryNames[(int) $row['category_id']] ?? 'Categoría') ?></td>
              <td><?= e($row['description']) ?></td>
              <td><?= e(\App\Support\DecimalAmount::format((string) $row['amount'])) ?></td>
              <td><?= (int) $row['is_duplicate'] === 1 ? 'Duplicada · se omitirá' : 'Lista' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($preview['batch']['status'] === 'preview'): ?>
      <div class="import-actions">
        <form method="post" action="/imports/confirm" class="import-confirm">
          <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
          <input type="hidden" name="batch_id" value="<?= (int) $preview['batch']['id'] ?>">
          <p class="field-help">Al confirmar, las filas válidas quedarán registradas como movimientos y actualizarán los saldos.</p>
          <button class="button button-primary" type="submit">Confirmar importación</button>
        </form>
        <form method="post" action="/imports/cancel">
          <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
          <input type="hidden" name="batch_id" value="<?= (int) $preview['batch']['id'] ?>">
          <button class="button button-quiet" type="submit">Cancelar vista previa</button>
        </form>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">Historial</p><h2>Importaciones recientes</h2></div></div>
  <?php if (!$recentImports): ?>
    <p class="empty-state">Todavía no hay importaciones.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Importación</th><th>Cuenta</th><th>Filas</th><th>Estado</th><th>Creada</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($recentImports as $batch): ?>
            <tr>
              <td>#<?= (int) $batch['id'] ?></td>
              <td><?= e($batch['account_name']) ?></td>
              <td><?= (int) $batch['row_count'] ?></td>
              <td><?= $batch['status'] === 'confirmed' ? 'Confirmada' : ($batch['status'] === 'cancelled' ? 'Cancelada' : 'Pendiente') ?></td>
              <td><?= e($batch['created_at']) ?></td>
              <td><a href="/imports?batch=<?= (int) $batch['id'] ?>">Ver</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
