---
title: 'BZBS Rheinhof'
date: '12:30 20-03-2025'
root_of_blog: true
content:
    items:
        - '@self.children'
    limit: 10
    order:
        by: date
        dir: desc
feed:
    limit: 10
shortcode-citation:
    items: cited
    reorder_uncited: true
sitemap:
    lastmod: '20-03-2025 12:30'
media_order: 'Salez_2025-02-25.jpeg,Salez_2025-03-18.JPG,Salez_2025-03-05.jpeg,Salez.JPG'
process:
    markdown: true
    twig: true
---

## Standort Salez
Webseite: [https://www.bzbs.ch/weiterbildung/landwirtschaft]/
Methode: Rising Plate Meter (mit Plattform Grasslandtools )

===

## Gallerie

    {% for image in page.media.images %}
    {% set filename = image.filename %}
        {% include 'partials/lightbox.html.twig' with {image: filename} %}
    {% endfor %}
