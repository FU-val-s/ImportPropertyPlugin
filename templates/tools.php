<?php if (isset($_GET['sent']) && $_GET['sent'] ): ?>
<div class="message">Se está procesando su solicitud, tenga en cuenta que la carga puede demorar hasta 5 minutos...</div>
<?php endif; ?>
<h1><?php echo esc_html__('Sincronizador de propiedades - Tokko', 'ct-admin'); ?></h1>
<p><?php echo esc_html__('Deberá ingresar el ID de propiedad para que el sistema lo procese, ya sea un alta o actualización de datos.', 'ct-admin'); ?></p>
<form method="POST" action="<?php echo esc_html(admin_url('admin-post.php')); ?>">
  <input type="hidden" name="action" value="fuvals_submit_property_update">
  <?php wp_nonce_field('fuvals_submit_property_update', 'fuvals_submit_property_update_nonce'); ?>
  <input type="hidden" name="redirectToUrl" value="/">
  <input type="text" id="property_id" name="property_id" class="form-control" value="" required>
  <input id="update-submit" type="submit" value="Actualizar"></input>
</form>
