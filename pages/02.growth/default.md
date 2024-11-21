---
title: Graswachstumskurve
published: true
twig_first: true
process:
    markdown: true
    twig: true
hide_page_title: false
show_sidebar: false
hide_git_sync_repo_link: false
media_order: 'IMG_20190509_061559.jpg,IMG_20190509_061615.jpg'
featherlight:
    active: true
menu_before_icon: tabler/chart-area-line.svg
root_of_blog: true
content:
    items:
        - '@self.children'
    limit: 10
    order:
        by: date
        dir: desc
menu: Kurven
hero:
    image: IMG_20190509_061559.jpg
---

<iframe src="/uploads/Graswachstumskurve_ohneLegende_2024.html" style="width:100%; height:600px;" ></iframe>
[Interaktive Grafik in neuem Tab öffnen](/uploads/Graswachstumskurve_ohneLegende_2024.html?target=_blank)


[ui-accordion independent=true open=none]
[ui-accordion-item title="Bedienung der interaktiven Graswachstumskurve"]
Die schwarze, gestrichelte Linie bildet sich aus dem Durchschnitt aller Graswachstumsmeldungen des Jahres. 
Die rote, gestrichelte Linie ist ein langjähriger Mittelwert für das CH-Mittelland.


### Tipps:
- Klick auf Standort: ein-/ausblenden
- Doppelklick auf Standort: alle anderen Standorte werden ausgeblendet
- Kamerasymbol: angezeigte Grafik lokal speichern
[/ui-accordion-item]
[ui-accordion-item title="Graswachtumsdiagramm statisch (wie bisher)"]
![Graswachstumskurve_2024](/uploads/Graswachstumskurve_2024.svg "Graswachstumskurve_2024")
[/ui-accordion-item]
[/ui-accordion]


![Graswachstumskarte_aktuell](/uploads/Graswachstumskarte_aktuell.svg "Graswachstumskarte_aktuell")


[ui-accordion independent=true open=none]
[ui-accordion-item title="Vergangene Graswachstumskarten"]
{% for key,image in page.find('/uploads/archive/').media %}
  {{ image.html() | raw }}
{% endfor %}
[/ui-accordion-item]
[/ui-accordion]