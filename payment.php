<?php
// payment.php - Main payment processing file
session_start();
header('Content-Type: application/json');

// Initialize session variables
if (!isset($_SESSION['transactions'])) {
    $_SESSION['transactions'] = [];
}

// M-Pesa Sandbox Credentials
$BusinessShortCode = "174379";
$Passkey = "bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919";
$ConsumerKey = "XeVkJwRzSl9fUfxhdgCz7aBND1TIHokCRPqSIGqIKz0q4pxe";
$ConsumerSecret = "hLNzHL8CqKQarLLf1JL14XAVAc7CjDcHncIlpZADcXuFyKTjUP0QwZdrQfO9XGMZ";

// Test phone numbers for sandbox with correct PINs
$testPhones = [
    '254708374149' => '123456', // Correct PIN for test number
    '254727016623' => '0000',   // Correct PIN for your number
];

// Handle payment status check (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['checkoutID'])) {
    $checkoutID = $_GET['checkoutID'];
    
    // For sandbox - simulate payment confirmation with PIN validation
    if (isset($_SESSION['checkout_' . $checkoutID])) {
        $paymentData = $_SESSION['checkout_' . $checkoutID];
        $timeSinceRequest = time() - $paymentData['timestamp'];
        
        // Get entered PIN and correct PIN
        $enteredPin = $paymentData['simulated_pin'] ?? '';
        $correctPin = $testPhones[$paymentData['phone']] ?? '123456';
        
        // Scenario 1: Wrong PIN entered (fails after 5 seconds)
        if ($timeSinceRequest > 5 && $enteredPin !== $correctPin) {
            echo json_encode([
                'ResultCode' => '1',
                'ResultDesc' => 'The initiator information is invalid.',
                'CheckoutRequestID' => $checkoutID
            ]);
            
            // Mark as failed
            $_SESSION['checkout_' . $checkoutID]['status'] = 'failed';
            $_SESSION['checkout_' . $checkoutID]['failure_reason'] = 'Invalid PIN';
            
        } 
        // Scenario 2: Correct PIN entered (succeeds after 10 seconds)
        else if ($timeSinceRequest > 10 && $enteredPin === $correctPin) {
            // Mark as completed
            $paymentData['status'] = 'completed';
            $_SESSION['checkout_' . $checkoutID] = $paymentData;
            
            // Generate receipt number
            $receiptNumber = 'MP' . time() . rand(1000, 9999);
            
            // Add to transactions
            $_SESSION['transactions'][] = [
                'checkoutID' => $checkoutID,
                'name' => $paymentData['name'],
                'phone' => $paymentData['phone'],
                'amount' => $paymentData['amount'],
                'time' => date('Y-m-d H:i:s'),
                'receipt' => $receiptNumber,
                'status' => 'completed'
            ];
            
            echo json_encode([
                'ResultCode' => 0,
                'ResultDesc' => 'The service request is processed successfully.',
                'MerchantRequestID' => 'SIM' . time(),
                'CheckoutRequestID' => $checkoutID,
                'Amount' => $paymentData['amount'],
                'MpesaReceiptNumber' => $receiptNumber,
                'TransactionDate' => date('YmdHis'),
                'PhoneNumber' => $paymentData['phone']
            ]);
            
        } 
        // Scenario 3: Insufficient balance for large amounts
        else if ($timeSinceRequest > 5 && $paymentData['amount'] > 50000) {
            echo json_encode([
                'ResultCode' => '2001',
                'ResultDesc' => 'The balance is insufficient for the transaction',
                'CheckoutRequestID' => $checkoutID
            ]);
            
            $_SESSION['checkout_' . $checkoutID]['status'] = 'failed';
            $_SESSION['checkout_' . $checkoutID]['failure_reason'] = 'Insufficient balance';
            
        }
        // Scenario 4: Still processing
        else {
            echo json_encode([
                'ResultCode' => '1032',
                'ResultDesc' => 'Request processing in progress'
            ]);
        }
    } else {
        // For testing without session data
        echo json_encode([
            'ResultCode' => '1037',
            'ResultDesc' => 'Transaction cancelled by user'
        ]);
    }
    exit;
}

