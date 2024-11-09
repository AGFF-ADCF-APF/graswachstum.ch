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
    buttons:
        submit:
            type: submit
            value: Senden
        reset:
            type: reset
            value: Zurücksetzen
    process:
        captcha: true
        save:
            fileprefix: contact-
            dateformat: Ymd-His-u
            extension: txt
        email:
            from: '{{ config.plugins.email.from }}'
            to:
                - '{{ config.plugins.email.to }}'
                - '{{ form.value.email }}'
            subject: '[Kontaktformular] {{ form.value.name|e }}'
            body: '{% include ''forms/data.html.twig'' %}'
        message: 'Danke für deine Nachricht! Du solltest eine Kopie in deine Mailbox erhalten.'
hide_page_title: false
show_sidebar: true
hide_git_sync_repo_link: true
---

