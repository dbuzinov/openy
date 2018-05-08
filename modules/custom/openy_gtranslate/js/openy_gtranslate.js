(function ($) {

  Drupal.behaviors.openy_gtranslate = {
    attach: function (context, settings) {
      setTimeout(function () {
        var langSelect = $('.goog-te-menu-frame').first();

        $('nav .navbar-nav li.language > a').on('click', function (e, context) {
          e.preventDefault();
          langSelect.show();
          langSelect.addClass('open');
          return false;
        });

        $('body').on('click', function (e, context) {
          if (langSelect.hasClass('open')) {
            langSelect.hide();
            langSelect.removeClass('open');
          }
        });
      }, 100);
    }
  };

})(jQuery);