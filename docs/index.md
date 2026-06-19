---
layout: home

hero:
  name: Billify
  text: Billing for Laravel hosting systems
  tagline: A PostgreSQL-backed billing engine for subscriptions, proration, usage metering, and invoicing — built so a charge is never billed twice and never lost when your accounting system is down.
  actions:
    - theme: brand
      text: Get started
      link: /guide/introduction
    - theme: alt
      text: Quickstart
      link: /guide/quickstart
    - theme: alt
      text: View on GitHub
      link: https://github.com/Thiritin/billify

features:
  - title: Charges are the source of truth
    details: A charge accrues as pending and flips to invoiced only when a driver confirms the invoice. If the accounting system throws, charges stay pending and retry next run. No revenue lost.
  - title: No double billing, enforced by Postgres
    details: A GiST EXCLUDE constraint on billed windows makes it physically impossible to bill the same service period twice for an item and dimension.
  - title: Money is integer minor units
    details: All money math runs through brick/money. No floats. Invoice totals reconcile because each line total sums into the invoice.
  - title: Subscriptions with real anchoring
    details: subscribe / renew / changePlan / cancel, with signup or fixed-day anchoring and first-period policies (prorate, prorate + full, full, free until anchor).
  - title: Usage and hourly metering
    details: Record usage per dimension, then roll it up in arrears with allowance, cap, and aggregation. Quotes show metered lines flagged as estimated.
  - title: Pluggable invoice and tax drivers
    details: Ships a database invoice driver and a multi-jurisdiction tax resolver (EU OSS via ibericode + VIES, Switzerland, UK, your own). Bind your accounting integration as a driver.
---
