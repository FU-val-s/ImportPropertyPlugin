<?php

// /wp-content/plugins/TokkoImportPlugin-main/class-fuvals-houzezImport.php
//class that make connection with the api and transfer the results.
require plugin_dir_path(__FILE__) . '/api.php';
class Fuvals_houzezImport_Tokko
{
  public int $postId;
  //Connection data for tokko
  public $apiUser = 'TokkoTest'; // for tokko?
  public $apiKey = 'b87fe1d3b55263138083c53238333311c8046c81';
  // FIN - DATOS INMOBILIARIA //
  public $apiUrl = 'http://tokkobroker.com/api/v1/webcontact/?key=';
  public array $apiData;
  public $property;
  //CONSTRUCT
  public function __construct($agent, $conciliateImages) //string $apiDest, $operation)
  {
    $this->agent = $agent;
    $this->conciliateImages = $conciliateImages;
    $this->apiData = [
      'inm' => $this->apiUser,
      'apiK' => $this->apiKey,
      'utf8decode' => $this->apiKey,
    ];
    //Load taxonomies
    $taxonomies = [
      'property_type',
      'property_status',
      'property_feature',
      'property_country',
      'property_state',
      'property_city',
      'property_area'
    ];
    foreach ($taxonomies as $taxonomy) {
      $ops_objs = get_terms(
        $taxonomy,
        array(
          'hide_empty' => false,
        )
      );
      foreach ($ops_objs as $op) {
        $this->$taxonomy[$op->term_id] = $op->slug;
      }
    }
    error_log('fuval initiated');
  }
  //
  public function clean_duplicates()
  {
  }
  //

  //
  public function setPropertyTerms($terms, $taxonomy, $reload = false, $field_name = "name")
  {
    $termIds = [];
    error_log('Propiedad Setting terms: ' . $taxonomy);
    if (!empty($terms)) {
      $terms = is_array($terms) ? $terms : [$terms];
      if ($reload) {
        $term_objs = wp_get_object_terms($this->postId, $taxonomy);
        foreach ($term_objs as $term) {
          wp_remove_object_terms($this->postId, $term->term_id, $taxonomy);
        }
      }
      foreach ($terms as $term) {
        //$term = html_entity_decode($term);
        $termSlug = sanitize_title($term[$field_name]);
        if (!($fid = array_search($termSlug, $this->$taxonomy))) {
          error_log('Adding term: ' . $term[$field_name]);
          //error_log(print_r($propFeat, true));
          $id = wp_insert_term($term[$field_name], $taxonomy);
          if (is_wp_error($id)) {
            error_log($id->get_error_message());
          } else {
            $fid = $id['term_id'];
            $this->$taxonomy[$fid] = $termSlug;
          }
        }
        $termIds[] = $fid;
      }
      wp_set_object_terms($this->postId, $termIds, $taxonomy);
    } else {
      error_log('Propiedad Setting terms NO terms: ' . $taxonomy);
    }
    return;
  }

