<section class="panel">
  <div class="panel-heading"><div><p class="eyebrow">MOVIMIENTO #<?= (int) $operationId ?></p><h2>Adjuntos</h2></div><a href="/transactions">Volver a movimientos</a></div>
  <p class="muted">PDF, JPG, PNG o WebP de hasta 5 MB, máximo 5 por movimiento. Solo tú y los miembros del espacio pueden descargarlos.</p>
  <form method="post" action="/attachments" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <input type="hidden" name="operation_id" value="<?= (int) $operationId ?>">
    <label class="form-grid-full">Archivo
      <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" required>
    </label>
    <button class="button button-primary" type="submit">Subir adjunto</button>
  </form>
</section>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">ARCHIVOS</p><h2>Adjuntos guardados</h2></div></div>
  <?php if (!$attachments): ?>
    <div class="empty-state"><strong>Sin adjuntos</strong><p>Sube una factura o comprobante para este movimiento.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Archivo</th><th>Tipo</th><th>Tamaño</th><th>Subido</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($attachments as $attachment): ?>
            <tr>
              <td><?= e($attachment['original_name']) ?></td>
              <td><?= e($attachment['mime_type']) ?></td>
              <td><?= e(number_format(((int) $attachment['size_bytes']) / 1024, 1, ',', '.')) ?> KB</td>
              <td><?= e($attachment['created_at']) ?></td>
              <td>
                <a class="button button-quiet" href="/attachments/download?id=<?= (int) $attachment['id'] ?>">Descargar</a>
                <form method="post" action="/attachments/delete" data-confirm="¿Eliminar este adjunto?">
                  <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
                  <input type="hidden" name="id" value="<?= (int) $attachment['id'] ?>">
                  <button class="button button-quiet" type="submit">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
