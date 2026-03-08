(function (window) {
    'use strict';

    var $ = window.jQuery;

    if (!$) {
        return;
    }

    if (typeof $.fn.andSelf !== 'function' && typeof $.fn.addBack === 'function') {
        $.fn.andSelf = function () {
            return this.addBack.apply(this, arguments);
        };
    }

    if (typeof $.fn.size !== 'function') {
        $.fn.size = function () {
            return this.length;
        };
    }

    if (typeof $.trim !== 'function') {
        $.trim = function (value) {
            if (value === null || value === undefined) {
                return '';
            }

            return String(value).trim();
        };
    }

    if (typeof $.isArray !== 'function') {
        $.isArray = Array.isArray;
    }

    if (typeof $.isFunction !== 'function') {
        $.isFunction = function (value) {
            return typeof value === 'function';
        };
    }

    if (typeof $.isNumeric !== 'function') {
        $.isNumeric = function (value) {
            if (value === null || value === undefined || value === '') {
                return false;
            }

            return !Number.isNaN(Number(value));
        };
    }
})(window);
