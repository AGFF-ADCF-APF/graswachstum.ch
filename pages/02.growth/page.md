---
title: Graswachstum
published: true
twig_first: true
process:
    twig: true
hide_page_title: false
show_sidebar: true
hide_git_sync_repo_link: false
media_order: 'Graswachstumskurve_2024.svg,Graswachstum_2024KW18_2.svg'
featherlight:
    active: true
---

![Graswachstumskarte_aktuell](/uploads/Graswachstumskarte_aktuell.svg "Graswachstumskarte_aktuell")

![Graswachstumskurve_2024](/uploads/Graswachstumskurve_2024.svg "Graswachstumskurve_2024")

ältere Karten:
{% for image in page.find('/uploads/archive/').media %}
  {{ image.html() | raw }}
{% endfor %}