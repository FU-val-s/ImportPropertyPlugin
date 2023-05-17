<?php

function getPropertyData() {

    $inm = 'ATM';
    $apiK = 'XFBIKJWXLEJLZGIP6NVDZOYTD';
    // FIN - DATOS INMOBILIARIA //
    $url='http://xintel.com.ar/api/';
    $data = array(
    'json' => 'fichas.propiedades',
    'inm' => $inm,
    'apiK' => $apiK,
    'id' => 3178
    //'utf8decode' => $apiK,
    );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch,CURLOPT_TIMEOUT, 15);
    $result = json_decode(curl_exec($ch));
    curl_close($ch);

    //error_log(print_r($result,true));
    return $result;
}

?>