  public function set_status()
  {
    error_log("SET_STATUS FOR: " . $this->property['id'] . "\n");
    $prop = $this->property;
    $stat = 'publish';
    if (isset($prop['custom_tags'])) {
      $tags = json_decode(json_encode($prop['custom_tags']), true);
      foreach ($tags as $tag) {
        $tag = json_decode(json_encode($tag), true);
        //error_log("entra a tag " . $tag['name']);
        $aux = strpos($tag['name'], "Compartida");
        if (!($aux === false)) {
          $stat = 'draft';
          break;
        }
      }
    }
    return $stat;
  }
  public function create_property()
  {
    //static values for test
    $type = 'property';
    $status = $this->set_status();
    $authorId = '1'; // admin wordpress
    //Edit post information
    $postData = array(
      'post_date' => date('Y-m-d h:i:s'),
      'post_date_gmt' => date('Y-m-d h:i:s'),
      'post_title' => $this->property['fake_address'],
      'post_content' => html_entity_decode($this->property['description']),
      'post_status' => $status,
      'post_type' => $type,
      'post_author' => $authorId,
    );
    //Create property
    $this->postId = wp_insert_post($postData, true);
    error_log('propiedad creada: ' . $this->postId);
    return $this->postId;
  }
  //
  public function property_details($id)
  {
    $data = array_merge(
      $this->apiData,
      array(
        'json' => 'fichas.propiedades',
        'id' => $id
      )
    );
    return $this->callApi($data);
  }
  //
  // public function get_properties()
  // {
  //   $data = array_merge(
  //     $this->apiData,
  //     ['json' => 'fichas.destacadas']
  //   );
  //   return $this->callApi($data);
  // }
  public function get_valued_properties($i, $filters = [])
  {
    $data = array_merge(
      $this->apiData,
      array(
        'json' => 'resultados.fichas',
        'ordenar' => 'preciomayor',
        'codigo_ficha' => '',
        'tipo_operacion' => '',
        'tipo_inmueble' => '',
        'sellocalidades' => '',
        'barrios1' => '',
        'Ambientes' => '',
        'part' => '',
        'page' => $i,
        //'no_disponible'=>'False',
        'valor_minimo' => '',
        'valor_maximo' => '',
        'fechainic' => '',
        'fechafinc' => '',
        'rppagina' => '10',
        'ignora_limite' => true,
      ),
      $filters
    );
    //error_log('VALUED PROPS: ' . print_r($data, true));
    return $this->callApi($data);
  }
  //
  public function get_last_properties($i, $minval = false)
  {
    $data = array_merge(
      $this->apiData,
      array(
        'json' => 'resultados.fichas',
        'rppagina' => '10',
        'ignora_limite' => true,
        'ordenar' => 'ultac',
        'page' => $i,
      )
    );
    if ($minval != false) {
      $data['valor_minimo'] = $minval;
    }
    return $this->callApi($data);
  }
  public function process_property($ficha, $minval = false)
  {
    //$ficha is only one property - schema: Array[0][data]->data of the property
    global $wpdb;
    $table_houzez_data = $wpdb->prefix . "postmeta";
    error_log("Procesando propiedad " . $ficha['id']);
    //$apiData = $this->property_details($ficha);
    error_log("Detalis propiedad fetched ");
    if (isset($ficha)) {
      $this->property = $ficha;
      //Property images array
      $propertyImg = $ficha['photos'];
      //Property features array
      if (isset($ficha['tags'])) {
        $propertyFeatures = $ficha['tags'];
      } else {
        $propertyFeatures = [];
      }
      //Additional features
      if (isset($ficha['custom_tags'])) {
        $extraFeatures = $ficha['custom_tags'];
      }
      //error_log("Features: " . print_r($propertyFeatures, true));
      //Get property
      $postIdQ = $wpdb->get_results("SELECT post_id FROM $table_houzez_data WHERE meta_key = 'fave_property_id' and meta_value = '" . $this->property['id'] . "'");
      //Check if property is active
      // if ($this->property['in_int'] == 'True' && (empty($this->property['in_esi']) || $this->property['in_esi'] == 'N')) {
      //   if ($minval && (empty($this->property['in_val']) || $this->property['in_val'] < $minval)) {
      //     error_log("Skipping Property MIN VAL: " . $this->property['in_val']);
      //     return;
      //   }
      error_log("Processing Property");
      //error_log(print_r($posts,true));
      //Check if property exist in database
      if (empty($postIdQ)) {
        $new = true;
        //Create property
        error_log("Create property");
        $this->postId = $this->create_property();
      } else {
        //Search property last modified date
        $new = false;
        //Clean properties
        $this->postId = array_shift($postIdQ)->post_id;
        if (count($postIdQ) > 1) {
          error_log("Se han detectado más de 1 post asociados a la propiedad, se eliminarán los posteriores:" . print_r($postIdQ, true));
          foreach ($postIdQ as $post) {
            wp_delete_post($post->post_id);
            error_log("Se ha eliminado el post:" . $post->post_id);
          }
        }
        error_log("La propiedad ya existe:" . $this->postId . ", hay que implementar update");
        //Change title or description
        $data = array(
          'ID' => $this->postId,
          'post_title' => $ficha['publication_title'],
          'post_content' => html_entity_decode($ficha['description']),
        );
        wp_update_post($data);
        error_log("Actulizados título y desc:" . $this->postId);
      }
      // //Assign agent
      // error_log("Asignando agente: $this->agent");
      // $this->assign_agent();
      //PROPERTY ID
      update_post_meta($this->postId, 'fave_property_id', $this->property['id']);
      //PROPERTY TYPE
      $typeProp = $this->set_typeProp($ficha['type']);
      //error_log("TIPO: " . print_r($typeProp), true);
      $this->setPropertyTerms($typeProp, 'property_type');
      //PROPERTY CUSTOM FIELDS
      $this->loadCustomFields();
      //Set PROPERTY OPERATION TYPE
      $this->setOperationType($this->property['operations']);
      //PRICE with currencie already set.
      $this->set_price($this->property['operations']);
      //PRICE AND CURRENCIE FOR RENT
      error_log("Create property: main data updated");
      //ROOMS AND SIZES
      update_post_meta($this->postId, 'fave_property_bedrooms', $this->property['suite_amount']);
      update_post_meta($this->postId, 'fave_property_bathrooms', $this->property['bathroom_amount']);
      update_post_meta($this->postId, 'fave_property_rooms', $this->property['room_amount']);
      update_post_meta($this->postId, 'fave_property_garage', $this->property['parking_lot_amount']);

      //Add video
      // if (!empty($this->property['videos']))
      //   update_post_meta($this->postId, 'fave_video_url', $this->property['in_vid']);
      //Type of unity conditional
      // if (!empty($this->property['tipo_medida']) || $this->property['in_tip'] == 'Campo' || $this->property['in_tip'] == 'Chacra') {
      //   if ($this->property['in_tip'] == 'Campo' || $this->property['in_tip'] == 'Chacra') {
      //     update_post_meta($this->postId, 'fave_property_land_postfix', 'HAS');
      //     update_post_meta($this->postId, 'fave_property_size_prefix', 'HAS');
      //   } else {
      //     update_post_meta($this->postId, 'fave_property_land_postfix', $this->property['tipo_medida']);
      //     update_post_meta($this->postId, 'fave_property_size_prefix', 'm²');
      //   }
      // } else {
      //   update_post_meta($this->postId, 'fave_property_land_postfix', 'm²');
      //   update_post_meta($this->postId, 'fave_property_size_prefix', 'm²');

      // }
      update_post_meta($this->postId, 'fave_property_size', $ficha['roofed_surface']);
      update_post_meta($this->postId, 'fave_property_land', $ficha['total_surface']);
      update_post_meta($this->postId, 'fave_property_land_postfix', 'm²');
      update_post_meta($this->postId, 'fave_property_size_prefix', 'm²');
      error_log("Create property: sizes updated");
      //ADDRESS AND LOCATION DATA
      $location = explode('|', $ficha['location']['full_location']);
      //create associative array for setProperty
      //error_log("LOCALIDAD: " . print_r($location, true));
      $this->setPropertyTerms($this->createAsso($location[0]), 'property_country', true);
      $this->setPropertyTerms($this->createAsso($location[1]), 'property_state', true);
      $this->setPropertyTerms($this->createAsso($location[2]), 'property_city', true);
      $this->setPropertyTerms($this->createAsso($location[3]), 'property_area', true);
      error_log("Create property location updated");
      // MOSTRAR MAPA
      $coordinates = $this->property['geo_lat'] . "," . $this->property['geo_long'];
      update_post_meta($this->postId, 'fave_property_location', $coordinates);
      update_post_meta($this->postId, 'fave_property_map', '1');
      //Necesito el pais, codigo postal y departamento.
      //$address = $this->property['geo_lat'] . " , " . $this->property['geo_long'];
      update_post_meta($this->postId, 'fave_property_map_address', $this->property['real_address']);
      //FEATURES
      //error_log("FEATURES: ".print_r(json_decode(json_encode($propertyFeatures),true),true));
      $this->setPropertyTerms($propertyFeatures, 'property_feature');
      $this->setPropertyTerms($extraFeatures, 'property_feature', false, 'group_name');
      error_log("Create property features updated");
      //LOAD IMAGES PACK FOR POST
      $imageList = $this->getImageUrl($propertyImg);
      //Do not update for now
      if ($new) {
        loadThumbProperty($this->postId, $propertyImg['0']['image']);
        loadImgProperty($this->postId, $imageList);
        //oldLoadImgProperty($this->postId, $this->property['img_princ'], $propertyImg, $this->property['in_fic']);
      } elseif ($this->conciliateImages) {
        //if ( !fuvalsHI_conciliateThumb($this->postId, $this->property['img_princ']) ) {
        error_log('ERRORES en thumb... reloading');
        if (!loadThumbProperty($this->postId, $propertyImg['0']['image'], true)) {
          error_log('ERRORES IN RELOADING THUMB');
        }
        //}
        //if ( !fuvalsHI_conciliateImages($this->postId, $propertyImg, $this->property['img_princ']) ) {
        error_log('ERRORES in images... reloading');
        if (!loadImgProperty($this->postId, $imageList, true)) {
          error_log('ERRORES IN RELOADING IMGS');
        }
        //}
      }
      wp_update_post(['ID' => $this->postId]);
      error_log('TERMINAMOS DE LEVANTAR LOS DATOS E IMAGENES');
    } else {
      error_log("NO SE PUDO OBTENER DETALLE DE LA FICHA: $ficha");
    }
  }

