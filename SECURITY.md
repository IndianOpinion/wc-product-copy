# Security policy

## Reporting a problem

Email **hello@indianopinion.org** with the subject "Security:
wc-product-copy". Please include the version, what you found, how to
reproduce it, and what an attacker could do with it. Do not open a public
issue for a security problem.

We reply within 3 working days, agree a fix and a disclosure date with you,
and aim to release a fix within 30 days for anything serious. We credit
reporters who want to be named.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | yes |

## Scope notes

- Model output is untrusted. It is cleaned before it is stored and again
  before it is written to a product. A way to get a script, a link or an
  attribute through that cleaning is a security bug.
- Every admin action needs a nonce and a capability. A way around either is
  a security bug.
- The API key is never printed back. A way to read it from the admin or the
  CLI is a security bug.
