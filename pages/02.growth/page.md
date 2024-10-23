---
title: Graswachstum
published: true
twig_first: true
process:
    markdown: true
    twig: true
hide_page_title: false
show_sidebar: false
hide_git_sync_repo_link: false
media_order: 'Graswachstumskurve_2024.svg,Graswachstum_2024KW18_2.svg'
featherlight:
    active: true
---

![Graswachstumskarte_aktuell](/uploads/Graswachstumskarte_aktuell.svg "Graswachstumskarte_aktuell")

<iframe src="/uploads/Graswachstumskurve_ohneLegende_2024.html" style="width:100%; height:600px;" >


[ui-accordion independent=true open=none]
[ui-accordion-item title="Vergangene Graswachstumskarten"]
{% for key,image in page.find('/uploads/archive/').media %}
  {{ image.html() | raw }}
{% endfor %}
[/ui-accordion-item]
[/ui-accordion]