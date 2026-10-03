# Security policy

## Supported versions

Security fixes are released for the latest minor version. Please keep Blue Lens Analytics up to date.

| Version | Supported |
|---|---|
| 0.4.x | ✅ |
| < 0.4 | ❌ |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Email **security@creativebay.co.ke** with:
- a description of the issue and its impact;
- steps or a proof of concept to reproduce it;
- the plugin, WordPress and PHP versions involved.

We aim to acknowledge reports within **3 working days** and to release a fix for confirmed issues within **30 days**, depending on severity. We will credit you in the changelog unless you prefer otherwise. Please give us reasonable time to release a fix before any public disclosure.

## Scope

In scope: the plugin code in this repository, including the public collection endpoint `/wp-json/blue-lens/v1/collect`, the admin REST endpoints and the tracker scripts.

Out of scope: vulnerabilities in WordPress core, other plugins or themes, hosting configuration, and third-party services (MaxMind, DB-IP, social platforms), which should be reported to their owners.

## Hardening built into the plugin

- Prepared SQL statements everywhere, with table names passed as identifiers.
- Capability (`manage_blue_lens`) and REST nonce checks on every admin endpoint.
- The public endpoint accepts only same-site page URLs, limits body size (16 KB), events per request (25), requests per visitor per minute (with an object cache or APCu), and events per session (2,000).
- All input is validated and sanitised; all output is escaped. The embedded tracker data uses `JSON_HEX_TAG` to prevent script injection.
- No `eval`, no remote code loading, and no raw IP addresses or form values stored.
