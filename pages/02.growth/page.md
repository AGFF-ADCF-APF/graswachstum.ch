---
title: Graswachstum
published: true
hide_page_title: false
show_sidebar: true
hide_git_sync_repo_link: false
media_order: 'Graswachstumskurve_2024.svg,Graswachstum_2024KW18_2.svg'
featherlight:
    active: true
---

![Graswachstumskarte_aktuell](/uploads/Graswachstumskarte_aktuell.svg "Graswachstumskarte_aktuell")

![Graswachstumskurve_2024](/uploads/Graswachstumskurve_2024.svg "Graswachstumskurve_2024")

{% for image in page.find('/gallery/2021/28-09').media %}
  {{ image.html() | raw }}
{% endfor %}