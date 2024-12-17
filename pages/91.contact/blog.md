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
            value: Submit
        reset:
            type: reset
            value: Reset
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
        message: 'Thank you for getting in touch!'
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
---

Kontaktformular