/* global window, document */
(function (window, document) {
  'use strict';

  window.StockMindCSRF = {
    token: function () {
      var meta = document.querySelector('meta[name="csrf-token"]');
      return meta ? meta.getAttribute('content') || '' : '';
    }
  };
})(window, document);
