---
title: "FAQ"
nav_order: 10
description: "Short answers about darvis/supplydrive-api: what it is, what you need, the login and the token, the statuses, failed calls, retries, password safety, testing."
faq: true
---

# Frequently asked questions

{% for item in site.data.faq %}
## {{ item.q }}

{{ item.a | markdownify }}
{% endfor %}
