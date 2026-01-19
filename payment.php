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

// Test phone numbers for sandbox
$testPhones = ["254708374149"];

// Handle payment status check (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['checkoutID'])) {
    $checkoutID = $_GET['checkoutID'];
    
    // For sandbox - simulate payment confirmation
    if (isset($_SESSION['checkout_' . $checkoutID])) {
        $paymentData = $_SESSION['checkout_' . $checkoutID];
        $timeSinceRequest = time() - $paymentData['timestamp'];
        
        if ($timeSinceRequest > 10) { // Simulate success after 10 seconds
            $paymentData['status'] = 'completed';
            $_SESSION['checkout_' . $checkoutID] = $paymentData;
            
            // Add to transactions
            $_SESSION['transactions'][] = [
                'checkoutID' => $checkoutID,
                'name' => $paymentData['name'],
                'phone' => $paymentData['phone'],
                'amount' => $paymentData['amount'],
                'time' => date('Y-m-d H:i:s')
            ];
            
            echo json_encode([
                'ResultCode' => 0,
                'ResultDesc' => 'Payment successful',
                'CheckoutRequestID' => $checkoutID
            ]);
        } else {
            echo json_encode([
                'ResultCode' => '1032',
                'ResultDesc' => 'Payment in progress'
            ]);
        }
    } else {
        // For testing
        echo json_encode([
            'ResultCode' => 0,
            'ResultDesc' => 'Payment confirmed (test)'
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
    $basketItems = isset($_POST['basket']) ? json_decode($_POST['basket'], true) : null;
    
    // Validation
    if (empty($Amount) || empty($PhoneNumber) || empty($CustomerName)) {
        echo json_encode([
            'ResponseCode' => 1,
            'ResponseDescription' => 'Please fill all fields'
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
    
    // For sandbox, only allow test number
    if ($PhoneNumber !== '254708374149' && $PhoneNumber !== '254727016623') {
        echo json_encode([
            'ResponseCode' => 1,
            'ResponseDescription' => 'Sandbox: Use 254708374149 for testing'
        ]);
        exit;
    }
    
    // Generate timestamp and password
    $Timestamp = date('YmdHis');
    $Password = base64_encode($BusinessShortCode . $Passkey . $Timestamp);
    
    // Get access token
    $token = getAccessToken();
    if (!$token) {
        echo json_encode([
            'ResponseCode' => 1,
            'ResponseDescription' => 'Failed to connect to M-Pesa'
        ]);
        exit;
    }
    
    // Prepare STK push data
    $AccountReference = "TowettCollection";
    $TransactionDesc = "Clothing Purchase";
    
    // Send STK push
    $response = sendSTKPush($token, $BusinessShortCode, $Password, $Timestamp, 
                           $Amount, $PhoneNumber, $AccountReference, $TransactionDesc);
    
    $responseData = json_decode($response, true);
    
    if (isset($responseData['ResponseCode']) && $responseData['ResponseCode'] == "0") {
        $checkoutID = $responseData['CheckoutRequestID'];
        
        // Store in session
        $_SESSION['checkout_' . $checkoutID] = [
            'name' => $CustomerName,
            'phone' => $PhoneNumber,
            'amount' => $Amount,
            'timestamp' => time(),
            'status' => 'pending',
            'basket_items' => $basketItems
        ];
        
        echo json_encode([
            'ResponseCode' => '0',
            'ResponseDescription' => 'STK Push sent to your phone',
            'CheckoutRequestID' => $checkoutID,
            'CustomerMessage' => 'Check your phone for M-Pesa prompt'
        ]);
        
    } else {
        $errorMsg = $responseData['errorMessage'] ?? $responseData['ResponseDescription'] ?? 'Unknown error';
        echo json_encode([
            'ResponseCode' => '1',
            'ResponseDescription' => 'Payment failed: ' . $errorMsg
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
    curl_close($curl);
    
    $data = json_decode($response, true);
    return $data['access_token'] ?? false;
}

// Function to send STK push
function sendSTKPush($token, $businessCode, $password, $timestamp, 
                    $amount, $phone, $accountRef, $transactionDesc) {
    
    $url = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    
    // Use a test callback URL for sandbox
    $callbackUrl = 'https://yourdomain.com/mpesa_callback.php'; // Replace with your actual URL
    
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
    
    return $response;
}

// If no valid request
echo json_encode(['error' => 'Invalid request']);
?>
