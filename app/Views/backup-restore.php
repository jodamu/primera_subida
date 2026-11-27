<section class="welcome">
  <p class="eyebrow">Respaldo financiero</p>
  <h1>Restaurar datos</h1>
  <p class="muted">La restauración es aditiva: nunca borra ni reemplaza registros. Primero carga el respaldo y revisa sus datos y el destino de cada hogar.</p>
</section>

<section class="panel">
  <div class="panel-heading"><div><p class="eyebrow">Paso 1</p><h2>Cargar archivo JSON</h2></div></div>
  <p class="field-help">Se aceptan respaldos Mi Finanzas formato 1 o 2, hasta 8 MB y 5.000 registros. No incluye usuarios, contraseñas ni secretos.</p>
  <form method="post" action="/backups/restore/preview" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <label>Archivo de respaldo (.json)
      <input type="file" name="backup_file" accept=".json,application/json" required>
    </label>
    <div class="form-actions"><button class="button button-primary" type="submit">Ver vista previa</button></div>
  </form>
</section>

<?php if ($preview): ?>
  <section class="panel section-gap">
    <div class="panel-heading">
      <div><p class="eyebrow">Paso 2 · respaldo #<?= (int) $preview['batch']['id'] ?></p><h2>Vista previa aditiva</h2></div>
      <span class="status-pill"><?= $preview['batch']['status'] === 'preview' ? 'Pendiente de confirmar' : ($preview['batch']['status'] === 'restored' ? 'Restaurado' : 'Cancelado') ?></span>
    </div>
    <p class="field-help">
      <?= (int) $preview['batch']['row_count'] ?> registros detectados. Los datos se agregarán como nuevos cuando falten; si ya existen, se conservarán sin cambios. Si hay una referencia incompatible, la transacción completa se cancelará.
    </p>
    <?php if ($preview['batch']['status'] === 'preview'): ?>
      <h3>Contenido del respaldo</h3>
      <ul class="plain-list">
        <?php foreach ($preview['counts'] as $table => $count): ?>
          <?php if ($count > 0): ?><li><?= e(str_replace('_', ' ', $table)) ?>: <?= (int) $count ?></li><?php endif; ?>
        <?php endforeach; ?>
      </ul>
      <?php if ($preview['source_spaces']): ?>
        <form method="post" action="/backups/restore/confirm" class="form-grid section-gap">
          <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
          <input type="hidden" name="batch_id" value="<?= (int) $preview['batch']['id'] ?>">
          <h3 class="form-grid-full">Asigna cada hogar del respaldo</h3>
          <?php foreach ($preview['source_spaces'] as $sourceSpace): ?>
            <label><?= e($sourceSpace['name']) ?>
              <select name="household_map[<?= (int) $sourceSpace['source_id'] ?>]" required>
                <option value="">Selecciona un destino</option>
                <option value="personal">Espacio personal</option>
                <?php foreach ($manageableHouseholds as $household): ?>
                  <option value="<?= (int) $household['id'] ?>"><?= e($household['name']) ?> · Hogar</option>
                <?php endforeach; ?>
                <option value="skip">Omitir este hogar</option>
              </select>
            </label>
          <?php endforeach; ?>
          <p class="field-help form-grid-full">Solo puedes integrar datos en tu espacio personal o en hogares donde eres propietario o administrador.</p>
          <div class="form-actions form-grid-full">
            <button class="button button-primary" type="submit">Restaurar sin reemplazar</button>
          </div>
        </form>
      <?php else: ?>
        <form method="post" action="/backups/restore/confirm">
          <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
          <input type="hidden" name="batch_id" value="<?= (int) $preview['batch']['id'] ?>">
          <button class="button button-primary" type="submit">Restaurar datos personales</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Este respaldo ya no se puede volver a aplicar.</p>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">Historial</p><h2>Respaldos revisados</h2></div></div>
  <?php if (!$recentBatches): ?>
    <p class="empty-state">Todavía no has cargado respaldos.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Respaldo</th><th>Filas</th><th>Formato</th><th>Estado</th><th>Creado</th></tr></thead>
        <tbody>
          <?php foreach ($recentBatches as $batch): ?>
            <tr>
              <td><a href="/backups/restore?batch=<?= (int) $batch['id'] ?>">#<?= (int) $batch['id'] ?></a></td>
              <td><?= (int) $batch['row_count'] ?></td>
              <td><?= (int) $batch['source_version'] ?></td>
              <td><?= $batch['status'] === 'preview' ? 'Pendiente' : ($batch['status'] === 'restored' ? 'Restaurado' : 'Cancelado') ?></td>
              <td><?= e($batch['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
