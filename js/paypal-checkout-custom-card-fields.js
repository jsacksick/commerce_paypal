(function ($, Drupal, settings) {
  'use strict';

  Drupal.paypalCheckout = {
    cardFieldsSelector: '',
    onCreateUrl: '',
    onSubmitUrl: '',
    makeCall: function(url, params) {
      params = params || {};
      var ajaxSettings = {
        dataType: 'json',
        url: url
      };
      $.extend(ajaxSettings, params);
      return $.ajax(ajaxSettings);
    },
    renderForm: function(context) {
      var $cardFields = $(this.cardFieldsSelector, context).once('paypal');
      if ($cardFields.length === 0 || paypal.HostedFields.isEligible() !== true) {
        return;
      }
      var $form = $cardFields.closest('form');
      paypal.HostedFields.render({
        createOrder: function() {
          return Drupal.paypalCheckout.makeCall(Drupal.paypalCheckout.onCreateUrl).then(function(data) {
            return data.id;
          });
        },
        styles: {
          'input': {
            'color': '#3A3A3A',
            'transition': 'color 160ms linear',
            '-webkit-transition': 'color 160ms linear'
          },
          ':focus': {
            'color': '#333333'
          },
          '.invalid': {
            'color': '#FF0000'
          }
        },
        fields: {
          number: {
            selector: '#commerce-paypal-card-number',
            placeholder: Drupal.t('Card Number')
          },
          cvv: {
            selector: '#commerce-paypal-cvv',
            placeholder: Drupal.t('CVV')
          },
          expirationDate: {
            selector: '#commerce-paypal-expiration-date',
            placeholder: 'MM/YYYY'
          }
        }
      }).then(function(hostedFields) {
        $form.on('submit', function (event) {
          if ($(Drupal.paypalCheckout.cardFieldsSelector).length === 0) {
            return;
          }
          event.preventDefault();
          var state = hostedFields.getState();
          var formValid = Object.keys(state.fields).every(function(key) {
            return state.fields[key].isValid;
          });
          if (formValid) {
            hostedFields.submit().then(function(payload) {
              return Drupal.paypalCheckout.makeCall(Drupal.paypalCheckout.onSubmitUrl, {
                type: 'POST',
                contentType: "application/json; charset=utf-8",
                data: JSON.stringify({
                  id: payload.orderId
                })
              }).then(function(data) {
                event.currentTarget.submit();
              });
            });
          }
        });
      });

    },
    initialize: function (context) {
      var waitForSdk = function() {
        if (typeof paypal !== 'undefined') {
          Drupal.paypalCheckout.renderForm(context);
        }
        else {
          setTimeout(function() {
            waitForSdk()
          }, 100);
        }
      };
      waitForSdk();
    }
  };

  $(function () {
    $.extend(true, Drupal.paypalCheckout, settings.paypalCheckout);
    var script = document.createElement('script');
    script.src = Drupal.paypalCheckout.src;
    script.type = 'text/javascript';
    script.setAttribute('data-partner-attribution-id', 'Centarro_Commerce_PCP');
    script.setAttribute('data-client-token', Drupal.paypalCheckout.clientToken);
    document.getElementsByTagName('head')[0].appendChild(script);
  });

  Drupal.behaviors.commercePaypalCheckout = {
    attach: function (context) {
      Drupal.paypalCheckout.initialize(context);
    }
  };

})(jQuery, Drupal, drupalSettings);