  public function set_typeProp($type)
  {
    $result = array(json_decode(json_encode($type), true));
    //error_log("SET TYPE: ".print_r($result,true));
    return $result;
  }
  public function createAsso($loc)
  {
    $result = array(array('name' => $loc));
    return $result;
  }
  public function getImageUrl($listImg)
  {
    $result = [];
    foreach ($listImg as $img) {
      $result[] = $img['original'];
    }

    return $result;
  }
  public function assign_agent()
  {
    if ($this->agent) {
      error_log("Adding agent:" . $this->agent);
      update_post_meta($this->postId, 'fave_agent_display_option', 'agent_info');
      update_post_meta($this->postId, 'fave_agents', $this->agent);
    } else {
      $agent_name = $this->property['vendedor_nombre'] . ' ' . $this->property['vendedor_apellido'];
      //$agent_name = 'Fernando Uval';
      $args = array(
        'post_type' => 'houzez_agent',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'title' => $agent_name,
      );
      $loop = new WP_Query($args);

      if ($loop->have_posts()) {
        $loop->the_post();
        $agent_id = get_the_ID();
        error_log("Adding agent:" . $agent_id);
        update_post_meta($this->postId, 'fave_agents', $agent_id);
        update_post_meta($this->postId, 'fave_agent_display_option', 'agent_info');
      }
    }
  }
  /*
   *
   */
  public function loadCustomFields()
  {
    error_log("Load Custom fields");
    //update_post_meta($this->postId, 'fave_anio_construccion', $this->property['in_anio']);
    update_post_meta($this->postId, 'fave_antiguedad', $this->property['age']);
    update_post_meta($this->postId, 'fave_orientacion', $this->property['orientation']);
    //update_post_meta($this->postId, 'fave_amueblado', $this->property['in_amu']);
    update_post_meta($this->postId, 'fave_cant_pisos', $this->property['floors_amount']);
    update_post_meta($this->postId, 'fave_estado', $this->property['property_condition']);
    //update_post_meta($this->postId, 'fave_precio_alq', $this->property['in_vaa']);
    update_post_meta($this->postId, 'fave_tipo_prop', $this->property['type']['name']);
    // if (!empty($this->property['location'])) {
    //   update_post_meta($this->postId, 'fave_ubicacion', $this->property['location']['full_location']);
    // }
    //update_post_meta($this->postId, 'fave_categoria', $this->property['in_eco']);
    // if (!empty($this->property['in_exp'])) {
    //   $moe = $this->property['in_moe'] == 'D' ? 'USD' : '$';
    //   update_post_meta($this->postId, 'fave_expensas', $this->property['in_exp'] . ' ' . $moe);
    // }
    // if (!empty($this->property['in_imp'])) {
    //   $impuesto = $this->property['moneda_impuesto'] == 'D' ? 'USD' : '$';
    //   update_post_meta($this->postId, 'fave_impuesto', $this->property['in_imp'] . ' ' . $impuesto);
    // }
    //update_post_meta($this->postId, 'fave_emprendimiento', $this->property['in_edi']);
    //update_post_meta($this->postId, 'fave_cant_asc', $this->property['in_asc']);
    update_post_meta($this->postId, 'fave_sup_cub', $this->property['roofed_surface'] . 'm²');
    update_post_meta($this->postId, 'fave_sup_semi_cub', $this->property['semiroofed_surface'] . 'm²');
    update_post_meta($this->postId, 'fave_nro_plant', $this->property['floors_amount']);
    //update_post_meta($this->postId, 'fave_estado_of', $this->property['in_ale']);
    update_post_meta($this->postId, 'fave_zonific', $this->property['zonification']);
    // update_post_meta($this->postId, 'fave_fot', $this->property['in_fot']);
    // update_post_meta($this->postId, 'fave_cant_nav', $this->property['in_lin']);
    // update_post_meta($this->postId, 'fave_usos_limt', $this->property['in_uso']);
    // update_post_meta($this->postId, 'fave_tipo_piso', $this->property['in_arq']);
    // update_post_meta($this->postId, 'fave_ideal', $this->property['in_ide']);
    // update_post_meta($this->postId, 'fave_rubro', $this->property['in_rub']);
    // update_post_meta($this->postId, 'fave_frente', $this->property['in_pil']);
    // $activities = ['G' => 'Ganadería', 'A' => 'Agricultura', 'P' => 'Porcinos', 'C' => 'Apicultura', 'T' => 'Turístico', 'H' => 'Haras', 'F' => 'Forestación'];
    // delete_post_meta($post->ID, 'fave_actividades');
    // for ($i = 1; $i <= 3; $i++) {
    //   $act_sum = $this->property['in_ac' . $i];
    //   if (!empty($act_sum)) {
    //     $activity = isset($activities[$act_sum]) ? $activities[$act_sum] : $act_sum;
    //     add_post_meta($this->postId, 'fave_actividades', $activity);
    //   }
    // }
    // update_post_meta($this->postId, 'fave_valor_ha', $this->property['in_mt2']);
    // update_post_meta($this->postId, 'fave_codigo_camp', $this->property['in_con']);
    // update_post_meta($this->postId, 'fave_gas', $this->property['in_gas']);
    // if ($this->property['in_tip'] == 'Departamento' || $this->property['in_tip'] == 'Apartamento' || $this->property['in_tip'] == 'Casa') {
    //   update_post_meta($this->postId, 'fave_aire_acondicionado', $this->property['in_paq']);
    // }
    //update_post_meta($this->postId, 'fave_riego', $this->property['in_rie']);
  }

