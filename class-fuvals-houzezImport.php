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
    error_log("SET_STATUS FOR: " . $this->property['reference_code'] . "\n");
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
      'post_content' => html_entity_decode($this->property['rich_description']),
      'post_status' => $status,
      'post_type' => $type,
      'post_author' => $authorId,
    );
    //Create property
    $this->postId = wp_insert_post($postData, true);
    error_log('created property: ' . $this->postId);
    return $this->postId;
  }
  //
  public function property_details($id)
  {
    $data = array_merge(
      $this->apiData,
      array(
        'json' => 'fichas.propiedades',
        'reference_code' => $id
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
  public function process_property($ficha, $minval = false, $update = true)
  {
    //$ficha is only one property - schema: Array[0][data]->data of the property
    global $wpdb;
    $table_houzez_data = $wpdb->prefix . "postmeta";
    error_log("Processing property " . $ficha['reference_code']);
    //$apiData = $this->property_details($ficha);
    error_log("Detalis propiedad fetched ");
    if (isset($ficha)) {
      $this->property = $ficha;
      //Property images array
      $propertyImg = $ficha['photos'];
      //Property agent array
      $propertyAgent = $ficha['producer'];
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
      $postIdQ = $wpdb->get_results("SELECT post_id FROM $table_houzez_data WHERE meta_key = 'fave_property_id' and meta_value = '" . $this->property['reference_code'] . "'");
      //Check if property is active
      // if ($this->property['in_int'] == 'True' && (empty($this->property['in_esi']) || $this->property['in_esi'] == 'N')) {
      //   if ($minval && (empty($this->property['in_val']) || $this->property['in_val'] < $minval)) {
      //     error_log("Skipping Property MIN VAL: " . $this->property['in_val']);
      //     return;
      //   }
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
        update_post_meta($this->postId, 'fave_property_id', $this->property['reference_code']);
        //Change title or description
        $data = array(
          'ID' => $this->postId,
          'post_title' => $ficha['publication_title'],
          'post_content' => html_entity_decode($ficha['description']),
        );
        wp_update_post($data);
        error_log("Actulizados título y desc:" . $this->postId);
        if (!$update) {
          error_log("Do not update, continue with next");
          return;
        }
      }
      //ASSIGN AGENT
      error_log("Assigning agent " . $propertyAgent['name']);
      //$this->agent = $propertyAgent['name'];
      $this->assign_agent($propertyAgent);
      //PROPERTY ID
      update_post_meta($this->postId, 'fave_property_id', $this->property['reference_code']);
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
      //IS FEATURED PROPERTY
      if (!empty($this->property['is_starred_on_web'])) {
        update_post_meta($this->postId, 'fave_featured', $this->property['is_starred_on_web']);
      }
      //Add video
      // if (!empty($this->property['videos']))
      //   update_post_meta($this->postId, 'fave_video_url', $this->property['in_vid']);
      update_post_meta($this->postId, 'fave_property_size', $ficha['roofed_surface']);
      update_post_meta($this->postId, 'fave_property_land', $ficha['total_surface']);
      update_post_meta($this->postId, 'fave_property_land_postfix', $ficha['surface_measurement']);
      update_post_meta($this->postId, 'fave_property_size_prefix', $ficha['surface_measurement']);
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
      update_post_meta($this->postId, 'fave_property_map_address', $this->property['real_address']);
      //FEATURES
      //error_log("FEATURES: ".print_r(json_decode(json_encode($propertyFeatures),true),true));
      $this->setPropertyTerms($propertyFeatures, 'property_feature');
      $this->setPropertyTerms($extraFeatures, 'property_feature', false, 'group_name');
      error_log("Create property features updated");
      //LOAD IMAGES PACK FOR POST
      $imageList = $this->getImageUrl($propertyImg);
      $thumb = array_shift($imageList);
      //Do not update for now
      if ($new) {
        loadThumbProperty($this->postId, $thumb);
        loadImgProperty($this->postId, $imageList);
        //oldLoadImgProperty($this->postId, $this->property['img_princ'], $propertyImg, $this->property['in_fic']);
      } elseif ($this->conciliateImages) {
        //if ( !fuvalsHI_conciliateThumb($this->postId, $this->property['img_princ']) ) {
        error_log('ERRORES en thumb... reloading');
        if (!loadThumbProperty($this->postId, $thumb, true)) {
          error_log('ERRORES IN RELOADING THUMB');
        }
        //}
        //if ( !fuvalsHI_conciliateImages($this->postId, $propertyImg, $this->property['img_princ']) ) {
        error_log('ERRORS in images... reloading');
        if (!loadImgProperty($this->postId, $imageList, true)) {
          error_log('ERRORS IN RELOADING IMGS');
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
  public function assign_agent2($agent){
    global $wpdb;
    $table_houzez_data = $wpdb->prefix . "postmeta";
    $postIdA = $wpdb->get_results("SELECT post_id FROM $table_houzez_data WHERE meta_key = 'fave_agent_email' and mate_value = '" . $agent['email'] . "'");
    if(empty($postIdA)){
      //CREATE AND ASSIGN AGENT
      $postData = array(
        'post_date' => date('Y-m-d h:i:s'),
        'post_date_gmt' => date('Y-m-d h:i:s'),
        'post_title' => $agent['name'],
        'post_status' => 'publish',
        'post_type' => 'houzez_agent',
        'post_author' => '1',
      );
      $agentPostId = wp_insert_post($postData, true);
      update_post_meta($agentPostId, 'fave_agent_email', $agent['email']);
      update_post_meta($agentPostId, 'fave_agent_mobile', $agent['phone']);
      update_post_meta($agentPostId, 'fave_agent_logo', $agent['picture']);
      //Assign
      update_post_meta($this->postId, 'fave_agent_display_option', 'agent_info');
      update_post_meta($this->postId, 'fave_agents', $agent['id']);
    }else{
      //ONLY ASSIGN
      update_post_meta($this->postId, 'fave_agent_display_option', 'agent_info');
      update_post_meta($this->postId, 'fave_agents', $postIdA);
    }
    error_log("AGENTE ASIGNADO");
  }
  public function assign_agent($agentF)
  {
    try {
      if ($this->agent) {
        error_log("Adding agent:" . $this->agent);
        update_post_meta($this->postId, 'fave_agent_display_option', 'agent_info');
        update_post_meta($this->postId, 'fave_agents', $this->agent);
      } else {
        $agent_name = $agentF['name'];
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
    } catch (\Exception $e) {
      error_log("ERROR IN AGENT:\n" . $e->getMessage());
    }

  }
  /*
   *
   */
  public function loadCustomFields()
  {
    error_log("Load Custom fields");
    update_post_meta($this->postId, 'fave_antiguedad', $this->property['age']);
    update_post_meta($this->postId, 'fave_orientacion', $this->property['orientation']);
    update_post_meta($this->postId, 'fave_estado', $this->property['property_condition']);
    update_post_meta($this->postId, 'fave_nro-plant', $this->property['floors_amount']);
    update_post_meta($this->postId, 'fave_superficie', $this->property['surface']);
    update_post_meta($this->postId, 'fave_show-price', $this->property['web_price']);
  }

  public function loadCustomPrices($op)
  {
    error_log("LOAD RENT PRICES");
    foreach ($op['prices'] as $price) {
      if ($price['period'] == "1ra quincena de enero") {
        update_post_meta($this->postId, 'fave_first-half-jan', $price['price']);
        update_post_meta($this->postId, 'fave_first-half-jan-ref', $price['price']);
        update_post_meta($this->postId, 'fave_property-sec-price', $price['price']);
      }
      if ($price['period'] == "2da quincena de enero") {
        update_post_meta($this->postId, 'fave_second-half-jan', $price['price']);
        update_post_meta($this->postId, 'fave_second-half-jan-ref', $price['price']);
      }
      if ($price['period'] == "1ra quincena de febrero") {
        update_post_meta($this->postId, 'fave_first-half-feb', $price['price']);
        update_post_meta($this->postId, 'fave_first-half-feb-ref', $price['price']);
      }
      if ($price['period'] == "2da quincena de febrero") {
        update_post_meta($this->postId, 'fave_second-half-feb', $price['price']);
        update_post_meta($this->postId, 'fave_second-half-feb-ref', $price['price']);
      }
      if ($price['period'] == "Enero") {
        update_post_meta($this->postId, 'fave_alq-all-jan', $price['price']);
        update_post_meta($this->postId, 'fave_alq-all-jan-ref', $price['price']);
      }
      if ($price['period'] == "Febrero") {
        update_post_meta($this->postId, 'fave_alq-all-feb', $price['price']);
        update_post_meta($this->postId, 'fave_alq-all-feb-ref', $price['price']);

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
    $search->do_search(3, 'deleted_at');
    $result = $search->get_properties();
    return $result;
  }
}

?>
