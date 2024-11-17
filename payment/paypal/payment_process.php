<?php
ob_start();
session_start();
require_once('../../admin/inc/config.php');

$error_message = '';

// Fetch PayPal email from settings
$statement = $pdo->prepare("SELECT paypal_email FROM tbl_settings WHERE id=1");
$statement->execute();
$row = $statement->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die("PayPal email is not configured.");
}

$paypal_email = $row['paypal_email'];

// PayPal URLs
$return_url = 'payment_success.php'; // Ensure these files exist
$cancel_url = 'payment.php';
$notify_url = 'payment/paypal/verify_process.php';

$item_name = 'Product Item(s)';
$item_amount = $_POST['final_total'] ?? 0; // Use null coalescing operator to avoid undefined index errors
$item_number = time();
$payment_date = date('Y-m-d H:i:s');

// Check if PayPal request or response
if (!isset($_POST["txn_id"]) && !isset($_POST["txn_type"])) {
    $querystring = '';

    // Append PayPal account to querystring
    $querystring .= "?business=" . urlencode($paypal_email) . "&";
    
    // Append item details
    $querystring .= "item_name=" . urlencode($item_name) . "&";
    $querystring .= "amount=" . urlencode($item_amount) . "&";
    $querystring .= "item_number=" . urlencode($item_number) . "&";

    // Append posted values
    foreach ($_POST as $key => $value) {
        $value = urlencode(stripslashes($value));
        $querystring .= "$key=$value&";
    }

    // Append PayPal return addresses
    $querystring .= "return=" . urlencode($return_url) . "&";
    $querystring .= "cancel_return=" . urlencode($cancel_url) . "&";
    $querystring .= "notify_url=" . urlencode($notify_url);

    // Insert payment record into the database
    $statement = $pdo->prepare("INSERT INTO tbl_payment (
                        customer_id,
                        customer_name,
                        customer_email,
                        payment_date,
                        txnid, 
                        paid_amount,
                        payment_method,
                        payment_status,
                        shipping_status,
                        payment_id
                    ) 
                    VALUES (?,?,?,?,?,?,?,?,?,?)");

    $sql = $statement->execute([
        $_SESSION['customer']['cust_id'] ?? null,
        $_SESSION['customer']['cust_name'] ?? 'Guest',
        $_SESSION['customer']['cust_email'] ?? 'N/A',
        $payment_date,
        '',
        $item_amount,
        'PayPal',
        'Pending',
        'Pending',
        $item_number
    ]);

    if ($sql) {
        // Debug URL if needed
        // echo 'Redirect URL: https://www.paypal.com/cgi-bin/webscr' . $querystring;
        header('Location: https://www.paypal.com/cgi-bin/webscr' . $querystring);
        exit();
    } else {
        die("Error inserting payment record.");
    }
} else {
    // Response from PayPal
    $req = 'cmd=_notify-validate';
    foreach ($_POST as $key => $value) {
        $value = urlencode(stripslashes($value));
        $value = preg_replace('/(.*[^%^0^D])(%0A)(.*)/i', '${1}%0D%0A${3}', $value); // IPN fix
        $req .= "&$key=$value";
    }

    // Assign posted variables to local variables
    $data = [
        'item_name'         => $_POST['item_name'] ?? '',
        'item_number'       => $_POST['item_number'] ?? '',
        'payment_status'    => $_POST['payment_status'] ?? '',
        'payment_amount'    => $_POST['mc_gross'] ?? 0,
        'payment_currency'  => $_POST['mc_currency'] ?? '',
        'txn_id'            => $_POST['txn_id'] ?? '',
        'receiver_email'    => $_POST['receiver_email'] ?? '',
        'payer_email'       => $_POST['payer_email'] ?? ''
    ];

    // Validate with PayPal
    $header = "POST /cgi-bin/webscr HTTP/1.0\r\n";
    $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $header .= "Content-Length: " . strlen($req) . "\r\n\r\n";

    $fp = fsockopen('ssl://www.paypal.com', 443, $errno, $errstr, 30);

    if (!$fp) {
        error_log("PayPal IPN validation failed: $errstr ($errno)");
        die("HTTP error during PayPal validation.");
    } else {
        fputs($fp, $header . $req);
        while (!feof($fp)) {
            $res = fgets($fp, 1024);
            if (strcmp($res, "VERIFIED") == 0) {
                // Payment verified, handle accordingly
                // Update database payment status here
            } elseif (strcmp($res, "INVALID") == 0) {
                error_log("PayPal IPN validation: INVALID response.");
            }
        }
        fclose($fp);
    }
}
