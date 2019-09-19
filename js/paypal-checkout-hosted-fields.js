(function ($, Drupal) {
  'use strict';

  Drupal.paypalCheckout = {
    formSelector: '#commerce-paypal-checkout-card-form',
    makeCall: function(url, settings) {
      settings = settings || {};
      var ajaxSettings = {
        dataType: 'json',
        url: url
      };
      $.extend(ajaxSettings, settings);
      return $.ajax(ajaxSettings);
    },
    renderForm: function(settings, context) {
      var $form = $(this.formSelector, context).closest('form').once('paypal-attach');
      if ($form.length === 0) {
        return;
      }
      paypal.HostedFields.render({
        createOrder: function() {
          return Drupal.paypalCheckout.makeCall(settings.onCreateUrl).then(function(data) {
            return data.id;
          });
        },
        styles: {
          'input': {
            'font-size': '14px',
            'font-family': 'Product Sans',
            'color': '#3a3a3a'
          },
          ':focus': {
            'color': 'black'
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
          if ($(Drupal.paypalCheckout.formSelector).length === 0) {
            return;
          }
          event.preventDefault();
          hostedFields.submit().then(function(data) {
          });
        });
      });
      if (paypal.HostedFields.isEligible() === true) {
        console.log('Is eligible!!!!!!');
      }
    },
    initialize: function (context, settings) {
      if (context === document) {
        var script = document.createElement('script');
        script.src = settings.src;
        script.type = 'text/javascript';
        script.setAttribute('data-partner-attribution-id', 'Centarro_Commerce_PCP');
        script.setAttribute('data-client-token', settings['clientToken']);
        document.getElementsByTagName('head')[0].appendChild(script);
      }
      var waitForSdk = function(settings) {
        if (typeof paypal !== 'undefined') {
          Drupal.paypalCheckout.renderForm(settings, context);
        }
        else {
          setTimeout(function() {
            waitForSdk(settings)
          }, 100);
        }
      };
      waitForSdk(settings);
    }
  };

  Drupal.behaviors.commercePaypalCheckout = {
    attach: function (context, settings) {
      Drupal.paypalCheckout.initialize(context, settings.paypalCheckout);
    },
    detach: function (context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      var $form = $('#commerce-paypal-checkout-card-form', context).closest('form');
      if ($form.length === 0) {
        return;
      }
    }
  };

})(jQuery, Drupal);
