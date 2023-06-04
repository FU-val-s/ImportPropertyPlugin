<?php
/**
 * Plugin Name: Tokko Import Plugin
 * Plugin URI:
 * Description: Este plugin importa datos llamando a la API de Tokko y genera las propiedades.
 * Version: 1.5.1
 * Author: FUvalS.uy
 * Author URI: https://fuvals.uy
 * Requires at least:
 * Tested up to:
 *
 *Text Domain: tokkoImportPlugin
 * Domain path: /languages/
 */


defined('ABSPATH') or die('¡sin trampas!');

ini_set("error_reporting", E_ALL & ~E_DEPRECATED);
//Activate plugin
register_activation_hook(__FILE__, 'houzezImp_activate');
//Add action to the created cron job hook - Crear una funcion para esto.
add_action('cron_hook_tokko', 'import_houzez_properties');
//Plugin activation hook
add_action('cron_fuvals_operations', 'fuvals_run_operation');
//Update function
add_action('cron_fuvals_houzez_update', 'fuvals_get_last_properties');
add_action('cron_fuvals_houzez_conciliate', 'fuvals_conciliate_properties');
//Get classes
require plugin_dir_path(__FILE__) . '/class-fuvals-wp-admin-form.php';
require plugin_dir_path(__FILE__) . '/class-fuvals-houzezImport.php';

//Call wp-admin-form class
function run_fuvals_wp_admin_form()
{
  $plugin = new Fuvals_Admin_Form();
  $plugin->init();
}
run_fuvals_wp_admin_form();

function houzezImp_activate()
{
  //add_option( 'Activated_Plugin_Houzez', true );
  // register the setting
  if (get_option('houzez_import_last_page', false)) {
    register_setting('houzez_import_last_page');
  }
  if (get_option('houzez_import_page_complete', false)) {
    register_setting( 'houzez_import_page_complete' );
  }
  update_option('houzez_import_last_page', 0);
  update_option('houzez_import_page_complete', true);
  error_log("\nUPDATE OPTION: " . get_option('houzez_import_last_page', 0));
  //Create and Scheduled cron job in wordpress
  if (!wp_next_scheduled('cron_hook_tokko')) {
    wp_schedule_event(time(), 'daily', 'cron_hook_tokko');
  }
  global $wpdb;
  //LOAD FIELDS BUILDER ONLY ONCE
  $table_houzez_fields_builds = $wpdb->prefix . "houzez_fields_builder";
  $fieldsQ = $wpdb->get_results("SELECT * FROM $table_houzez_fields_builds WHERE field_id = 'estado'");
  if (empty($fieldsQ)) {
    createCustomFields();
  }
  $fieldsQ = $wpdb->get_results("SELECT * FROM $table_houzez_fields_builds WHERE field_id = 'alq-all-jan-ref'");
  if (empty($fieldsQ)) {
    createNewCustomFields();
  }
  $fieldsQ = $wpdb->get_results("SELECT * FROM $table_houzez_fields_builds WHERE field_id = 'superficie'");
  if(empty($fieldsQ)) {
    createAux();
  }
}
;

//--MUST DO - CREATE A DESACTIVATION FUNCTION
//function houzezImp_desactivate () {}

