function headerHeight() {
  return document.getElementById('notice-block').offsetHeight;
}

function isFixedHeader() {
  return this.headerHeight() <= window.scrollY;
}

function scrollPosition() {
  return ((window.scrollY - this.headerHeight()) <= 50);
}

function scrollspy(hasFixedHeader) {
  return {
    visible: false,
    activeSection: '',
    fixedHeader: false,
    headerH: 0,
    sections: [],
    showThreshold: 200,
    hasFixedHeader: hasFixedHeader || false,

    init() {
      var links = this.$el.querySelectorAll('a[href^="#"]');
      var self = this;
      this.sections = [];
      links.forEach(function(link) {
        var id = link.getAttribute('href').substring(1);
        if (id) self.sections.push(id);
      });
      this.onScroll();
    },

    onScroll() {
      this.visible = window.scrollY > this.showThreshold;

      // Track header height for positioning below fixed header
      if (this.hasFixedHeader) {
        var header = document.querySelector('header');
        if (header) {
          this.fixedHeader = header.classList.contains('fixed');
          this.headerH = this.fixedHeader ? header.offsetHeight : 0;
        }
      }

      // Find active section
      var offset = this.headerH + (this.visible ? this.$el.offsetHeight : 0) + 20;
      var active = '';

      for (var i = this.sections.length - 1; i >= 0; i--) {
        var el = document.getElementById(this.sections[i]);
        if (el && el.getBoundingClientRect().top <= offset) {
          active = this.sections[i];
          break;
        }
      }

      this.activeSection = active;
    },

    scrollToSection(id) {
      var el = document.getElementById(id);
      if (!el) return;
      var header = document.querySelector('header');
      var headerH = (header && header.classList.contains('fixed')) ? header.offsetHeight : 0;
      var scrollspyH = this.$el.offsetHeight;
      var top = el.getBoundingClientRect().top + window.scrollY - headerH - scrollspyH;
      window.scrollTo({ top: top, behavior: 'smooth' });
    }
  };
}