(function () {
    'use strict';

    window.cnFetchJson = function (url, options) {
        options = options || {};
        options.headers = options.headers || {};
        options.headers['X-Requested-With'] = 'XMLHttpRequest';
        return fetch(url, options).then(function (r) {
            return r.json();
        });
    };
})();
