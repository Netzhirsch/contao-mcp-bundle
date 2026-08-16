# Security Policy

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Two private channels:

1. **GitHub Security Advisories** — "Report a vulnerability" on the
   [Security tab](https://github.com/Netzhirsch/contao-mcp-bundle/security/advisories/new).
   Preferred: the discussion stays attached to the repository and a fix can be
   prepared before anything becomes public.
2. **E-mail** — <kalus@netzhirsch.de>, subject line starting with
   `[SECURITY] contao-mcp-bundle`.

Helpful in a report: affected version, what an attacker gains, and the smallest
reproduction you have (a request, a tool call, a code path). A pointer to the
line is plenty — no polished exploit required.

## What to expect

| | |
|---|---|
| Acknowledgement | within 2 working days |
| Initial assessment | within 5 working days |
| Fix for a confirmed, exploitable issue | as a patch release, as soon as it is ready and verified |

We will tell you honestly whether we consider a report exploitable — including
when we do not, and why.

## Credit

Reporters are named in the [CHANGELOG](CHANGELOG.md) and in the release notes
unless they prefer otherwise. Just say so in the report.

## Supported versions

| Version | Supported |
|---|---|
| 1.x | ✅ |
| < 1.0 | ❌ (pre-release, never distributed publicly) |

Security fixes go into the current 1.x line. Run `composer update
netzhirsch/contao-mcp-bundle` to receive them.

## Scope

In scope: the MCP endpoint and its tools, the OAuth 2.1 implementation, the
backend-permission parity layer, the licence verification, and the bundle's
backend modules.

Out of scope: vulnerabilities in Contao itself (please report those to
[Contao](https://contao.org/en/security.html)), and findings that require an
attacker to already be a Contao administrator — an administrator can change the
system anyway.

## Known, accepted limitations

These are design trade-offs, not oversights — a report on them is welcome but
will likely be answered with this section:

- **The licence gate is PHP source on the customer's server.** It can be patched
  out; that breaks on every `composer update` and is unambiguous circumvention.
  The real protection is the signature: no valid token without our secret key.
- **`auth_mode=none`** disables authentication *and* the per-user permission
  layer on purpose. It exists for private networks and local development. On a
  publicly reachable site, use `auth_mode=oauth`.