//
function fuvals_conciliate_properties()
{
  error_log("------ START CONCILIATION ------\n");
  $props = get_posts([
    'post_type' => 'property',
    'post_status' => 'publish',
    'numberposts' => -1
  ]);
  $houzezImport = new Fuvals_houzezImport_Tokko($agent, $conciliateImages);
  foreach ($props as $property) {
    $delete = false;
    $ficha = get_post_meta($property->ID, 'fave_property_id', true);
    //error_log("CONCILIATE CHECKING: ".$ficha);
    $apiData = $houzezImport->property_details($ficha);
    if (isset($apiData['resultado']['ficha'][0])) {
      $prop = $apiData['resultado']['ficha'][0];
      if (!empty($prop['in_esi']) && $prop['in_esi'] != 'N') {
        error_log("CONCILIATION NOT FOR WEB: $ficha");
        $delete = true;
      }
    } else {
      error_log("CONCILIATION NO POST DATA: $ficha");
      $delete = true;
    }
    if ($delete) {
      error_log("DELETING POST: $ficha");
      wp_delete_post($property->ID, true);
    }
  }
  error_log("------ END CONCILIATION ------\n");
}
//
function fuvals_get_last_properties($minval = false)
{
  set_time_limit(0);
  ini_set('max_execution_time', '-1');
  $last_update = get_option('houzez_import_last_date', '2022-12-14 06:11:00');
  error_log("------ min " . print_r($minval, true) . " - START UPDATE - $last_update ------\n");
  require_once(ABSPATH . 'wp-admin/includes/file.php');
  require_once(ABSPATH . 'wp-admin/includes/media.php');
  require_once(ABSPATH . 'wp-admin/includes/image.php');
  $houzezImport = new Fuvals_houzezImport_Tokko(0, true);
  $next = true;
  $i = 0;
  while ($next) {
    $result = $houzezImport->get_last_properties($i, $minval);
    $properties = $result['resultado']['fichas'];
    error_log("\nFICHAS PÁGINA \n" . print_r($result['fichas'], true));
    // process properties
    foreach (array_reverse($properties) as $property) {
      if (!isset($property['fechaac_inmueble']) || empty($property['fechaac_inmueble'])) {
        //Check if property is already loaded
        global $wpdb;
        $table_houzez_data = $wpdb->prefix . "postmeta";
        $postIdQ = $wpdb->get_results("SELECT post_id FROM $table_houzez_data WHERE meta_key = 'fave_property_id' and meta_value = '" . $property['in_fic'] . "'");
        if (empty($postIdQ)) {
          error_log("Updating property: " . $property['in_fic'] . " - date: " . $property['fechaac_inmueble']);
          $houzezImport->process_property($property['in_fic']); //, $minval);
        } else {
          error_log("Skipping new property: " . $property['in_fic']);
        }
      } elseif ($property['fechaac_inmueble'] > $last_update) {
        error_log("Updating property: " . $property['in_fic'] . " - date: " . $property['fechaac_inmueble']);
        $houzezImport->process_property($property['in_fic']); //, $minval);
        //Only update for first page
        if ($i == 0)
          update_option('houzez_import_last_date', $property['fechaac_inmueble']);
      } else {
        $next = false;
        error_log("Skipping property processed: " . $property['in_fic'] . " - date: " . $property['fechaac_inmueble']);
      }
    }
    $i++;
  }
  //Get properties
  error_log("------END DEBUG------");
}
/*Function executed by Cron*/
function import_houzez_properties() {
  error_log("------START DEBUG------\n");
  set_time_limit(0);
  ini_set('max_execution_time', '-1');
  require_once(ABSPATH . 'wp-admin/includes/file.php');
  require_once(ABSPATH . 'wp-admin/includes/media.php');
  require_once(ABSPATH . 'wp-admin/includes/image.php');
  //database
  global $wpdb;
  $table_houzez_data = $wpdb->prefix . "postmeta";
    // Import object
  $houzezImport = new Fuvals_houzezImport_Tokko(0, true);
  $result = true;
  $_REQUEST["limit"] = 3; 
  while (!empty($result)) {
    if ( get_option('houzez_import_page_complete', false) ) {
      $process_last = false;
      $_REQUEST["page"] = get_option('houzez_import_last_page', 0) + 1;
      update_option('houzez_import_page_complete', false);
    }
    else {
      $process_last = true;
      $_REQUEST["page"] = get_option('houzez_import_last_page', 0);
    }
    update_option('houzez_import_last_page', $_REQUEST["page"]);
    $result = $houzezImport->callApi();
    error_log("PROCESSING PAGE:" . $_REQUEST["page"]);
    $i = -1;
    foreach ($result as $property) {
      //convert object to array
      $prop = json_decode(json_encode($property), true);
      $i += 1;
      //error_log(print_r($prop,true));
      if ( $process_last ) {
        error_log("PROCESSING UNFINISHED PAGE Post");
        //check array until we find last unprocessed
        $postIdQ = $wpdb->get_results("SELECT post_id FROM $table_houzez_data WHERE meta_key = 'fave_property_id' and meta_value = '" . $prop['data']['reference_code'] . "'");
        if ( !empty($postIdQ) ) {
          $last_postIdQ = array_shift($postIdQ)->post_id;
          //Si no es el último seguimos
          if ( isset( $result[($i + 1)] ) ) {
            error_log("Skipping property: ".$prop['data']['reference_code']);
            continue;
          }
          else {
            $do_process_last = false;
          }
        }
        else {
          $do_process_last = true;
        }
        $process_last = false;
        //delete property
        error_log("Deletting property: ".$last_postIdQ);
        //wp_delete_post($last_postIdQ);
        //load again
        if ( $do_process_last ) {
          $last_prop = json_decode(json_encode($result[($i - 1)]), true);
          error_log("Adding last property: ".$last_prop['data']['reference_code']);
          $houzezImport->process_property($last_prop['data'], 0, true);
        }
      }
      $houzezImport->process_property($prop['data'], 0, true);

      error_log("DONE: property-" . $prop['data']['reference_code'] . "\n");
    }
    update_option('houzez_import_page_complete', true);
    error_log("END PROCESSING PAGE:" . $_REQUEST["page"]);
    // for ($i = $first; $i < $limit; $i++) {
    //   $result = $houzezImport->get_valued_properties($i, $filters);
    //   error_log("\n\nProcesando PÁGINA " . get_option( 'houzez_import_last_page', 0 )." de $limit");
    //   error_log("\nFICHAS PÁGINA " . print_r($result['fichas'], true));
    //   foreach ($result['fichas'] as $ficha) {
    //     $houzezImport->process_property($ficha, $minval);
    //   }
    //   update_option('houzez_import_last_page', $i+1);
    // }
  }
  error_log("------END DEBUG------");
}

