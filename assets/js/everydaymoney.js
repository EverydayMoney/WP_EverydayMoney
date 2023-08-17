jQuery(function ($) {
    var everydaymoney_submit = false;

    jQuery("#everydaymoney-payment-button").click(function () {
        return everydaymoneyFormHandler();
    });

    jQuery("#everydaymoney_form form#order_review").submit(function () {
        return everydaymoneyFormHandler();
    });

    function everydaymoneyFormHandler() {
        if (everydaymoney_submit) {
            everydaymoney_submit = false;
            return true;
        }

        var $form = $("form#payment-form, form#order_review"),
            transactionId = $form.find("input.transactionId");
        transactionId.val("");

        var everydaymoney_callback = function (response) {
            $form.append('<input type="hidden" class="transactionId" name="transactionId" value="' + response.transactionId + '"/>');
            $("#everydaymoney_form a").hide();
            everydaymoney_submit = true;
            $form.submit();
        };

        var key = wc_everydaymoney_params.key;
        var amount = wc_everydaymoney_params.amount;
        var order_id = wc_everydaymoney_params.order_id;
        var email = wc_everydaymoney_params.email;
        var billing_phone = wc_everydaymoney_params.billing_phone;
        var first_name = wc_everydaymoney_params.first_name;
        var last_name = wc_everydaymoney_params.last_name;
        var uniqueOrderId = wc_everydaymoney_params.uniqueOrderId;

        var paymentEngine = RmPaymentEngine.init({
            key: key,
            customerId: order_id,
            firstName: first_name,
            lastName: last_name,
            narration: "bill pay",
            transactionId: uniqueOrderId,
            email: email,
            amount: amount,
            onSuccess: everydaymoney_callback,
            onError: function (response) {
                console.log("callback Error Response", response);
            },
            onClose: function () {
                console.log("closed");
            },
        });

        paymentEngine.showPaymentWidget();
        return false;
    }
});
