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

  // AI Pro integration for Title/Description suggestions
  $(document).on('click', '.seomagic-ai', function(event){
    event.preventDefault();
    const btn = $(event.currentTarget);
    if (btn.is('[disabled]')) return;

    // Find the nearest form field input/textarea to apply result
    const fieldGroup = btn.closest('.form-field');
    let input = fieldGroup.find('input[type="text"], textarea').first();
    if (!input.length) return;

    const purpose = btn.data('purpose') || 'title';
    const bodyEl = fieldGroup.find('.seomagic-body').first();
    const body = (bodyEl.length ? bodyEl.val() : '').toString();
    const title = $('input[name$="[title]"]').val() || '';

    let prompt = '';
    if (purpose === 'title') {
      prompt = "Generate a concise, SEO‑friendly page title (≤ 60 characters).\n"+
               "Output ONLY the title, no quotes or extra text.\n\n"+
               (title ? ("Existing title: " + title + "\n\n") : '') +
               "Page content:\n" + body.substring(0, 4000);
    } else if (purpose === 'description') {
      prompt = "Generate a compelling meta description (140–160 chars).\n"+
               "Output ONLY the description, no quotes or extra text.\n\n"+
               (title ? ("Title: " + title + "\n\n") : '') +
               "Page content:\n" + body.substring(0, 6000);
    }

    const context = JSON.stringify({ type:'page', title, content: body.substring(0, 12000) });

    btn.attr('disabled','disabled').addClass('busy');

    $.ajax({
      url: base_url + '.json/task:processAI',
      type: 'POST',
      dataType: 'json',
      data: {
        provider: '', // use default
        prompt: prompt,
        context: context,
        'admin-nonce': GravAdmin.config.admin_nonce
      },
      success: function(resp){
        try { if (typeof resp === 'string') { resp = JSON.parse(resp); } } catch(e) {}
        let content = '';
        if (resp && resp.status === 'success') {
          content = resp.response || '';
          if (typeof content === 'object' && content.content) { content = content.content; }
        } else if (resp && resp.response) {
          content = resp.response;
        }
        if (typeof content === 'string' && content.trim().length) {
          input.val(content.trim()).trigger('change');
        } else {
          alert('AI response was empty or invalid.');
        }
      },
      error: function(xhr){
        const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'AI request failed';
        alert(msg);
      },
      complete: function(){
        btn.removeAttr('disabled').removeClass('busy');
      }
    });
  });

  $(document).on('click', '.seomagic-reindex', function(event) {
    event.preventDefault();
    // Redirect to dashboard and auto-start scan for consistent UX + progress bar
    const dest = GravAdmin.config.base_url_relative + '/seo-magic?start=full';
    window.location.href = dest;
  });

  // No separate QuickTray button for changed; use in-dashboard button instead

  $(document).on('click', '.seomagic-delete', function(event) {
    event.preventDefault();
    const url = base_url + '.json/action:removeDataSEOMagic/admin-nonce:' + GravAdmin.config.admin_nonce;
    return request(url, function(){ window.location.href = GravAdmin.config.base_url_relative + '/seo-magic'; });
  });

})(jQuery));
