<?php
/**
 * Plugin Name: EverydayMoney Payments for WordPress and WooCommerce
 * Plugin URI:  https://wordpress.everydaymoney.app
 * Description: Accepts payments with EverydayMoney.App
 * Author:      EverydayMoney.App
 * Author URI:  https://everydaymoney.app/
 * Version:     0.1
 * WC requires at least: 3.0
 * WC tested up to: 8.0.2
 */

// Hook to run when the admin menu is being set up
add_action('admin_menu', 'everydaymoney_admin_menu');

add_action("woocommerce_api_everydaymoney_payment_webhook", "everydaymoney_payment_webhook");

function everydaymoney_payment_webhook() {
    header("HTTP/1.1 200 OK");

    // Get the incoming POST data
    $request_body = file_get_contents("php://input");
    $request_data = json_decode($request_body, true);

    // Verify the transactionRef against the external API
    $transactionRef = $request_data["transactionRef"];
    $gateway = new WC_EverydayMoney();
    if ($gateway->testmode == "yes") {
        $chargeUrl =
            "https://em-api-staging.logicaladdress.com/payment/business/charge";
    } else {
        $chargeUrl =
            "https://em-api-prod.everydaymoney.app/payment/business/charge";
    }
    // TODO: Set PublicKey in Header
    $verification_response = wp_remote_get(
        $chargeUrl . "?transactionRef=" . $transactionRef
    );
    if (!is_wp_error($verification_response)) {
        $verification_body = json_decode(wp_remote_retrieve_body($verification_response), true);

        if (!$verification_body["isError"] && $verification_body["result"]["status"] === "success") {
            // Transaction is verified, update the order status or perform any necessary actions
            $order_id = wc_get_order_id_by_order_key(
                $verification_body["result"]["referenceKey"]
            );
            $order = wc_get_order($order_id);

            $order_status = $order->get_status();
            // Update order status, complete the payment, etc.
            if($order_status == 'pending'){
                $order->payment_complete();
                // Add a note to the order
                $order->add_order_note("Payment completed via EverydayMoney. Transaction Ref: {$transactionRef}");
                // Send order completion email to the customer
                try {
                    // $order->send_order_completed_email();
                } catch (Exception $e) {
                    // Log the error message
                    error_log('Error sending order completion email: ' . $e->getMessage());
                    // You can also add additional error-handling logic here
                }
            }
            // Send a success response to the webhook
            wp_send_json(["status" => "success"]);
        }
    }

    // If verification fails or there's an error, send an error response
    wp_send_json(["status" => "error"]);
}

function everydaymoney_admin_menu() {
    // Check if WooCommerce is active
    if (class_exists('WC_Payment_Gateway')) {
        // Add your admin menu page or do other plugin setup here
    } else {
        add_action('admin_notices', 'everydaymoney_plugin_admin_notice');
    }
}

function everydaymoney_plugin_admin_notice() {
    echo '<div class="notice notice-error"><p>EverydayMoney Plugin requires WooCommerce to be installed and activated.</p></div>';
}


add_filter(
    "woocommerce_payment_gateways",
    "woocommerce_add_everydaymoney_payments"
);
function woocommerce_add_everydaymoney_payments($methods)
{
    $methods[] = "wc_everydaymoney";
    return $methods;
}

add_filter("plugins_loaded", "wc_everydaymoney_init");

