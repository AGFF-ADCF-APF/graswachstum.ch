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

![Graswachstumskurve_2024](/uploads/Graswachstumskurve_2024.svg "Graswachstumskurve_2024")

<iframe src="https://docs.google.com/spreadsheets/d/1n5WjLc8cLvXpLaB8jMfcO3pimrTKk6NC2Co5JbvRS5E/pubhtml?gid=1445871794&amp;single=true&amp;widget=true&amp;headers=false"></iframe>

[ui-accordion independent=true open=none]
[ui-accordion-item title="ältere Graswachstumskarten"]
{% for key,image in page.find('/uploads/archive/').media %}
  {{ image.html() | raw }}
{% endfor %}
[/ui-accordion-item]
[/ui-accordion]