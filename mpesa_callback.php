<?php
// mpesa_callback.php - Handles M-Pesa payment callbacks
header('Content-Type: application/json');

// Log the callback data
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'raw_input' => file_get_contents('php://input'),
    'server_data' => $_SERVER
];

// Save to log file
file_put_contents('mpesa_callback_log.txt', 
    json_encode($logData, JSON_PRETTY_PRINT) . "\n---\n", 
    FILE_APPEND
);

// Process JSON input if present
$input = file_get_contents('php://input');
if (!empty($input)) {
    $data = json_decode($input, true);
    
    if ($data) {
        // Check for STK callback
        if (isset($data['Body']['stkCallback'])) {
            $callback = $data['Body']['stkCallback'];
            $checkoutID = $callback['CheckoutRequestID'] ?? 'UNKNOWN';
            $resultCode = $callback['ResultCode'] ?? '';
            $resultDesc = $callback['ResultDesc'] ?? '';
            
            // Log callback details
            $callbackLog = [
                'checkout_id' => $checkoutID,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'callback_data' => $callback,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            file_put_contents('callback_details.log', 
                json_encode($callbackLog, JSON_PRETTY_PRINT) . "\n---\n", 
                FILE_APPEND
            );
            
            // If payment was successful
            if ($resultCode == 0 && isset($callback['CallbackMetadata']['Item'])) {
                $items = $callback['CallbackMetadata']['Item'];
                $paymentDetails = [];
                
                foreach ($items as $item) {
                    $paymentDetails[$item['Name']] = $item['Value'] ?? '';
                }
                
                // Save successful payment
                $successLog = [
                    'status' => 'SUCCESS',
                    'checkout_id' => $checkoutID,
                    'amount' => $paymentDetails['Amount'] ?? '',
                    'mpesa_receipt' => $paymentDetails['MpesaReceiptNumber'] ?? '',
                    'phone' => $paymentDetails['PhoneNumber'] ?? '',
                    'transaction_date' => $paymentDetails['TransactionDate'] ?? '',
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                
                file_put_contents('successful_payments.log', 
                    json_encode($successLog, JSON_PRETTY_PRINT) . "\n", 
                    FILE_APPEND
                );
            }
        }
    }
}

// Always return success to M-Pesa
echo json_encode([
    'ResultCode' => 0,
    'ResultDesc' => 'Callback received and processed successfully'
]);
?>
