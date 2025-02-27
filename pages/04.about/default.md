---
title: 'Über uns'
published: true
show_sidebar: false
hide_git_sync_repo_link: false
hide_page_title: false
media_order: 'Agridea_sanstexte.png,Agroscope_hoch.svg.png,HAFL.svg.png,logo-sg.png,Logo_Inforama_Pantone_co_300dpi.jpg,Logo-Graswachstum-neu-2024-web.webp,Logo-Graswachstum-neu-2024-web.svg'
hero:
    image: IMG_20190322_102544.jpg
    image_alignment: object-top
root_of_blog: true
content:
    items:
        - '@self.children'
    limit: 10
    order:
        by: date
        dir: desc
---

## Über die Messtandorte
### Standorte Posieux und Sorens
Agroscope misst seit dem Jahr 2000 das Graswachstum auf dem Agroscope Versuchsbetrieb in Posieux und auf dem Biobetrieb «Schulbauernhof Sorens» und stellt die aktuellen Wachstumszahlen als Referenzwerte für interessierte Kreise ins Netz. 
Webseite:  [Agroscope - Graswachstum](https://www.agroscope.admin.ch/agroscope/de/home/services/dienste/futtermittel/weidemanagement/graswachstum.html)
Methode: vereinfachte Methode abgeleitet von Corrall and Fenlon (1978)  ([Link](https://www.agroscope.admin.ch/agroscope/de/home/services/dienste/futtermittel/weidemanagement/graswachstum/erhebungsmethode.html))

 ![Agroscope_hoch.svg](Agroscope_hoch.svg.png?resize=200,200 "Agroscope_hoch.svg")

### Beschreibungen weiterer Standorte  
_**folgen bald**_


## Datensammlung und Aufbereitung
1. Die beteiligten Stellen und Betriebe melden selbständig das Graswachstum mit einem vorbereiteten Google Formular. 
2. Die gesammelten Daten werden als Google Sheet im Internet publiziert.
3. Ein [R-Skript ](https://github.com/AGFF-ADCF-APF/r-grassgrowth) ruft die Daten ab und erstellt die Grafiken. 
4. R lädt die Ergebnisse per FTP automatisch auf diese Internetseite hoch.

Interesse an einer Zusammenarbeit? [Schreib uns!](/contact?class=button)




## Medienpartner
[Bauernzeitung – Serie Graswachstum](http://www.bauernzeitung.ch/graswachstum-serie) 
![Logo-Graswachstum-neu-2024-web](Logo-Graswachstum-neu-2024-web.svg?resize=200,200 "Logo-Graswachstum-neu-2024-web")