function wc_everydaymoney_init()
{
    // if (!class_exists('WC_Payment_Gateway')) {
    //     return;
    // }

    if (!class_exists("WC_EverydayMoney") && class_exists('WooCommerce')) {
        class WC_EverydayMoney extends WC_Payment_Gateway
        {
            public function __construct()
            {
                $this->id = 'everyday-money';
                $this->icon = apply_filters('woocommerce_everydaymoney_icon', plugins_url('assets/images/everydaymoney-pay-options.png', __FILE__));
                $this->method_title = __('EverydayMoney', 'woocommerce');
                $this->method_description = 'EverydayMoney is a financial automation platform';
                $this->title = 'EverydayMoney Payments';

                $this->supports = array('products');

                // Load the form fields.
                $this->init_form_fields();

                // Load the settings.
                $this->init_settings();

                // Define user set variables
                $this->publicKey = $this->get_option("publicKey");
                $this->secretKey = $this->get_option("secretKey");
                $this->testmode = $this->get_option("testmode");
                $this->emailRequired = $this->get_option("emailRequired");
                $this->phoneRequired = $this->get_option("phoneRequired");
                $this->redirectUrl = $this->get_option("redirectUrl");
                $this->webhookUrl = $this->get_option("webhookUrl");
                $this->inclusive = $this->get_option("inclusive");
                $this->wallet = $this->get_option("wallet");

                $this->description = $this->get_option("description");
                $this->enabled = $this->get_option("enabled");

                // This action hook saves the settings
                add_action(
                    "woocommerce_update_options_payment_gateways_" . $this->id,
                    [$this, "process_admin_options"]
                );

                add_action("wp_enqueue_scripts", [$this, "payment_scripts"]);

                // You can also register a webhook here -> when all set
                add_action("woocommerce_api_everydaymoney_payment", [
                    $this,
                    "everydaymoney_payment_handler",
                ]);

                //Filters
                add_filter("woocommerce_currencies", [
                    $this,
                    "add_ngn_currency",
                ]);
                add_filter(
                    "woocommerce_currency_symbol",
                    [$this, "add_ngn_currency_symbol"],
                    10,
                    2
                );
            }

            function init_form_fields()
            {
                $this->form_fields = [
                    "publicKey" => [
                        "title" => "Your EM Public Key",
                        "type" => "text",
                        "description" => "The User name used for authorization",
                        "desc_tip" => true,
                    ],
                    "secretKey" => [
                        "title" => "Your EM Secret Key",
                        "type" => "text",
                        "description" =>
                            "The User secretKey used for authorization",
                        "desc_tip" => true,
                    ],

                    "description" => [
                        "title" => "Description",
                        "type" => "textarea",
                        "description" =>
                            "This controls the description which the user sees during checkout.",
                        "default" =>
                            "Proceed to make payment with EverydayMoney Payments",
                    ],

                    "enabled" => [
                        "title" => "Enable/Disable",
                        "label" => "Enable EverydayMoney Payments",
                        "type" => "checkbox",
                        "description" => "",
                        "default" => "no",
                    ],

                    "testmode" => [
                        "title" => "Test Mode",
                        "label" =>
                            "Enable this to use the test credentials provided by EverydayMoney",
                        "type" => "checkbox",
                        "description" => "",
                        "default" => "yes",
                    ],

                    "inclusive" => [
                        "title" => "Charges Inclusive",
                        "label" =>
                            "Check if you are bearing the cost of transactions",
                        "type" => "checkbox",
                        "description" => "",
                        "default" => "yes",
                    ],

                    "emailRequired" => [
                        "title" => "Make Email Required",
                        "label" => "Enforce Email Validation",
                        "type" => "checkbox",
                        "description" => "",
                        "default" => "no",
                    ],

                    "phoneRequired" => [
                        "title" => "Make Phone Number Required",
                        "label" => "Enforce Phone Validation",
                        "type" => "checkbox",
                        "description" => "",
                        "default" => "no",
                    ],
                    "redirectUrl" => [
                        "title" => "Redirection Endpoint",
                        "type" => "text",
                        "description" => "We will redirect here when payment is successful",
                        "default" => get_option('siteurl')."/wc-api/everydaymoney_payment",
                        "desc_tip" => true,
                    ],
                    "webhookUrl" => [
                        "title" => "Webhook Endpoint URL",
                        "type" => "text",
                        "description" => "This ensures that payment is logged even when payment is carried out outside the app",
                        "default" => get_site_url() . "/wc-api/everydaymoney_payment_webhook",
                    ],
                    "wallet" => [
                        "title" => "Wallet ID",
                        "type" => "text",
                        "description" => "Settlement will occur on this wallet",
                        "default" => "default",
                        "desc_tip" => true,
                    ],
                ];
            }

            function add_ngn_currency($currencies)
            {
                $currencies["NGN"] = __("Nigerian Naira (NGN)", "woocommerce");
                return $currencies;
            }

            function add_ngn_currency_symbol($currency_symbol, $currency)
            {
                switch ($currency) {
                    case "NGN":
                        $currency_symbol = "₦";
                        break;
                }

                return $currency_symbol;
            }

            public function payment_scripts()
            {
                // we need JavaScript to process a token only on cart/checkout pages, right?
                if (
                    !is_cart() &&
                    !is_checkout() &&
                    !isset($_GET["pay_for_order"])
                ) {
                    return;
                }

                // if our payment gateway is disabled, we do not have to enqueue JS too
                if ("no" === $this->enabled) {
                    return;
                }

                // no reason to enqueue JavaScript if API keys are not set
                if (
                    empty($this->private_key) ||
                    empty($this->publishable_key)
                ) {
                    return;
                }

                // do not work with card detailes without SSL unless your website is in a test mode
                if (!$this->testmode && !is_ssl()) {
                    return;
                }
            }

            public function process_payment($order_id)
            {
                global $woocommerce;

                // we need it to get any order detailes
                $order = wc_get_order($order_id);
                $data = $order->get_data();
                if ($this->testmode == "yes") {
                    $redirectUrl =
                        "https://dtu039g57sbfd.cloudfront.net?methods=bank_transfer&transactionRef=";
                } else {
                    // TODO: Set it to cloudfront.net or its alias
                    $redirectUrl =
                        "https://em-api-prod.everydaymoney.app/public/index.html?transactionRef=";
                }

                /*
                 * Array with parameters for API interaction
                 */
                $args = [
                    "publicKey" => $this->publicKey,
                    "customerName" =>
                        $data["billing"]["first_name"] .
                        " " .
                        $data["billing"]["last_name"],
                    "customerKey" => $data["billing"]["email"],
                    "referenceKey" => $order->get_order_key(),
                    "amount" => floatval($order->get_total()),
                    "currency" => "NGN",
                    "wallet" => $this->wallet,
                    "inclusive" => $this->inclusive == "yes" ? true : false,
                    "redirectUrl" => $this->redirectUrl,
                    "webhookUrl" => $this->webhookUrl,
                ];

                if ($this->phoneRequired != "no" && $data["billing"]["phone"]) {
                    $args["phone"] = $data["billing"]["phone"];
                }

                if ($this->emailRequired != "no" && $data["billing"]["email"]) {
                    $args["email"] = $data["billing"]["email"];
                }
                if($this->redirectUrl){
                    $args["redirectUrl"] = $this->redirectUrl;
                }
                /*
                 * Your API interaction could be built with wp_remote_post()
                 */
                if ($this->testmode == "yes") {
                    $chargeUrl =
                        "https://em-api-staging.logicaladdress.com/payment/business/charge";
                } else {
                    $chargeUrl =
                        "https://em-api-prod.everydaymoney.app/payment/business/charge";
                }
                $response = wp_remote_post($chargeUrl, [
                    "headers" => ["Content-type" => "application/json"],
                    "body" => json_encode($args),
                ]);

                if (!is_wp_error($response)) {
                    $body = json_decode($response["body"], true);
                    if (!$body["isError"]) {
                        return [
                            "result" => "success",
                            "redirect" =>
                                $redirectUrl .
                                $body["result"]["transactionRef"],
                        ];
                    } else {
                        if(isset($body["error"]) && isset($body["error"]["message"])){
                            wc_add_notice($body["error"]["message"], "error");
                        } else {
                            wc_add_notice("An unknown error ocured, please check your input and try again", "error");
                        }
                        return;
                    }
                } else {
                    wc_add_notice("Connection error.", "error");
                    return;
                }
            }

            public function everydaymoney_payment_handler()
            {
                header("HTTP/1.1 200 OK");
                $transactionRef = isset($_GET["transactionRef"])
                    ? $_GET["transactionRef"]
                    : null;
                if (!$transactionRef) {
                    wc_add_notice(
                        "Something went wrong, please try again later",
                        "error"
                    );
                    $order_id = wc_get_order_id_by_order_key(
                        $GET["referenceKey"]
                    );
                    $order = wc_get_order($order_id);
                    return wp_redirect($this->get_return_url($order));
                }

                if ($this->testmode == "yes") {
                    $chargeUrl =
                        "https://em-api-staging.logicaladdress.com/payment/business/charge";
                } else {
                    $chargeUrl =
                        "https://em-api-prod.everydaymoney.app/payment/business/charge";
                }
                $response = wp_remote_get(
                    $chargeUrl . "?transactionRef=" . $transactionRef
                );

                if (!is_wp_error($response)) {
                    $body = json_decode($response["body"], true);
                    if (
                        !$body["isError"] &&
                        $body["result"]["status"] != "pending"
                    ) {
                        if (
                            $body["result"]["status"] != "success" ||
                            $body["result"]["status"] != "completed"
                        ) {
                            $order_id = wc_get_order_id_by_order_key(
                                $body["result"]["referenceKey"]
                            );
                            $order = wc_get_order($order_id);
                            $order_status = $order->get_status();
                            // Update order status, complete the payment, etc.
                            if($order_status == 'pending'){
                                $order->payment_complete();
                                $order->add_order_note("Payment completed via EverydayMoney. Transaction Ref: {$transactionRef}");
                                try {
                                    // $order->send_order_completed_email();
                                } catch (Exception $e) {
                                    // Log the error message
                                    error_log('Error sending order completion email: ' . $e->getMessage());
                                    // You can also add additional error-handling logic here
                                }
                            }
                            wc_empty_cart();
                            wc_add_notice(
                                "Your payment is received!",
                                "success"
                            );
                            return wp_redirect($this->get_return_url($order));
                        } else {
                            wc_add_notice(
                                $body["result"]["statusReason"],
                                "error"
                            );
                            return;
                        }
                    } else if (
                        !$body["isError"] &&
                        $body["result"]["status"] == "pending"
                    ) {
                        // Alert or show user that  order was canceled
                        // will be best if redirect to order page & notify user that order is complete/err/canceled
                        $order_id = wc_get_order_id_by_order_key(
                            $body["result"]["referenceKey"]
                        );
                        $order = wc_get_order($order_id);
                        wc_add_notice(
                            "We are yet to process your payment",
                            "error"
                        );
                        return wp_redirect($this->get_return_url($order));
                    } else {
                        $order_id = wc_get_order_id_by_order_key(
                            $body["result"]["referenceKey"]
                        );
                        $order = wc_get_order($order_id);
                        wc_add_notice(
                            "Oopse! Something went wrong",
                            "error"
                        );
                        return wp_redirect($this->get_return_url($order));
                    }
                } else {
                    wc_add_notice("Connection error.", "error");
                    return;
                }

                wp_die();
                // update_option('webhook_debug', $_GET);
            }
        }
    }
}


