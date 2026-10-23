/* global window, document, jQuery */
(function (window, document, $) {
  'use strict';

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      }[char];
    });
  }

  function toast(message, icon) {
    var stack = document.getElementById('toast-stack');

    if (!stack || !$) {
      return;
    }

    var item = document.createElement('div');
    item.className = 'toast';
    item.setAttribute('role', 'status');
    item.innerHTML = '<i class="bi ' + escapeHtml(icon || 'bi-info-circle-fill') + '"></i><span>' + escapeHtml(message) + '</span>';
    stack.appendChild(item);

    window.setTimeout(function () {
      $(item).fadeOut(180, function () {
        item.remove();
      });
    }, 3200);
  }

  window.StockMindApp = {
    escapeHtml: escapeHtml,
    toast: toast
  };
})(window, document, window.jQuery);
