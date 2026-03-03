<?php
function json_response($status, $data = []) {
    echo json_encode([
        "status" => $status,
        "data" => $data
    ]);
    exit;
}
?>