// Handle payment initiation (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Get form data
    $Amount = $_POST['amount'] ?? '';
    $PhoneNumber = $_POST['phone'] ?? '';
    $CustomerName = $_POST['name'] ?? '';
    $simulatedPin = $_POST['pin'] ?? '123456';
    $basketItems = isset($_POST['basket']) ? json_decode($_POST['basket'], true) : null;
    
    // Validation
    if (empty($Amount) || empty($PhoneNumber) || empty($CustomerName)) {
        echo json_encode([
            'ResponseCode' => 1,
            'ResponseDescription' => 'Please fill all fields: name, phone, and amount'
        ]);
        exit;
    }
    
    if ($Amount < 10) {
        echo json_encode([
            'ResponseCode' => 1,
            'ResponseDescription' => 'Minimum amount is KES 10'
        ]);
        exit;
    }
    
    // Format phone number
    if (strpos($PhoneNumber, '0') === 0) {
        $PhoneNumber = '254' . substr($PhoneNumber, 1);
    } elseif (strpos($PhoneNumber, '+254') === 0) {
        $PhoneNumber = substr($PhoneNumber, 1);
    }
    
    // Validate phone format
    if (!preg_match('/^254[17]\d{8}$/', $PhoneNumber)) {
        echo json_encode([
            'ResponseCode' => 1,
            'ResponseDescription' => 'Invalid phone number. Use format: 07XXXXXXXX or 2547XXXXXXXX'
        ]);
        exit;
    }
    
    // For sandbox, validate against test numbers
    if (!array_key_exists($PhoneNumber, $testPhones)) {
        echo json_encode([
            'ResponseCode' => 1,
            'ResponseDescription' => 'Sandbox: Use test number 254708374149'
        ]);
        exit;
    }
    
    // Get correct PIN for this phone
    $correctPin = $testPhones[$PhoneNumber];
    
    // Check if entered PIN is correct
    $pinStatus = ($simulatedPin === $correctPin) ? 'correct' : 'incorrect';
    
    // Generate timestamp and password
    $Timestamp = date('YmdHis');
    $Password = base64_encode($BusinessShortCode . $Passkey . $Timestamp);
    
    // Get access token
    $token = getAccessToken();
    if (!$token) {
        echo json_encode([
            'ResponseCode' => 1,
            'ResponseDescription' => 'Failed to connect to M-Pesa service'
        ]);
        exit;
    }
    
    // Prepare STK push data
    $AccountReference = "TowettCollection";
    $TransactionDesc = "Clothing Purchase";
    
    // Add item count to description if available
    if ($basketItems && is_array($basketItems)) {
        $itemCount = count($basketItems);
        $TransactionDesc = "Clothes: {$itemCount} item(s)";
    }
    
    // Send STK push
    $response = sendSTKPush($token, $BusinessShortCode, $Password, $Timestamp, 
                           $Amount, $PhoneNumber, $AccountReference, $TransactionDesc);
    
    $responseData = json_decode($response, true);
    
    if (isset($responseData['ResponseCode']) && $responseData['ResponseCode'] == "0") {
        $checkoutID = $responseData['CheckoutRequestID'];
        
        // Store in session with PIN validation info
        $_SESSION['checkout_' . $checkoutID] = [
            'name' => $CustomerName,
            'phone' => $PhoneNumber,
            'amount' => $Amount,
            'timestamp' => time(),
            'status' => 'pending',
            'basket_items' => $basketItems,
            'simulated_pin' => $simulatedPin,
            'pin_status' => $pinStatus,
            'correct_pin' => $correctPin
        ];
        
        // Log the payment attempt
        file_put_contents('payment_attempts.log', 
            date('Y-m-d H:i:s') . " - " .
            "Phone: $PhoneNumber, " .
            "Amount: $Amount, " .
            "PIN: $simulatedPin, " .
            "Status: $pinStatus, " .
            "CheckoutID: $checkoutID\n",
            FILE_APPEND
        );
        
        echo json_encode([
            'ResponseCode' => '0',
            'ResponseDescription' => 'STK Push sent successfully! Check your phone.',
            'CheckoutRequestID' => $checkoutID,
            'CustomerMessage' => 'Please check your phone for M-Pesa prompt',
            'TestNote' => "Testing: PIN entered is $pinStatus. Use PIN: $correctPin for success."
        ]);
        
    } else {
        $errorMsg = $responseData['errorMessage'] ?? 
                   ($responseData['ResponseDescription'] ?? 'Unknown error occurred');
        
        echo json_encode([
            'ResponseCode' => '1',
            'ResponseDescription' => 'Failed to initiate payment: ' . $errorMsg
        ]);
    }
    
    exit;
}

// Function to get access token
function getAccessToken() {
    global $ConsumerKey, $ConsumerSecret;
    
    $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($ConsumerKey . ':' . $ConsumerSecret)
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        return $data['access_token'] ?? false;
    }
    
    return false;
}

// Function to send STK push
function sendSTKPush($token, $businessCode, $password, $timestamp, 
                    $amount, $phone, $accountRef, $transactionDesc) {
    
    $url = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    
    // Use a test callback URL for sandbox
    $callbackUrl = 'https://webhook.site/d2b9e5c7-1234-5678-abcd-ef1234567890';
    
    $data = [
        'BusinessShortCode' => $businessCode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => $amount,
        'PartyA' => $phone,
        'PartyB' => $businessCode,
        'PhoneNumber' => $phone,
        'CallBackURL' => $callbackUrl,
        'AccountReference' => $accountRef,
        'TransactionDesc' => $transactionDesc
    ];
    
    $jsonData = json_encode($data);
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($curl);
    curl_close($curl);
    
    // Log the request and response
    file_put_contents('stk_push.log', 
        date('Y-m-d H:i:s') . "\n" .
        "Request: " . $jsonData . "\n" .
        "Response: " . $response . "\n\n",
        FILE_APPEND
    );
    
    return $response;
}

// If no valid request
echo json_encode([
    'error' => 'Invalid request method',
    'method' => $_SERVER['REQUEST_METHOD']
]);
?>
