---
title: Kontakt
date: '23:45 29-04-2024'
form:
    name: contact
    fields:
        name:
            label: Name
            placeholder: 'Gib deinen Namen ein'
            autocomplete: 'on'
            type: text
            validate:
                required: true
        email:
            label: Email
            placeholder: 'Gib deine E-Mail-Adresse ein'
            type: email
            validate:
                required: true
        message:
            label: Message
            placeholder: 'Schreib deine Nachricht an uns'
            type: textarea
            validate:
                required: true
        g-recaptcha-response:
            label: Captcha
            type: captcha
            recaptcha_not_validated: 'Captcha not valid!'
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
hide_page_title: false
show_sidebar: true
hide_git_sync_repo_link: false
---