add_action('admin_menu', 'everydaymoney_reports_menu');

function everydaymoney_reports_menu() {
    add_menu_page(
        'EverydayMoney',
        'EverydayMoney',
        'manage_options',
        'everydaymoney_reports',
        'everydaymoney_reports_page',
        'dashicons-chart-bar', // Using analytics icon
        20 // Menu position
    );
}

function everydaymoney_enqueue_datatables_assets() {
    wp_enqueue_style('datatables', 'https://cdn.datatables.net/1.10.25/css/jquery.dataTables.min.css');
    wp_enqueue_script('jquery');
    wp_enqueue_script('datatables', 'https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js', array('jquery'), null, true);
}


add_action('admin_enqueue_scripts', 'everydaymoney_enqueue_datatables_assets');

function everydaymoney_fetch_sales_reports($publicKey, $secretKey, $testMode, $take = 20) {
    $token = null;
    if($testMode === "yes"){
        $baseEndpoint = "https://em-api-staging.logicaladdress.com";
    }else{
        $baseEndpoint = "https://em-api-prod.everydaymoney.app";
    }

    $tokenResponse = wp_remote_request(
        "${baseEndpoint}/auth/business/token",
        array(
            'method'      => 'POST',
            'headers'     => array(
                'accept'       => '*/*',
                'Authorization' => 'Basic ' . base64_encode($publicKey . ':' . $secretKey),
                'x-api-key' => $publicKey,
            ),
        )
    );

    if (is_array($tokenResponse) && !is_wp_error($tokenResponse)) {
        $body = wp_remote_retrieve_body($tokenResponse);
        $result = json_decode($body);

        if (!$result->isError) {
            $token = $result->result->token;
        } else {
            // Handle error case
            return $result->error;
        }
    } else {
        // Handle HTTP request error
        return 'HTTP request error';
    }

    $response = wp_remote_request(
        "${baseEndpoint}/payment/business/charge-history?order=DESC&page=1&take=${take}",
        array(
            'method'      => 'POST',
            'headers'     => array(
                'accept'       => '*/*',
                'Authorization' => 'Bearer ' . $token,
            ),
        )
    );

    if (is_array($response) && !is_wp_error($response)) {
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body);

        if (!$result->isError) {
            return $result->result;
        } else {
            // Handle error case
            return $result->error;
        }
    } else {
        // Handle HTTP request error
        return 'HTTP request error';
    }
}



