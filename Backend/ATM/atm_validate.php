<?php
$data = [
    "trace_number"=>$_POST['trace'],
    "token"=>$_POST['token'],
    "pin"=>$_POST['pin']
];

$response = file_get_contents("http://localhost/Backend/network/acquirer_confirm.php", false,
    stream_context_create([
        "http"=>[
            "method"=>"POST",
            "header"=>"Content-Type: application/json",
            "content"=>json_encode($data)
        ]
    ])
);

$result = json_decode($response,true);

if ($result['status']=="APPROVED") {
    header("Location: atm_dispense.php?trace=".$_POST['trace']."&amount=".$result['data']['amount']);
} else {
    echo "Declined";
}