  public function loadCustomPrices($op)
  {
    error_log("LOAD RENT PRICES");
    foreach ($op['prices'] as $price) {
      if ($price['period'] == "1ra quincena de enero") {
        update_post_meta($this->postId, 'fave_first_half_jan', $price['price']);
        update_post_meta($this->postId, 'fave_first_half_jan_ref', $price['price']);
        update_post_meta($this->postId, 'fave_property_sec_price', $price['price']);
      }
      if ($price['period'] == "2da quincena de enero") {
        update_post_meta($this->postId, 'fave_second_half_jan', $price['price']);
        update_post_meta($this->postId, 'fave_second_half_jan_ref', $price['price']);
      }
      if ($price['period'] == "1ra quincena de febrero") {
        update_post_meta($this->postId, 'fave_first_half_feb', $price['price']);
        update_post_meta($this->postId, 'fave_first_half_feb_ref', $price['price']);
      }
      if ($price['period'] == "2da quincena de febrero") {
        update_post_meta($this->postId, 'fave_second_half_feb', $price['price']);
        update_post_meta($this->postId, 'fave_second_half_feb_ref', $price['price']);
      }
      if ($price['period'] == "Enero") {
        update_post_meta($this->postId, 'fave_alq_all_jan', $price['price']);
        update_post_meta($this->postId, 'fave_alq_all_jan_ref', $price['price']);
      }
      if ($price['period'] == "Febrero") {
        update_post_meta($this->postId, 'fave_alq_all_feb', $price['price']);
        update_post_meta($this->postId, 'fave_alq_all_feb_ref', $price['price']);

      }
    }
  }

