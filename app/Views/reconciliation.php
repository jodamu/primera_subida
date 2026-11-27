<section class="welcome">
  <p class="eyebrow">Revisión manual</p>
  <h1>Conciliar extracto bancario</h1>
  <p class="muted">Importa un CSV para detectar coincidencias exactas por cuenta, fecha, dirección e importe. Cada coincidencia requiere confirmación; esta herramienta nunca crea, anula ni modifica movimientos.</p>
</section>

<section class="panel">
  <div class="panel-heading"><div><p class="eyebrow">Paso 1</p><h2>Importa el extracto</h2></div></div>
  <?php if (!$accounts): ?>
    <div class="empty-state"><strong>No hay cuentas activas.</strong><p>Agrega una cuenta antes de conciliar un extracto.</p><a class="button button-secondary" href="/accounts">Ir a cuentas</a></div>
  <?php else: ?>
    <p class="field-help">CSV de hasta 2 MB y 1.000 filas. El valor puede llevar signo (+ ingreso, - gasto); si no, asigna una columna de dirección con valores como Crédito/Débito o Ingreso/Gasto.</p>
    <form method="post" action="/reconciliation/preview" enctype="multipart/form-data" class="form-grid import-form">
      <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
      <label>Cuenta
        <select name="account_id" required>
          <?php foreach ($accounts as $account): ?>
            <option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?> · <?= e($account['scope'] === 'household' ? 'Familiar' : 'Personal') ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Archivo CSV (máximo 2 MB)
        <input type="file" name="csv_file" accept=".csv,text/csv" required>
      </label>
      <label>Separador
        <select name="delimiter"><option value=",">Coma (,)</option><option value=";">Punto y coma (;)</option><option value="&#9;">Tabulador</option></select>
      </label>
      <label>Encabezado de fecha<input type="text" name="date_column" maxlength="80" required placeholder="Fecha"></label>
      <label>Encabezado de importe<input type="text" name="amount_column" maxlength="80" required placeholder="Valor"></label>
      <label>Encabezado de descripción<input type="text" name="description_column" maxlength="80" required placeholder="Descripción"></label>
      <label>Encabezado de dirección (opcional)<input type="text" name="direction_column" maxlength="80" placeholder="Tipo / Movimiento"></label>
      <div class="form-actions form-grid-full"><button class="button button-primary" type="submit">Revisar extracto</button></div>
    </form>
  <?php endif; ?>
</section>

<?php if ($preview): ?>
  <section class="panel section-gap">
    <div class="panel-heading">
      <div><p class="eyebrow"><?= e($preview['batch']['account_name']) ?></p><h2>Extracto #<?= (int) $preview['batch']['id'] ?></h2></div>
      <span class="status-pill"><?= $preview['batch']['status'] === 'preview' ? 'Pendiente de revisar' : 'Conciliado' ?></span>
    </div>
    <p class="field-help"><?= count($preview['rows']) ?> filas. Las coincidencias no cambian los importes ni el estado de tus cuentas.</p>
    <form method="post" action="/reconciliation/confirm">
      <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
      <input type="hidden" name="batch_id" value="<?= (int) $preview['batch']['id'] ?>">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Fila</th><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Importe</th><th>Sugerencia / decisión</th></tr></thead>
          <tbody>
            <?php foreach ($preview['rows'] as $row): ?>
              <tr>
                <td><?= (int) $row['line_number'] ?></td>
                <td><?= e($row['transaction_date']) ?></td>
                <td><?= $row['operation_type'] === 'income' ? 'Ingreso' : 'Gasto' ?></td>
                <td><?= e($row['description']) ?></td>
                <td><?= e(\App\Support\DecimalAmount::format((string) $row['amount'])) ?></td>
                <td>
                  <?php if ($row['candidate_operation_id'] !== null): ?>
                    <span>Coincidencia exacta sugerida · movimiento #<?= (int) $row['candidate_operation_id'] ?></span>
                  <?php else: ?>
                    <span>Sin coincidencia exacta única</span>
                  <?php endif; ?>
                  <?php if ($preview['batch']['status'] === 'preview'): ?>
                    <label class="sr-only" for="decision-<?= (int) $row['id'] ?>">Decisión para la fila <?= (int) $row['line_number'] ?></label>
                    <select id="decision-<?= (int) $row['id'] ?>" name="decisions[<?= (int) $row['id'] ?>]" required>
                      <option value="">Elige una decisión</option>
                      <?php if ($row['candidate_operation_id'] !== null): ?><option value="matched">Confirmar coincidencia</option><?php endif; ?>
                      <option value="unmatched">Marcar sin coincidencia</option>
                      <option value="ignored">Ignorar fila</option>
                    </select>
                  <?php else: ?>
                    <strong><?= ['matched' => 'Coincidencia confirmada', 'unmatched' => 'Sin coincidencia', 'ignored' => 'Ignorada'][$row['status']] ?? 'Pendiente' ?></strong>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($preview['batch']['status'] === 'preview'): ?>
        <div class="form-actions section-gap"><button class="button button-primary" type="submit">Confirmar decisiones</button></div>
      <?php endif; ?>
    </form>
  </section>
<?php endif; ?>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">Historial</p><h2>Conciliaciones recientes</h2></div></div>
  <?php if (!$recentBatches): ?>
    <p class="empty-state">Todavía no hay extractos revisados.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Extracto</th><th>Cuenta</th><th>Filas</th><th>Estado</th><th>Creado</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($recentBatches as $batch): ?>
            <tr>
              <td>#<?= (int) $batch['id'] ?></td><td><?= e($batch['account_name']) ?></td>
              <td><?= (int) $batch['row_count'] ?></td>
              <td><?= $batch['status'] === 'preview' ? 'Pendiente' : e('Conciliado') ?></td>
              <td><?= e($batch['created_at']) ?></td>
              <td><a href="/reconciliation?batch=<?= (int) $batch['id'] ?>">Ver</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
