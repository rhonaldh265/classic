<?php
// mpesa_callback.php - Handles M-Pesa payment callbacks
header('Content-Type: application/json');

// Log the callback for debugging
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'post_data' => file_get_contents('php://input'),
    'get_data' => $_GET
];

// Save to log file
file_put_contents('callback_log.txt', json_encode($logData) . "\n", FILE_APPEND);

// Process the callback if it's a POST request with JSON data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if ($data && isset($data['Body'])) {
        $callbackData = $data['Body']['stkCallback'] ?? null;
        
        if ($callbackData) {
            $checkoutID = $callbackData['CheckoutRequestID'] ?? '';
            $resultCode = $callbackData['ResultCode'] ?? '';
            $resultDesc = $callbackData['ResultDesc'] ?? '';
            
            // Log callback details
            $callbackLog = [
                'checkoutID' => $checkoutID,
                'resultCode' => $resultCode,
                'resultDesc' => $resultDesc,
                'time' => date('Y-m-d H:i:s')
            ];
            
            file_put_contents('payments.log', json_encode($callbackLog) . "\n", FILE_APPEND);
            
            // If payment was successful
            if ($resultCode == 0 && isset($callbackData['CallbackMetadata']['Item'])) {
                $items = $callbackData['CallbackMetadata']['Item'];
                $paymentDetails = [];
                
                foreach ($items as $item) {
                    $paymentDetails[$item['Name']] = $item['Value'] ?? '';
                }
                
                // Save successful payment
                $successLog = [
                    'status' => 'success',
                    'checkoutID' => $checkoutID,
                    'amount' => $paymentDetails['Amount'] ?? '',
                    'receipt' => $paymentDetails['MpesaReceiptNumber'] ?? '',
                    'phone' => $paymentDetails['PhoneNumber'] ?? '',
                    'time' => date('Y-m-d H:i:s')
                ];
                
                file_put_contents('successful_payments.log', json_encode($successLog) . "\n", FILE_APPEND);
            }
        }
    }
}

// Always return success to M-Pesa
echo json_encode([
    'ResultCode' => 0,
    'ResultDesc' => 'Callback received successfully'
]);
?>