  public function setOperationType($propOp)
  {
    if (!empty($propOp)) {
      if (count($propOp) > 1) {
        foreach ($propOp as $op) {
          //this only rewrite until the last op, we need to write all options
          wp_set_object_terms($this->postId, $op['operation_type'], 'property_status');
        }
      } else {
        $val = $propOp['0']['operation_type'];
        wp_set_object_terms($this->postId, $val, 'property_status');
      }
    }
  }
  public function set_price($ops)
  {
    if (!empty($ops)) {
      try {
        foreach ($ops as $op) {
          if ($op['operation_type'] == "Venta") {
            error_log("ENTRA EN VENTA PRICES ======");
            update_post_meta($this->postId, 'fave_property_price', $op['prices']['0']['price']);
          }
          if ($op['operation_type'] == "Alquiler temporario") {
            //Rent Operation
            error_log("ENTRA EN ALQUILER PRICES ======");
            $this->loadCustomPrices($op);
          }
        }
      } catch (\Exception $e) {
        error_log("ERROR IN PRICE:\n" . $e->getMessage());
      }
    } else {
      error_log("ERROR - Property doesn't have price");
      update_post_meta($this->postId, 'fave_property_price', 'A confirmar');
    }

  }

  public function update_price()
  {
    if (!empty($this->property['in_val'])) {
      error_log("UPDATING sale PRICE: $this->postId --> " . $this->property['in_val']);
      update_post_meta($this->postId, 'fave_property_price', $this->property['in_val']);
      update_post_meta($this->postId, 'fave_property_sec_price', $this->property['in_vaa']);
    } else {
      error_log("UPDATING rent PRICE: $this->postId --> " . $this->property['in_vaa']);
      update_post_meta($this->postId, 'fave_property_price', $this->property['in_vaa']);
      update_post_meta($this->postId, 'fave_property_sec_price', 0);
    }
    if (!empty($this->property['in_vaa'])) {
      $currencie = '';
      switch ($this->property['in_moa']) {
        case 'P':
          $currencie = '$U';
          break;
        case 'E':
          $currencie = '€';
          break;
      }
      $period = '';
      switch ($this->property['in_pva']) {
        case 'd':
        case 'D':
          $period = ' por Dia.';
          break;
        case 's':
        case 'S':
          $period = ' por Semana.';
          break;
        case 'q':
        case 'Q':
          $period = ' por Quincena.';
          break;
        case 'm':
        case 'M':
          $period = ' por Mes.';
          break;
      }
      update_post_meta($this->postId, 'fave_property_price_postfix', $currencie . $period);
    } else {
      update_post_meta($this->postId, 'fave_property_price_postfix', '');
    }
  }

