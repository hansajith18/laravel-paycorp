# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Email security reports privately to: **hansajith18@gmail.com**

Include:
- A description of the vulnerability
- Steps to reproduce
- Potential impact
- Any suggested fix

You will receive a response within 72 hours. Please allow time for a patch before any public disclosure.

## Security Design

This package follows PCI DSS safe-handling principles:

- Raw card numbers and CVVs are **never stored** — only masked card numbers (e.g. `456445****4564`)
- All outgoing API requests are signed with HMAC-SHA256
- TLS verification is enforced on all HTTP connections (`verify: true`)
- Refund API credentials are AES-256-CBC encrypted before transmission
- Gateway audit logs sanitise all sensitive fields before persisting
- The `toSafeArray()` response method explicitly excludes cardholder name, raw expiry, and internal comments

## Known Non-Issues

- The `PAYCORP_HMAC_SECRET` and `PAYCORP_AUTH_TOKEN` values are sent as HTTP headers — this is by design and required by the Paycorp API specification. Ensure your environment variables are kept secret.
