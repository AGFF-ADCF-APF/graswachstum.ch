---
title: 'Météo des prés'
published: true
visible: true
hide_page_title: true
modular_content:
    items: '@self.modular'
    order:
        by: folder
        dir: asc
content:
    items:
        - '@self.children'
    limit: 7
    order:
        by: date
        dir: desc
    pagination: true
    url_taxonomy_filters: true
    filter:
        published: true
hide_post_summary: true
post_icon: calendar-o
hide_post_date: true
hide_post_taxonomy: false
feed:
    description: Graswachstumsberichte
    limit: 10
show_sidebar: true
hide_git_sync_repo_link: false
hero_scroll: false
continue_link_as_button: true
media_order: csm_img_7352_f4e25fef1d.webp
root_of_blog: true
hero:
    image: csm_img_7352_f4e25fef1d.webp
    buttons:
        -
            text: '[svg-icon icon="world" /] Carte de croissance'
            link: '/growth#karte'
            classes: 'bg-primary text-white'
        -
            text: '[svg-icon icon="chart-area-line"]  Courbe de croissance'
            link: '/growth#graswachstumskurve'
            classes: 'bg-primary text-white'
        -
            text: 'Original Météo des prés (Prométerre)'
            link: 'https://www.prometerre.ch/meteodespres'
            classes: 'bg-primary text-white'
show_breadcrumbs: true
show_pagination: true
author: 'Martin Zbinden'
sitemap:
    lastmod: '17-12-2024 20:21'
aura:
    author: zbma
shortcode-citation:
    items: cited
    reorder_uncited: true
menu_before_icon: tabler/send.svg
---

