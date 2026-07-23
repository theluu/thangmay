(function($) {
	"use strict";

    $(document).ready(function() {
        $('body').each(function(index, item) {
            var tuVan  = $(item).find('.tu-van');
            var chuyenVien  = $(item).find('.chuyen-vien');

            $(tuVan).appendTo($(chuyenVien));
        });
    });
})(window.jQuery);