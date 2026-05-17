(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var burger = document.getElementById('sidebar-toggle');
        var sidebar = document.querySelector('.sidebar');
        if (burger && sidebar) {
            burger.addEventListener('click', function () {
                sidebar.classList.toggle('open');
            });
            document.body.addEventListener('click', function (e) {
                if (
                    sidebar.classList.contains('open') &&
                    !sidebar.contains(e.target) &&
                    e.target !== burger &&
                    !burger.contains(e.target)
                ) {
                    sidebar.classList.remove('open');
                }
            });
        }
    });
})();
