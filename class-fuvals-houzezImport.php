<?php
// /wp-content/plugins/imporHouzezPlugin-main/class-fuvals-houzezImport.php
class Fuvals_houzezImport
{
  public int $postId;
  //Connection data
  public $apiUser = 'ATM';
  public $apiKey = 'XFBIKJWXLEJLZGIP6NVDZOYTD';
  // FIN - DATOS INMOBILIARIA //
  public $apiUrl = 'http://xintel.com.ar/api/';
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
      'property_city',
      'property_area'
    ];
    foreach ($taxonomies as $taxonomy) {
      $ops_objs = get_terms($taxonomy, array(
        'hide_empty' => false,
      ));
      foreach ($ops_objs as $op) {
        $this->$taxonomy[$op->term_id] = $op->slug;
      }
    }
  }
  //
  public function clean_duplicates()
  {
  }
  //
  public function setOperationType($propOp)
  {
    switch ($propOp) {
      case 'A':
        $tid = array_search('alquileres-anuales', $this->property_status);
        break;
      case 'V':
        $tid = array_search('venta', $this->property_status);
        break;
      case 'T':
        $tid = array_search('alquileres-temporarios', $this->property_status);
        break;
      case 'B':
        $tid = [array_search('alquileres-temporarios', $this->property_status), array_search('venta', $this->property_status)];
        break;
      default:
        $tid = [array_search('alquileres-anuales', $this->property_status), array_search('venta', $this->property_status)];
        break;
    }
    wp_set_object_terms($this->postId, $tid, 'property_status');
  }
  //
  public function setPropertyTerms($terms, $taxonomy, $reload = false)
  {
    $termIds = [];
    error_log('Propiedad Setting terms: ' . $taxonomy);
    if (!empty($terms)) {
      $terms = is_array($terms) ? $terms : [$terms];
      if ( $reload ) {
        $term_objs = wp_get_object_terms($this->postId, $taxonomy);
        foreach ($term_objs as $term) {
          wp_remove_object_terms( $this->postId, $term->term_id, $taxonomy );
        }
      }
      foreach ($terms as $term) {
        $term = html_entity_decode($term);
        $termSlug = sanitize_title($term);
        if (!($fid = array_search($termSlug, $this->$taxonomy))) {
          error_log('Adding term: ' . $term);
          //error_log(print_r($propFeat, true));
          $id = wp_insert_term($term, $taxonomy);
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
    }
    else {
      error_log('Propiedad Setting terms NO terms: ' . $taxonomy);
    }
    return;
  }
  //
  public function create_property()
  {
    //static values for test
    $type = 'property';
    $status = 'publish';
    $authorId = '1'; // admin wordpress
    //Edit post information
    $postData = array(
      'post_date' => date('Y-m-d h:i:s'),
      'post_date_gmt' => date('Y-m-d h:i:s'),
      'post_title'    => $this->property['titulo'],
      'post_content'  => html_entity_decode($this->property['in_obs']),
      'post_status'   => $status,
      'post_type'     => $type,
      'post_author'   => $authorId,
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
  public function get_properties()
  {
    $data = array_merge(
      $this->apiData,
      ['json' => 'fichas.destacadas']
    );
    return $this->callApi($data);
  }
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
  public function get_last_properties($i, $minval = false) {
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
    if ( $minval != false ) {
      $data['valor_minimo'] = $minval;
    }
    return $this->callApi($data);
  }
  public function process_property($ficha, $minval = false) {
    global $wpdb;
    $table_houzez_data = $wpdb->prefix . "postmeta";
    error_log("Procesando propiedad " . $ficha);
    //Valido si ya existe
    $apiData = $this->property_details($ficha);
    error_log("Detalis propiedad fetched ");
    if ( isset($apiData['resultado']['ficha'][0]) ) {
      $this->property = $apiData['resultado']['ficha'][0];
      //Property images array
      $propertyImg = $apiData['resultado']['img'];
      if ( isset($apiData['resultado']['plano']) && !empty($apiData['resultado']['plano']) )
        $propertyImg[] = $apiData['resultado']['plano'];
      //Property features array
      $propertyFeatures = $apiData['resultado']['caracteristicas_generales_personalizadas'];
      //Get property
      error_log("Getting property in DB: ".$this->property['in_fic']);
      $postIdQ = $wpdb->get_results("SELECT post_id FROM $table_houzez_data WHERE meta_key = 'fave_property_id' and meta_value = '" . $this->property['in_fic'] . "'");
      //Check if property is active
      if ( $this->property['in_int'] == 'True' && ( empty($this->property['in_esi']) || $this->property['in_esi'] == 'N' ) ) {
        if ( $minval && ( empty($this->property['in_val']) || $this->property['in_val'] < $minval ) ) {
          error_log("Skipping Property MIN VAL: ".$this->property['in_val']);
          return;
        }
        error_log("Processing Property");
        //error_log(print_r($posts,true));
        if (empty($postIdQ)) {
          $new = true;
          //Create property
          error_log("Create property");
          $this->postId = $this->create_property();
        }
        else {
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
            'post_title' => $this->property['titulo'],
            'post_content' => html_entity_decode($this->property['in_obs']),
          );
          wp_update_post( $data );
          error_log("Actulizados título y desc:" . $this->postId);
        }
        //Assign agent
        error_log("Asignando agente: $this->agent");
        $this->assign_agent();
        //PROPERTY ID
        update_post_meta($this->postId, 'fave_property_id', $this->property['in_fic']);
        //PROPERTY TYPE
        $this->setPropertyTerms($this->property['in_tip'], 'property_type');
        //PROPERTY CUSTOM FIELDS
        $this->loadCustomFields();
        //Set PROPERTY OPERATION TYPE
        $this->setOperationType($this->property['in_ope']);
        //PRICE with currencie already set.
        $this->update_price();
        //PRICE AND CURRENCIE FOR RENT
        error_log("Create property: main data updated");
        //ROOMS AND SIZES
        update_post_meta($this->postId, 'fave_property_bedrooms', $this->property['ti_dor']);
        update_post_meta($this->postId, 'fave_property_bathrooms', $this->property['in_bao']);
        update_post_meta($this->postId, 'fave_property_year', $this->property['in_anio']);
        update_post_meta($this->postId, 'fave_property_rooms', $this->property['ambientes']);
        update_post_meta($this->postId, 'fave_property_garage', $this->property['garage']);
        //Add video
        if ( !empty($this->property['in_vid']) )
        update_post_meta($this->postId, 'fave_video_url', $this->property['in_vid']);
        //Type of unity conditional
        if ( !empty($this->property['tipo_medida']) || $this->property['in_tip'] == 'Campo' || $this->property['in_tip'] == 'Chacra' ) {
          if ( $this->property['in_tip'] == 'Campo' || $this->property['in_tip'] == 'Chacra' ) {
            update_post_meta($this->postId, 'fave_property_land_postfix', 'HAS');
            update_post_meta($this->postId, 'fave_property_size_prefix', 'HAS');
          }
          else {
            update_post_meta($this->postId, 'fave_property_land_postfix', $this->property['tipo_medida']);
            update_post_meta($this->postId, 'fave_property_size_prefix', 'm²');
          }
        }else{
          update_post_meta($this->postId, 'fave_property_land_postfix', 'm²');
          update_post_meta($this->postId, 'fave_property_size_prefix', 'm²');
        }
        update_post_meta($this->postId, 'fave_property_size', $this->property['in_sto']);
        update_post_meta($this->postId, 'fave_property_land', $this->property['in_sut']);
        error_log("Create property: sizes updated");
        //ADDRESS AND LOCATION DATA
        $this->setPropertyTerms($this->property['in_pai'], 'property_country');
        //$this->setPropertyTerms($this->property['?'], 'property_state');
        $this->setPropertyTerms($this->property['in_loc'], 'property_city', true);
        $this->setPropertyTerms($this->property['in_bar'], 'property_area', true);
        update_post_meta($this->postId, 'fave_property_location', $this->property['in_coo']);
        error_log("Create property location updated");
        // MOSTRAR MAPA
        update_post_meta($this->postId, 'fave_property_map', '1');
        //update_post_meta($this->postId, 'houzez_geolocation_lat', $this->property['dormitorios']);
        //update_post_meta($this->postId, 'houzez_geolocation_long', $this->property['dormitorios']);
        //update_post_meta($this->postId, 'fave_property_zip', $this->property['dormitorios']);
        //Necesito el pais, codigo postal y departamento.
        //update_post_meta($this->postId, 'fave_property_map_address', $this->property['dormitorios']);
        //MOSTRAR STREET VIEW
        //update_post_meta($this->postId, 'fave_property_map_street_view', $this->property['dormitorios']);
        //VIRTUAL TOUR 360
        if ( !empty($this->property['in_tou']) ) {
          update_post_meta($this->postId, 'fave_virtual_tour', "<iframe src='".$this->property['in_tou']."'></iframe>");
        }

        //FEATURES
        $this->setPropertyTerms($propertyFeatures, 'property_feature');
        error_log("Create property features updated");
        //LOAD IMAGES PACK FOR POST
        //Do not update for now
        if ($new) {
          loadThumbProperty($this->postId, $this->property['img_princ']);
          loadImgProperty($this->postId, $propertyImg);
          //oldLoadImgProperty($this->postId, $this->property['img_princ'], $propertyImg, $this->property['in_fic']);
        }
        elseif ( $this->conciliateImages ) {
          //if ( !fuvalsHI_conciliateThumb($this->postId, $this->property['img_princ']) ) {
            error_log('ERRORES en thumb... reloading');
            if ( !loadThumbProperty($this->postId, $this->property['img_princ'], true) ) {
              error_log('ERRORES IN RELOADING THUMB');
            }
          //}
          //if ( !fuvalsHI_conciliateImages($this->postId, $propertyImg, $this->property['img_princ']) ) {
            error_log('ERRORES in images... reloading');
            if ( !loadImgProperty($this->postId, $propertyImg, true) ) {
              error_log('ERRORES IN RELOADING IMGS');
            }
          //}
        }
        wp_update_post( ['ID' => $this->postId] );
        error_log('TERMINAMOS DE LEVANTAR LOS DATOS E IMAGENES');
      }
      else {
        error_log("La propiedad no está para publicar: $ficha");
        // La borramos si no está
        if ( !empty($postIdQ) ) {
          foreach ($postIdQ as $post) {
            wp_delete_post($post->post_id);
            error_log("Se ha eliminado el post:" . $post->post_id);
          }
        }
      }
    }
    else {
      error_log("NO SE PUDO OBTENER DETALLE DE LA FICHA: $ficha");
    }
  }
  //
  public function assign_agent() {
    if ($this->agent) {
      error_log("Adding agent:" . $this->agent);
      update_post_meta($this->postId, 'fave_agent_display_option', 'agent_info');
      update_post_meta($this->postId, 'fave_agents', $this->agent);
    }
    else {
      $agent_name = $this->property['vendedor_nombre'].' '.$this->property['vendedor_apellido'];
      //$agent_name = 'Fernando Uval';
      $args = array(
      'post_type' => 'houzez_agent',
      'post_status' => 'publish',
      'posts_per_page' => 1,
      'title' => $agent_name,
      );
      $loop = new WP_Query( $args );

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
  public function loadCustomFields() {
    error_log("Load Custom fields");
    update_post_meta($this->postId, 'fave_anio_construccion', $this->property['in_anio']);
    update_post_meta($this->postId, 'fave_antiguedad', $this->property['in_ant']);
    update_post_meta($this->postId, 'fave_orientacion', $this->property['orientacion']);
    update_post_meta($this->postId, 'fave_amueblado', $this->property['in_amu']);
    update_post_meta($this->postId, 'fave_cant_pisos', $this->property['in_npi']);
    update_post_meta($this->postId, 'fave_estado', $this->property['in_esa']);
    update_post_meta($this->postId, 'fave_precio_alq', $this->property['in_vaa']);
    update_post_meta($this->postId, 'fave_tipo_prop', $this->property['in_tpr']);
    update_post_meta($this->postId, 'fave_ubicacion', $this->property['ubicacion']);
    update_post_meta($this->postId, 'fave_categoria', $this->property['in_eco']);
    if ( !empty($this->property['in_exp']) ) {
      $moe = $this->property['in_moe'] == 'D' ? 'USD' : '$';
      update_post_meta($this->postId, 'fave_expensas', $this->property['in_exp'].' '.$moe);
    }
    if ( !empty($this->property['in_imp']) ) {
      $impuesto = $this->property['moneda_impuesto'] == 'D' ? 'USD' : '$';
      update_post_meta($this->postId, 'fave_impuesto', $this->property['in_imp'].' '.$impuesto);
    }
    update_post_meta($this->postId, 'fave_emprendimiento', $this->property['in_edi']);
    update_post_meta($this->postId, 'fave_cant_asc', $this->property['in_asc']);
    update_post_meta($this->postId, 'fave_sup_cub', $this->property['in_cub'].'m²');
    update_post_meta($this->postId, 'fave_sup_semi_cub', $this->property['sup_semicubierta'].'m²');
    update_post_meta($this->postId, 'fave_nro_plant', $this->property['in_npi']);
    //update_post_meta($this->postId, 'fave_estado_of', $this->property['in_ale']);
    update_post_meta($this->postId, 'fave_zonific', $this->property['in_zon']);
    update_post_meta($this->postId, 'fave_fot', $this->property['in_fot']);
    update_post_meta($this->postId, 'fave_cant_nav', $this->property['in_lin']);
    update_post_meta($this->postId, 'fave_usos_limt', $this->property['in_uso']);
    update_post_meta($this->postId, 'fave_tipo_piso', $this->property['in_arq']);
    update_post_meta($this->postId, 'fave_ideal', $this->property['in_ide']);
    update_post_meta($this->postId, 'fave_rubro', $this->property['in_rub']);
    update_post_meta($this->postId, 'fave_frente', $this->property['in_pil']);
    $activities = ['G' => 'Ganadería','A' => 'Agricultura','P' => 'Porcinos','C' => 'Apicultura','T' => 'Turístico','H' => 'Haras', 'F' => 'Forestación'];
    delete_post_meta($post->ID, 'fave_actividades');
    for ($i=1; $i <= 3; $i++) {
      $act_sum = $this->property['in_ac'.$i];
      if ( !empty( $act_sum ) ) {
        $activity = isset($activities[$act_sum]) ? $activities[$act_sum] : $act_sum;
        add_post_meta($this->postId, 'fave_actividades', $activity);
      }
    }
    update_post_meta($this->postId, 'fave_valor_ha', $this->property['in_mt2']);
    update_post_meta($this->postId, 'fave_codigo_camp', $this->property['in_con']);
    update_post_meta($this->postId, 'fave_gas', $this->property['in_gas']);
    if ( $this->property['in_tip'] == 'Departamento' || $this->property['in_tip'] == 'Apartamento' || $this->property['in_tip']=='Casa' ) {
      update_post_meta($this->postId, 'fave_aire_acondicionado', $this->property['in_paq']);
    }
    //update_post_meta($this->postId, 'fave_riego', $this->property['in_rie']);

    //Fields that might be better to place as features
    $othersFeatures = [];
    if (!empty($this->property['in_dep'])) {
      $othersFeatures[] = 'Dormitorio de servicio';
    }
    if (!empty($this->property['in_agu'])) {
      $othersFeatures[] = 'Agua Caliente';
    }
    if (!empty($this->property['in_pav'])) {
      $othersFeatures[] = 'Pavimentado';
    }
    if (!empty($this->property['in_clo'])) {
      $othersFeatures[] = 'Cloaca';
    }
    if (!empty($this->property['in_lin'])) {
      $othersFeatures[] = 'Linea Telefonica';
    }
    if (!empty($this->property['in_parking'])) {
      $othersFeatures[] = 'Parking';
    }
    $this->setPropertyTerms($othersFeatures, 'property_feature');
  }
  /*
  */
  public function update_price() {
    if ( !empty($this->property['in_val']) ){
      error_log("UPDATING sale PRICE: $this->postId --> ".$this->property['in_val']);
      update_post_meta($this->postId, 'fave_property_price', $this->property['in_val']);
      update_post_meta($this->postId, 'fave_property_sec_price', $this->property['in_vaa']);
    }
    else {
      error_log("UPDATING rent PRICE: $this->postId --> ".$this->property['in_vaa']);
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
    }
    else {
      update_post_meta($this->postId, 'fave_property_price_postfix', '');
    }
  }
  private function callApi($data)
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->apiUrl);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    //error_log('API RESULT: ' . $result);
    return $result;
  }
}
?>
