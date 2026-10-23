
/* global window, fetch, FormData */
(function (window) {
  'use strict';

  function parseJson(response) {
    return response.text().then(function (text) {
      var data = {};

      if (text) {
        try {
          data = JSON.parse(text);
        } catch (parseError) {
          var preview = text
            .replace(/<[^>]*>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 300);

          var invalidJsonError = new Error(
            'Server returned an invalid JSON response (HTTP ' +
            response.status + '). ' +
            (preview || 'The response body is empty or unreadable.')
          );

          invalidJsonError.status = response.status;
          invalidJsonError.payload = text;
          throw invalidJsonError;
        }
      }

      if (!response.ok || data.success === false) {
        var message = data &&
          data.error &&
          data.error.message
            ? data.error.message
            : data.message || 'Request failed (' + response.status + ').';

        var error = new Error(message);
        error.status = response.status;
        error.payload = data;

        throw error;
      }

      return data;
    });
  }

  function request(url, options) {
    options = options || {};

    var method = (options.method || 'GET').toUpperCase();
    var headers = Object.assign(
      { Accept: 'application/json' },
      options.headers || {}
    );

    var body = options.body;

    if (body && typeof body === 'object' && !(body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }

    if (method !== 'GET') {
      headers['X-CSRF-Token'] = window.StockMindCSRF
        ? window.StockMindCSRF.token()
        : '';

      headers['X-Requested-With'] = 'XMLHttpRequest';
    }

    return fetch(url, {
      method: method,
      headers: headers,
      body: body,
      credentials: 'same-origin'
    }).then(parseJson);
  }

  window.StockMindAPI = {
    request: request
  };
})(window);