function everydaymoney_reports_page() {
    ?>
    <div class="wrap">
        <h2>EverydayMoney Reports</h2>
        <p>This page displays reports fetched from EverydayMoney.</p>
    <?php
    if (!class_exists("WC_EverydayMoney") || !class_exists("WC_Payment_Gateway")){
        echo '<div class="notice notice-error"><p>EverydayMoney and WooCommerce is required.</p></div>';
        return;
    }
    $gateway = new WC_EverydayMoney(); // Create an instance of the gateway class
    $sales_data = everydaymoney_fetch_sales_reports($gateway->publicKey, $gateway->secretKey, $gateway->testmode, 20);
    // die(var_dump($sales_data[0]->customer));
    ?>
        <?php if(is_array($sales_data)){ ?>
        <table id="sales-table" class="display" style="width:100%">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Name</th>
                    <th>Transaction Ref</th>
                    <th>Amount</th>
                    <th>Settled Amount</th>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Paid Date</th>
                    <th>Paid Time</th>
                    <!-- Add more columns as needed -->
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales_data as $sale) : ?>
                    <tr>
                        <td><?php echo $sale->customer ? $sale->customer->customerKey : "N/A"; ?></td>
                        <td><?php echo $sale->customer && $sale->customer->name != null ? $sale->customer->name : "N/A"; ?></td>
                        <td><?php echo $sale->transactionRef; ?></td>
                        <td><?php echo number_format($sale->amount / 100, 2) ?></td>
                        <td><?php echo number_format($sale->settledAmount / 100, 2) ?></td>
                        <td><?php echo strtoupper($sale->status); ?></td>
                        <td><?php echo strtoupper($sale->chargeType); ?></td>
                        <td><?php echo $sale->paidAtDate; ?></td>
                        <td><?php echo $sale->paidAtTime; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <? } else { ?>
        <div><?php $sales_data ?></div>
        <?php } ?>
    </div>
    <script>
        jQuery(document).ready(function($) {
            $('#sales-table').DataTable();
        });
    </script>
    <?php
}

?>
