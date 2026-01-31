---
title: Kontakt
form:
    name: contact
    fields:
        name:
            label: Name
            placeholder: 'Wie heisst du?'
            autocomplete: 'on'
            type: text
            validate:
                required: true
        email:
            label: Email
            placeholder: 'Gib deine E-Mail-Adresse ein.'
            type: email
            validate:
                required: true
        message:
            label: Message
            placeholder: 'Was willst du uns sagen?'
            type: textarea
            validate:
                required: true
        g-recaptcha-response:
            label: Captcha
            type: captcha
            recaptcha_not_validated: 'Captcha ungültig!'
    buttons:
        submit:
            type: submit
            value: Absenden
        reset:
            type: reset
            value: Zurücksetzen
    process:
        captcha: true
        save:
            fileprefix: contact-
            dateformat: Ymd-His-u
            extension: txt
            body: '{% include ''forms/data.txt.twig'' %}'
        email:
            subject: '[Site Contact Form] {{ form.value.name|e }}'
            body: '{% include ''forms/data.html.twig'' %}'
        message: 'Danke für deine Nachricht!'
root_of_blog: true
content:
    items:
        - '@self.children'
    limit: 10
    order:
        by: date
        dir: desc
published: true
sitemap:
    lastmod: '17-12-2024 20:11'
hero:
    display: true
    image: IMG_20170925_123125_HDR.jpg
    content: 'Kontaktiere uns auf verschiedenen Kanälen.'
    buttons:
        -
            text: Whatsapp
            link: 'https://chat.whatsapp.com/HWT0TodVZBuBDVAFVrUUbr'
            classes: 'bg-primary text-white'
        -
            text: Kontaktformular
            link: '#kontaktformular'
            classes: 'bg-primary text-white'
    height: 20px
feed:
    limit: 10
shortcode-citation:
    items: cited
    reorder_uncited: true
---

Fragen, Vorschläge, Bedenken?  
[Kontaktiere uns!](/contact?classes=button) [[fa=whatsapp /]](https://chat.whatsapp.com/HWT0TodVZBuBDVAFVrUUbr)

### Whatsapp-Gruppe "Graswachstumsberichte" 
[[fa=whatsapp /] Link zum Beitreten](https://chat.whatsapp.com/HWT0TodVZBuBDVAFVrUUbr) 
 
 

### Partner
[Bauernzeitung](https://www.bauernzeitung.ch/graswachstum-serie) 
[AGFF](https://www.agff.ch) 
[IG Weidemilch](https://www.weidemilch.ch) 
[Betriebe und Organisationen](/about)

## Kontaktformular