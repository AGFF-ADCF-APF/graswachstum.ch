function headerHeight() {
  return document.getElementById('notice-block').offsetHeight;
}

function isFixedHeader() {
  return this.headerHeight() <= window.scrollY;
}

function scrollPosition() {
  return ((window.scrollY - this.headerHeight()) <= 50);
}