((function($) {

  const request = window.Grav.default.Utils.request || function() {};
  const base_url = window.GravAdmin.config.base_url_relative;

  const charCounter = $('.char-counter');

  if (typeof LazyLoad !== 'undefined') {
    new LazyLoad({});
  }

  if (charCounter.length) {
    charCounter.textcounter({
      type: "character",
      max: 0,
      stopInputAtMaximum: false,
      countSpaces: true,
      counterText: '%d chars',
    });
  }

  const collapse = function(h2, callback) {
    const icon = h2.find('.expand-icon');
    const panel = h2.next('.expand-panel');

    icon.addClass('closed');
    panel.slideUp(400, callback || function() {});
  }

  const expand = function(h2, callback) {
    const icon = h2.find('.expand-icon');
    const panel = h2.next('.expand-panel');

    icon.removeClass('closed');
    panel.slideDown(400, callback || function() {});
  }

  const toggle = function(h2, callback) {
    const panel = h2.next('.expand-panel');
    const isVisible = panel.is(':visible');

    if (isVisible) {
      collapse(h2, callback);
    } else {
      expand(h2, callback);
    }
  }

  $(document).on('click', '.seomagic-report .expand-wrapper > h2', function(event) {
    const target = $(event.currentTarget);
    toggle(target);
  });

  $(document).on('click', '.seomagic-report [data-expand]', function(event) {
    event.preventDefault();

    const target = $(event.currentTarget);
    const doExpand = target.data('expand');
    const triggers = $('.seomagic-report .expand-wrapper > h2');

    triggers.each(function(index, trigger) {
      trigger = $(trigger);

      if (doExpand) {
        expand(trigger);
      } else {
        collapse(trigger);
      }
    });
  });

  $(document).on('click', '.seomagic-report [data-quick-jump]', function(event) {
    event.preventDefault();

    const target = $(event.currentTarget);
    const destination = target.data('quickJump');
    const toolbar = $('.content-wrapper .quick-jump-bar');
    const container = $('.content-wrapper .simplebar-content-wrapper');
    const header = $(`h2#${destination}`);

    const scroll = function() {
      const offset = Math.round(toolbar.height() / 44) - 1;
      const headerTop = header.position().top;
      container[0].scroll({
        top: headerTop - (44 * offset),
        behavior: 'smooth'
      });
    };

    if (header.length) {
      scroll();
      expand(header, scroll);
    }
  });

  $(document).on('click', '.seomagic-reindex, .seomagic-delete', function(event) {
    event.preventDefault();
    const target = $(event.currentTarget);
    if (target.is('[disabled]')) {
      return false;
    }

    const elements = {
      reindex: $('.seomagic-reindex'),
      trash: $('.seomagic-delete')
    }

    if (target.hasClass('seomagic-reindex')) {
      elements.reindex
        .attr('disabled', 'disabled')
        .find('> .fa').removeClass('fa-magic').addClass('fa-refresh fa-spin');

      return request(base_url + '.json/action:processSEOMagic/admin-nonce:' + GravAdmin.config.admin_nonce, function() {
        elements.reindex
          .removeAttr('disabled')
          .find('> .fa').removeClass('fa-refresh fa-spin fa-magic').addClass('fa-magic');
      });
    } else {
      elements.trash
        .attr('disabled', 'disabled')
        .find('> .fa').removeClass('fa-trash').addClass('fa-refresh fa-spin');

      return request(base_url + '.json/action:removeDataSEOMagic/admin-nonce:' + GravAdmin.config.admin_nonce, function() {
        elements.trash
          .removeAttr('disabled')
          .find('> .fa').removeClass('fa-refresh fa-spin fa-trash').addClass('fa-trash');
      });
    }
  });

})(jQuery));
