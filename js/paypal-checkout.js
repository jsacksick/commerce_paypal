(function ($, Drupal) {
  'use strict';

  Drupal.paypalCheckout = {
    renderButtons: function(settings) {
      $(settings['elementSelector']).once().each(function() {
        paypal.Buttons({
          createOrder: function() {
            return fetch(settings.onCreateUri)
              .then(function(res) {
                return res.json();
              }).then(function(data) {
                return data.id ? data.id : '';
              });
          }
        }).render('#' + $(this).attr('id'));
      });
    },
    initialize: function (context, settings) {
      if (context === document) {
        var script = document.createElement('script');
        script.src = settings.src;
        script.type = 'text/javascript';
        document.getElementsByTagName('head')[0].appendChild(script);
      }
      const waitForSdk = function(settings) {
        if (typeof paypal !== 'undefined') {
          Drupal.paypalCheckout.renderButtons(settings);
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
    }
  };

})(jQuery, Drupal);
