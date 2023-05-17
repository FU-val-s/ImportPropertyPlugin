<?php


function createCustomFields(){

    global $wpdb;
    $table = $wpdb->prefix . "houzez_fields_builder";
    $data = array( 'label' => 'Año de contruccion', 'field_id' => 'anio_construt' , 'type' => 'text' , 'is_search' => 'yes' );
    $wpdb->insert( $table, $data );
}

?>