  public function callApi()
  {
    //La funcion de curl esta hecha dentro de api.inc
    //Funcion de prueba con la api de tokko.
    //En el caso que funcione necesito ver el resto de los posibles filtros que puedo enviar.
    //Filters for de call:
    $data_arr = [
      "current_localization_id" => 0,
      "current_localization_type" => "country",
      "price_from" => 0,
      "price_to" => 999999999,
      "operation_types" => [1, 2, 3],
      "property_types" => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25],
      "currency" => "ANY",
      "filters" => []
    ];
    $data = json_decode(json_encode($data_arr));
    $auth = new TokkoAuth('b87fe1d3b55263138083c53238333311c8046c81');
    // CREATE PROPERTY SEARCH OBJECT
    $search = new TokkoSearch($auth);
    $search->TokkoSearch($auth, $data);
    //order_by=price&limit=20&order=desc&page=1&data='+JSON.stringify(data);
    // ORDER BY, LIMIT, ORDER
    //$search->do_search(500, 'deleted_at');
    $search->do_search(10, 'deleted_at');
    //date_default_timezone_set('UTC');
    $result = $search->get_properties();
    //error_log("API CALL RESULT:" . print_r($result, true));
    // $file = fopen('call1.json', 'w');
    // fwrite($file,print_r($result,true));
    // fclose($file);
    return $result;
  }
}

?>