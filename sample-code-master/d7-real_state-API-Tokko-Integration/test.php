<?php
require './api.inc';

$data_arr = [
    "current_localization_id"=>0,
    "current_localization_type"=>"country",
    "price_from"=>0,
    "price_to"=>999999999,
    "operation_types"=>[1,2,3],
    "property_types"=>[1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25],
    "currency"=>"ANY",
    "filters"=>[]
  ];
  $data = json_decode(json_encode($data_arr));
  $auth = new TokkoAuth('b87fe1d3b55263138083c53238333311c8046c81');
  // CREATE PROPERTY SEARCH OBJECT
  $search = new TokkoSearch($auth);
  $search->TokkoSearch($auth, $data);
  //order_by=price&limit=20&order=desc&page=1&data='+JSON.stringify(data);
  // ORDER BY, LIMIT, ORDER
  //$search->do_search(500, 'deleted_at');
  $search->do_search( 10, 'deleted_at' );
  date_default_timezone_set('UTC');
  print "ARRANCA \n";
  
  foreach ( $search->get_properties() as $propiedad_obj ) {
    $propiedad = $propiedad_obj->data;
    print_r($propiedad);
  }
  print "TERMINA \n";
  exit();
?>
