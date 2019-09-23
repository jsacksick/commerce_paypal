(function ($, Drupal, settings) {
  'use strict';

  Drupal.paypalCheckout = {
    cardFieldsSelector: '',
    onCreateUrl: '',
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
      var $submit = $form.find('.button--primary');
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
          // Disable the Continue button.
          $submit.attr("disabled", "disabled");
          event.preventDefault();
          var message = '';
          var $messagesContainer = $(Drupal.paypalCheckout.cardFieldsSelector + ' .paypal-messages');
          $messagesContainer.html('');
          var state = hostedFields.getState();
          var formValid = Object.keys(state.fields).every(function(key) {
            var isValid = state.fields[key].isValid;
            if (!isValid) {
              message += Drupal.t('The @field you entered is invalid.', {'@field': key}) + '<br>';
            }
            return isValid;
          });

          if (!formValid) {
            message += Drupal.t('Please check your details and try again.');
            $messagesContainer.html(Drupal.theme('commercePaypalError', message));
            $submit.attr("disabled", false);
            return;
          }
          Drupal.paypalCheckout.addLoader();
          hostedFields.submit({
            contingencies: ['3D_SECURE']
          }).then(function(payload) {
            if (!payload.hasOwnProperty('orderId')) {
              message += Drupal.t('Please check your details and try again.');
              $messagesContainer.html(Drupal.theme('commercePaypalError', message));
              $submit.attr("disabled", false);
              Drupal.paypalCheckout.removeLoader();
            }
            else {
              event.currentTarget.submit();
            }
          });
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
    },
    addLoader: function() {
      var $background = $('<div id="paypal-background-overlay"></div>');
      var $loader = $('<div class="paypal-background-overlay-loader"></div>');
      $background.append($loader);
      $('body').append($background);
    },
    removeLoader: function() {
      $('body').remove('#paypal-background-overlay');
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

  $.extend(Drupal.theme, /** @lends Drupal.theme */{
    commercePaypalError: function (message) {
      return $('<div role="alert">' +
        '<div class="messages messages--error">' + message + '</div>' +
        '</div>'
      );
    }
  });

})(jQuery, Drupal, drupalSettings);
