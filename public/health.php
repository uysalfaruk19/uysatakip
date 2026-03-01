<?php
// Health check endpoint — Railway healthcheck
header('Content-Type: application/json');
echo json_encode([
    'status'  => 'ok',
    'service' => 'UYSA ERP',
    'time'    => date('Y-m-d H:i:s'),
]);
