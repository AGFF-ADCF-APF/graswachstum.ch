---
title: Kontakt
form:
    name: contact
    fields:
        name:
            label: Name
            placeholder: 'Enter your name'
            autocomplete: 'on'
            type: text
            validate:
                required: true
        email:
            label: Email
            placeholder: 'Enter your email address'
            type: email
            validate:
                required: true
        message:
            label: Message
            placeholder: 'Enter your message'
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
            from: '{{ config.plugins.email.from }}'
            to:
                - '{{ config.plugins.email.to }}'
                - '{{ form.value.email }}'
            body: '{% include ''forms/data.html.twig'' %}'
        message: 'Thank you for getting in touch!'
hide_page_title: false
show_sidebar: true
hide_git_sync_repo_link: false
---

# Kontaktformular 
[safe-email autolink="true"]webmaster@graswachstum.ch[/safe-email]
