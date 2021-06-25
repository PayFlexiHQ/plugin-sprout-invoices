var $ = jQuery.noConflict();

jQuery(function($) {

    var payflexi_flexible_checkout_payment_submit = false;

    jQuery('#payflexi_payment_button' ).on('click', function() {
        return PayflexiFlexibleCheckoutPaymentFormHandler();
    });

    function CheckoutMetaFields() {
        var meta = {
            title: si_payflexi_js_object.description
        };
        if (si_payflexi_js_object.invoice_id){
            meta['invoice_id'] = si_payflexi_js_object.invoice_id;
        }
        if(si_payflexi_js_object.email){
            meta['email'] = si_payflexi_js_object.email;
        }
        if(si_payflexi_js_object.name){
            meta['name'] = si_payflexi_js_object.name;
        }
        return meta;
    }

    function PayflexiFlexibleCheckoutPaymentFormHandler() {

        if ( payflexi_flexible_checkout_payment_submit ) {
            payflexi_flexible_checkout_payment_submit = false;
            return true;
        }
        //var $form = $("#si_credit_card_form").attr('action', '' + si_payflexi_js_object.checkout_form_url + '');
        var $form = $("#si_credit_card_form");

        var amount = Number( si_payflexi_js_object.amount );

        var payflexi_callback = function( response ) {
           // $form.append( '<input type="hidden" name=' + si_payflexi_js_object.checkout_input_name + '" value="' + si_payflexi_js_object.checkout_input_value + '"/>' );
            $form.append( '<input type="hidden" name="reference" value="' + response.reference + '"/>' );
            payflexi_flexible_checkout_payment_submit = true;
            setTimeout(function () {
                $form.submit();
            }, 5000);

            $( 'body' ).block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                },
                css: {
                    cursor: "wait"
                }
            });
        };

		var payflexiHandler = PayFlexi.checkout({
            key: ''+si_payflexi_js_object.key+'',
            gateway: ''+si_payflexi_js_object.gateway+'',
            reference: ''+si_payflexi_js_object.ref+'',
            name: ''+si_payflexi_js_object.name+'',
            email: ''+si_payflexi_js_object.email+'',
            amount: amount,
            currency: ''+si_payflexi_js_object.currency+'',
            meta: CheckoutMetaFields(),
			onSuccess: payflexi_callback,
			onExit: function() {
                window.location.reload();
            },
            onDecline: function (response) {
                console.log(response);
                window.location.reload();
            }
		});
    
        payflexiHandler.renderCheckout();

        return false;
    
    }

});