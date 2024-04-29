---
title: Sidebar
routable: false
visible: false
cache_enable: false
hide_page_title: false
show_sidebar: true
hide_git_sync_repo_link: false
forms:
    APopupForm:
        action: /tingleform
        fields:
            -
                name: subject
                type: text
                label: 'Subject of message'
                placeholder: 'Write your subject here'
            -
                name: return
                type: email
                label: 'Return email address'
                validate:
                    required: true
                    message: 'Please include a return address'
            -
                name: content
                label: 'Content of the message'
                type: textarea
                placeholder: 'Say something nice'
                validate:
                    required: true
                    message: 'You have not sent a message'
        buttons:
            -
                type: Submit
                value: 'Send this Email'
            -
                type: Reset
                value: 'Reset the form'
        process:
            -
                save:
                    fileprefix: contact-
                    dateformat: Ymd-His
                    extension: txt
                    body: '{% include ''forms/data.txt.twig'' %}'
            -
                reset: true
---

## AGFF-Grasmessnetzwerk

[safe-email autolink="true"]webmaster@graswachstum.ch[/safe-email]  



Vorschläge, Bedenken oder Einspruch?  
[popup-form form=APopupForm classes=" 'myclass1', 'myclass2' "]Kontaktformular[/popup-form]

## Partner
[AGFF](https://www.agff.ch)  
[IG Weidemilch](https://www.weidemilch.ch)  

