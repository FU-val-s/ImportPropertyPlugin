<?php
require './api.php';

class Call {
  private $auth;
  public function __construct() {
    $this->auth = new TokkoAuth('b87fe1d3b55263138083c53238333311c8046c81');
  }
  public function api() {
    $data_arr = [
      "current_localization_id" => 0,
      "current_localization_type" => "country",
      "price_from" => 0,
      "price_to" => 999999999,
      "operation_types" => [1, 2, 3],
      "property_types" => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25],
      "currency" => "ANY",
      "filters" => [],
    ];
    $_REQUEST["page"] = 3;
    $data = json_decode(json_encode($data_arr));
    // CREATE PROPERTY SEARCH OBJECT
    $search = new TokkoSearch($this->auth);
    $search->TokkoSearch($this->auth, $data);
    //order_by=price&limit=20&order=desc&page=1&data='+JSON.stringify(data);
    // ORDER BY, LIMIT, ORDER
    //$search->do_search(500, 'deleted_at');
    $search->do_search(3, 'deleted_at');
    date_default_timezone_set('UTC');
    print "ARRANCA \n";
    print_r($search->get_properties());
    $info = $search->get_properties();
    $file = fopen('prueba.json', 'w');
    fwrite($file,print_r($info,true));
    fclose($file);
    //$ret = file_put_contents("prueba.json",print_r(json_encode($search->get_properties())));
    // foreach ( $search->get_properties() as $propiedad_obj ) {
    //   $propiedad = $propiedad_obj->data;
    //   print_r($propiedad);
    // }
    print "TERMINA \n";
    exit();
  }
  public function prop() {
    print "GETTING PROP: ".$this->auth->key;
    $property = new TokkoProperty('reference_code', '587', $this->auth);
    print_r($property);
  }
}

$result = new Call;
$result->api();
?>