function deduplicateThumb($postId, $propertyImg)
{
  $images = get_post_meta($postId, 'fave_property_images');
  //Get first image (duplicated)
  $first_filepath = get_attached_file(array_shift($images));
  $first_filepath_arr = explode('/', $first_filepath);
  $first_filename = array_pop($first_filepath_arr);
  $first_base_arr = explode('_', $first_filename);
  array_shift($first_base_arr);
  $first_base = join('_', $first_base_arr);

  foreach ($images as $fid) {
    $filepath = get_attached_file($fid);
    //Reaload images if error found
    if (is_wp_error($filepath) || is_wp_error($fid)) {
      return false;
    }
    $filepath_arr = explode('/', $filepath);
    $filename = array_pop($filepath_arr);
    if (str_contains($filename, $first_base)) {
      delete_post_meta($postId, 'fave_property_images', "$fid");
      error_log("Se borra imagen por estar duplicada");
    }
  }
  return true;
}
// Conciliate Thumb
function fuvalsHI_conciliateThumb($postId, $frontImgUrl)
{
  error_log("CONCILIATE thumb");
  $thumb = get_post_meta($postId, '_thumbnail_id');
  if (is_wp_error($thumb)) {
    return false;
  }
  $filepath = get_attached_file($thumb[0]);
  if (is_wp_error($filepath)) {
    return false;
  }
  $file_name = basename($filepath);
  if (file_exists($filepath)) {
    if (str_contains($file_name, '_thumb')) {
      $file_name = fuvalsHI_update_path($thumb[0], $filepath, '_thumb');
    }
  } else {
    error_log("FILE not found, reloading image");
    delete_post_meta($postId, 'fave_property_images', $thumb[0]);
    wp_delete_attachment($thumb[0]);
  }
  $imgUrl = explode('?', $frontImgUrl)[0];
  $imgPath = pathinfo($imgUrl);
  $name = $imgPath['basename'];
  if ($file_name != $name && $file_name != $imgPath['filename'] . '-1.' . $imgPath['extension']) {
    error_log("CONCILIATE UPDATE thumb from $file_name to $name");
    wp_delete_attachment($thumb[0]);
    dowloadPostImage($postId, $frontImgUrl, '_thumbnail_id');
  }
  return true;
}
function fuvals_delete_all_post_meta($postId, $meta, $file = true)
{
  $meta_ids = get_post_meta($postId, $meta);
  foreach ($meta_ids as $meta_id) {
    delete_post_meta($postId, 'fave_property_images', $meta_id);
    if ($file)
      wp_delete_attachment($meta_id, true);
  }
}
// Conciliate images
function fuvalsHI_conciliateImages($postId, $propertyImg, $frontImgUrl)
{
  error_log("CONCILIATE images: ");
  $images = get_post_meta($postId, 'fave_property_images');
  $propertyImages = [];
  foreach ($images as $fid) {
    $filepath = get_attached_file($fid);
    //Reaload images if error found
    if (is_wp_error($filepath) || is_wp_error($fid)) {
      return false;
    }
    //Check image name
    if (!file_exists($filepath)) {
      error_log("FILE not found, reloading image");
      delete_post_meta($postId, 'fave_property_images', $fid);
      wp_delete_attachment($fid);
      continue;
    }
    $file_name = basename($filepath);
    $propertyImages[$fid] = explode('-', $file_name)[0];
  }
  //error_log("CONCILIATE ORIGINAL images in TOKKO: ".print_r($propertyImg, true));
  //error_log("CONCILIATE ORIGINAL images in POST: ".print_r($propertyImages, true));
  //error_log("IMAGES: ".print_r($propertyImages, true));
  //error_log( "CONCILIATE images ".print_r($propertyImages, true) );
  foreach ($propertyImg as $imgKey => $imgUrl) {
    $imgUrl = explode('?', $imgUrl)[0];
    $imgPath = pathinfo($imgUrl);
    $name = $imgPath['basename'];
    $name_no_ext = explode('.', $name)[0];
    //If not in imgs reload
    foreach ($propertyImages as $fid => $propImage) {
      if ( strpos($propImage, $name_no_ext) === 0 ) {
        unset($propertyImg[$imgKey]);
        unset($propertyImages[$fid]);
      }
    }
  }
  //error_log("CONCILIATE images in TOKKO: ".print_r($propertyImg, true));
  //error_log("CONCILIATE images in POST: ".print_r($propertyImages, true));
  //Add images not found in property
  foreach ($propertyImg as $imgUrl) {
    error_log("CONCILIATE downloading image $imgUrl");
    dowloadPostImage($postId, $imgUrl, 'fave_property_images');
  }
  //Remove property images not in CRM
  foreach ($propertyImages as $fid => $value) {
    error_log("CONCILIATE deleting image $fid --> $value");
    delete_post_meta($postId, 'fave_property_images', $fid);
    wp_delete_attachment($fid);
  }
  return true;
}
//Update path to get image real name
function fuvalsHI_update_path($fid, $filepath, $pattrn)
{
  //error_log("CONCILIATE updating path $file_name");
  //Rename file
  $path = pathinfo($filepath);
  $newfilename_arr = explode($pattrn, $filepath);
  //Get first numbers
  if ($pattrn != '_thumb') {
    preg_match("/(\d+)/", $newfilename_arr[1], $matches);
    $newfilename = substr($newfilename_arr[1], strlen($matches[0]));
  } else {
    $newfilename = $newfilename_arr[1];
  }
  //error_log("CONCILIATE updating newfile $newfilename: ".print_r($filepath, true));
  $newfile = $path['dirname'] . "/" . $newfilename;
  rename($filepath, $newfile);
  update_attached_file($fid, $newfile);
  error_log("ATTACHMENT " . $fid . " updated from " . $filepath . " to " . $newfilename);
  //wp_generate_attachment_metadata($fid, $newfile);
  error_log("ATTACHMENT thumbs generated");
  return $newfilename;
}
//Load thumb for a property.
function loadthumbProperty($postId, $frontImgUrl, $reload = false)
{
  //Delete all images from post
  if ($reload) {
    fuvals_delete_all_post_meta($postId, '_thumbnail_id');
  }
  //ADD FRONT IMAGE
  return dowloadPostImage($postId, $frontImgUrl, '_thumbnail_id');
}
//Load image for a property. Needs array with image.
function loadImgProperty($postId, $imgUrlList, $reload = false)
{
  //Delete all images from post
  if ($reload) {
    fuvals_delete_all_post_meta($postId, 'fave_property_images');
  }
  //ADD IMAGE GALLERY
  $result = true;
  foreach ($imgUrlList as $imgUrl) {
    if (!dowloadPostImage($postId, $imgUrl, 'fave_property_images')) {
      $result = false;
    }
  }
  return $result;
}
function oldLoadImgProperty($postID, $frontImgUrl, $imgUrlList, $propertyId)
{
  //ADD FRONT IMAGE
  if (!empty($frontImgUrl)) {

    /*Images are save as attachment posts , with
        post_type = attachment | post_parent = created post id | post_status = inherit */
    $url = $frontImgUrl;
    $aux = download_url($url);
    //Obtain archive name
    preg_match('/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $url, $matches);
    $fileI = array(
      'name' => 'propiedad' . $propertyId . '_' . basename($matches[0]),
      'tmp_name' => $aux
    );
    $frontImg_id = media_handle_sideload($fileI, $postID);
    add_post_meta($postID, 'fave_property_images', $frontImg_id);
    add_post_meta($postID, '_thumbnail_id', $frontImg_id);
  }

  //ADD IMAGE GALLERY
  $count = 1;
  foreach ($imgUrlList as $imgUrl) {
    $aux = download_url($imgUrl);
    //Obtain archive name
    preg_match('/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $imgUrl, $matches);
    $file = array(
      'name' => 'propiedad' . $propertyId . '_img' . $count . basename($matches[0]),
      'tmp_name' => $aux
    );
    $img_id = media_handle_sideload($file, $postID);
    add_post_meta($postID, 'fave_property_images', $img_id);
    //wp_generate_attachment_metadata($postID);
    $count++;
  }
}
/*
 * Download Images and add them to post
 */
function dowloadPostImage($postId, $imgUrl, $type)
{
  try {
    //Obtain archive name
    preg_match('/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $imgUrl, $matches);
    $name = explode('?', basename($matches[0]))[0];
    //continue unless file is right
    if (!empty($name)) {
      error_log("DOWNLOADING image: " . $name);
      $aux = download_url($imgUrl);
      if (is_wp_error($aux)) {
        error_log("ERROR downloading image $name: " . $imgUrl);
        unlink($aux);
        return false;
      }
      //Obtain archive name
      $file = array(
        'name' => $name,
        'tmp_name' => $aux
      );
      $img_id = media_handle_sideload($file, $postId);
      //unlink($aux);
      if (!is_wp_error($img_id)) {
        add_post_meta($postId, $type, $img_id);
        //$filepath = get_attached_file($fid);
        //wp_generate_attachment_metadata($img_id, $filepath);
      } else {
        error_log("ERROR setting image: " . $imgUrl);
        return false;
      }
    } else {
      error_log("ERROR in image name: " . $imgUrl);
    }
  } catch (\Exception $e) {
    error_log("ERROR downloading image: " . $imgUrl . "\n" . $e->getMessage());
    return false;
  }
  return true;
}


//MUST DO - VERIFICACION PARA LOS CAMPOS QUE PUEDEN ESTAR EN FEATURES Y NO ES NECESARIO SETEARLOS
function createAux(){
  global $wpdb;
  $table = $wpdb->prefix . "houzez_fields_builder";
  $wpdb->insert($table, array('label' => 'Superficie', 'field_id' => 'superficie', 'type' => 'text', 'is_search' => 'no'));
  $wpdb->insert($table, array('label' => 'Mostrar precio', 'field_id' => 'show-price', 'type' => 'text', 'is_search' => 'no'));
}
function createNewCustomFields()
{
  global $wpdb;
  $table = $wpdb->prefix . "houzez_fields_builder";
  //Ref-fields for prices
  $wpdb->insert($table, array('label' => 'Alquiler todo Enero - Ref', 'field_id' => 'alq-all-jan-ref', 'type' => 'text', 'is_search' => 'no'));
  $wpdb->insert($table, array('label' => 'Alquiler todo Febrero - Ref', 'field_id' => 'alq-all-feb-ref', 'type' => 'text', 'is_search' => 'no'));
  $wpdb->insert($table, array('label' => 'Alquiler primera quincena de Enero - Ref', 'field_id' => 'first-half-jan-ref', 'type' => 'text', 'is_search' => 'no'));
  $wpdb->insert($table, array('label' => 'Alquiler segunda quincena de Enero - Ref', 'field_id' => 'second-half-jan-ref', 'type' => 'text', 'is_search' => 'no'));
  $wpdb->insert($table, array('label' => 'Alquiler primera quincena de Febrero - Ref', 'field_id' => 'first-half-feb-ref', 'type' => 'text', 'is_search' => 'no'));
  $wpdb->insert($table, array('label' => 'Alquiler segunda quincena de Febrero - Ref', 'field_id' => 'second-half-feb-ref', 'type' => 'text', 'is_search' => 'no'));
  //PERIOD OF RENT FIELDS
  $wpdb->insert($table, array('label' => 'Alquiler todo Enero', 'field_id' => 'alq_all_jan', 'type' => 'text', 'is_search' => 'yes'));
  $wpdb->insert($table, array('label' => 'Alquiler todo Febrero', 'field_id' => 'alq_all_feb', 'type' => 'text', 'is_search' => 'yes'));
  $wpdb->insert($table, array('label' => 'Alquiler primera quincena de Enero', 'field_id' => 'first-half-jan', 'type' => 'text', 'is_search' => 'yes'));
  $wpdb->insert($table, array('label' => 'Alquiler segunda quincena de Enero', 'field_id' => 'second-half-jan', 'type' => 'text', 'is_search' => 'yes'));
  $wpdb->insert($table, array('label' => 'Alquiler primera quincena de Febrero', 'field_id' => 'first-half-feb', 'type' => 'text', 'is_search' => 'yes'));
  $wpdb->insert($table, array('label' => 'Alquiler segunda quincena de Febrero', 'field_id' => 'second-half-feb', 'type' => 'text', 'is_search' => 'yes'));
  $wpdb->insert($table, array('label' => 'Nro. Plantas', 'field_id' => 'nro-plant', 'type' => 'text', 'is_search' => 'yes'));
}

function createCustomFields()
{
  //REVISAR QUE ESTEN TODOS
  try {
    global $wpdb;
    $table = $wpdb->prefix . "houzez_fields_builder";
    //$wpdb->insert($table, array('label' => 'Año de construccion', 'field_id' => 'anio-construccion', 'type' => 'text', 'is_search' => 'yes'));
    $wpdb->insert($table, array('label' => 'Antiguedad', 'field_id' => 'antiguedad', 'type' => 'text', 'is_search' => 'yes'));
    $wpdb->insert($table, array('label' => 'Orientacion', 'field_id' => 'orientacion', 'type' => 'text', 'is_search' => 'yes'));
    $wpdb->insert($table, array('label' => 'Amueblado', 'field_id' => 'amueblado', 'type' => 'text', 'is_search' => 'yes'));
    $wpdb->insert($table, array('label' => 'Estado', 'field_id' => 'estado', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Precio Alquiler', 'field_id' => 'precio_alq', 'type' => 'text', 'is_search' => 'yes'));
    //MUST DO - El titulo de este campo cambia segun el tipo de proiedad que sea por lo cual: tipo de $property[type]
    //$wpdb->insert($table, array('label' => 'Tipo de Propiedad', 'field_id' => 'tipo_prop', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Ubicacion', 'field_id' => 'ubicacion', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Expensas', 'field_id' => 'expensas', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Categoria', 'field_id' => 'categoria', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Impuesto', 'field_id' => 'impuesto', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Emprendimiento', 'field_id' => 'emprendimiento', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Cantidad de Ascensores', 'field_id' => 'cant_asc', 'type' => 'text', 'is_search' => 'yes'));
    //Superficie
    //$wpdb->insert($table, array('label' => 'Sup. total', 'field_id' => 'sup_total', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Sup. cubierta', 'field_id' => 'sup_cub', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Sup. semi-cubierta', 'field_id' => 'sup_semi_cub', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert( $table, array( 'label' => 'Sup. Casco', 'field_id' => 'sup_casc' , 'type' => 'text' , 'is_search' => 'yes' ) );
    //$wpdb->insert( $table, array( 'label' => 'Sup. Casa', 'field_id' => 'sup_casa' , 'type' => 'text' , 'is_search' => 'yes' ) );

    //$wpdb->insert($table, array('label' => 'Estado oficinas', 'field_id' => 'estado_of', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Zonificacion', 'field_id' => 'zonific', 'type' => 'text', 'is_search' => 'yes'));
    //Factor de ocupacion total
    //$wpdb->insert($table, array('label' => 'F.O.T', 'field_id' => 'fot', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Cant. naves', 'field_id' => 'cant_nav', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Usos y limites', 'field_id' => 'usos_limt', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Tipo de piso', 'field_id' => 'tipo_piso', 'type' => 'text', 'is_search' => 'yes'));
    //Para local
    //$wpdb->insert($table, array('label' => 'Ideal para', 'field_id' => 'ideal', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Rubro actual', 'field_id' => 'rubro', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert($table, array('label' => 'Frente', 'field_id' => 'frente', 'type' => 'text', 'is_search' => 'yes'));
    //$wpdb->insert( $table, array( 'label' => 'Habilitado para vivienda', 'field_id' => 'hab_viv' , 'type' => 'text' , 'is_search' => 'yes' ) );
    //Falta campo de habilitacion para vivienda
    //Para Campos
    // $wpdb->insert($table, array('label' => 'actividad1', 'field_id' => 'act1', 'type' => 'text', 'is_search' => 'yes'));
    // $wpdb->insert($table, array('label' => 'actividad2', 'field_id' => 'act2', 'type' => 'text', 'is_search' => 'yes'));
    // $wpdb->insert($table, array('label' => 'actividad3', 'field_id' => 'act3', 'type' => 'text', 'is_search' => 'yes'));
    // $wpdb->insert($table, array('label' => 'Valor por Hectarea', 'field_id' => 'valor_ha', 'type' => 'text', 'is_search' => 'yes'));
    // $wpdb->insert($table, array('label' => 'Codigo', 'field_id' => 'codigo_camp', 'type' => 'text', 'is_search' => 'yes'));
    // $wpdb->insert($table, array('label' => 'Riego', 'field_id' => 'riego', 'type' => 'text', 'is_search' => 'yes'));
    // $wpdb->insert($table, array('label' => 'Gas', 'field_id' => 'gas', 'type' => 'text', 'is_search' => 'yes'));

    //Campos que son features pero pueden contener info o no estar dentro de las caracteristicas.
    // MUST DO -
  } catch (\Throwable $th) {
    error_log(print_r($th, true));
  }
}

/* OPERATIONS */
//Show propertys map
function fuvals_run_operation($params)
{
  list($operation, $parameters) = $params;
  error_log("RUN OPERATION: $operation");
  if (!empty($parameters))
    $operation($parameters);
  else
    $operation();
}
function fuvals_properties_show_map()
{
  $props = get_posts([
    'post_type' => 'property',
    'post_status' => 'publish',
    'numberposts' => -1
  ]);
  foreach ($props as $property) {
    error_log("Showing Property map: $property->ID");
    update_post_meta($property->ID, 'fave_property_map', '1');
  }
}
//
function fuvals_update_agent($params)
{
  list($from, $to) = $params;
  error_log("Update agents START from $from to $to");
  $props = get_posts([
    'post_type' => 'property',
    'post_status' => 'publish',
    'numberposts' => -1,
    'meta_key' => 'fave_agents',
    'meta_value' => $from
  ]);
  $houzezImport = new Fuvals_houzezImport_Tokko($to, false);
  foreach ($props as $property) {
    //Get id
    error_log("Updating agent: $property->ID");
    $id = get_post_meta($property->ID, 'fave_property_id', true);
    $apiData = $houzezImport->property_details($id);
    if (isset($apiData['resultado']['ficha'][0])) {
      error_log("Updating ficha $id con: " . $apiData['resultado']['ficha'][0]['vendedor_nombre']);
      $houzezImport->property = $apiData['resultado']['ficha'][0];
      $houzezImport->postId = $property->ID;
      $houzezImport->assign_agent();
    }
  }
}
//
function fuvals_properties_load_activities()
{
  error_log("Activities Load START");
  $args = array(
    'posts_per_page' => -1,
    'post_type' => 'property',
    'limit' => 5,
    'tax_query' => array(
      array(
        'taxonomy' => 'property_type',
        'field' => 'slug',
        'terms' => ['chacra', 'campo'],
      ),
    ),
  );
  $props = new WP_Query($args);
  $activities = ['G' => 'Ganadería', 'A' => 'Agricultura', 'P' => 'Porcinos', 'C' => 'Apicultura', 'T' => 'Turístico', 'H' => 'Haras', 'F' => 'Forestación'];
  while ($props->have_posts()) {
    $props->the_post();
    $post = $props->post;
    delete_post_meta($post->ID, 'fave_actividades');
    for ($i = 1; $i <= 3; $i++) {
      $act_sum = get_post_meta($post->ID, 'fave_act' . $i, true);
      if (!empty($act_sum)) {
        if ($act_sum == 'Fruticultura')
          $act_sum = 'Forestación';
        add_post_meta($post->ID, 'fave_actividades', $act_sum);
        error_log("Activities Property added: $post->ID --> " . $act_sum . " \n");
      }
    }
    error_log("Activities Property processed: $post->ID \n");
  }
  error_log("Activities Load END");
}
