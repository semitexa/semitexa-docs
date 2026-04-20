# Technical Design: Tenancy and Local Domain Testing Plan

**Status:** Implementation Roadmap  
**Scope:** Local Domain Infrastructure, Multi-layer Tenancy System

---

## 1. LLM Context & Summary
This document provides a testing plan and improvement proposals for the tenancy and local-domain infrastructure in the Semitexa platform. It covers host resolution using `dnsmasq`, multi-layered tenancy resolution (Organization, Locale, Environment, Theme), and the `local-domain:*` CLI commands for easier local host management.

---

## 2. Infrastructure Overview

### 2.1. Local Domain Configuration
- **dnsmasq:** Running on `127.0.0.1:5553`.
- **nginx proxy:** Proxies all `Host:` headers to Swoole on port 9502.
- **systemd-resolved:** Drop-in config at `/etc/systemd/resolved.conf.d/semitexa.conf` routing `.test` domains to dnsmasq.
- **Blocker:** Hardcoded `DNS=` lines in `/etc/systemd/resolved.conf` must be removed to prioritize the local `.test` resolver.

### 2.2. Multi-layer Tenancy
Resolution occurs in several layers:
- **Organization:** Subdomain-based (e.g., `acme.semitexa.test`).
- **Locale:** Path-based (e.g., `/en/`, `/uk/`).
- **Environment:** Custom header `X-Environment`.
- **Theme:** Custom header `X-Theme`.

---

## 3. Implementation Phases

### Phase 1: Fix Local Domain Resolution
1. **Clean up `resolved.conf`**: Remove hardcoded DNS servers.
2. **Enable Wildcard DNS**: Ensure `address=/semitexa.test/127.0.0.1` is set in dnsmasq (covers all subdomains).
3. **NetworkManager Detection**: Improve `bin/semitexa` to detect stale global DNS config on host systems.

### Phase 2: Enable Tenancy in `.env`
Add required variables to `.env` to activate the tenancy system:
```env
TENANCY_ENABLED=true
TENANCY_BASE_DOMAIN=semitexa.test
TENANTS=acme:Acme Corporation:active,beta:Beta Inc:active
LOCALE_ENABLED=true
LOCALE_STRATEGY=path
LOCALE_SUPPORTED=en,uk,de,pl,ru
```

---

## 4. Testing Matrix

| Category | Test Case | Command |
|:---|:---|:---|
| **Local Domain** | Base/Subdomain resolution | `resolvectl query acme.semitexa.test` |
| **Proxy** | Host header passthrough | `curl -s -o /dev/null -w '%{http_code}' http://acme.semitexa.test/` |
| **Tenancy** | Subdomain resolution | `curl -s http://acme.semitexa.test/` (Response: Tenant `acme`) |
| **Locale** | Path prefix resolution | `curl -s http://acme.semitexa.test/uk/` (Response: Locale `uk`) |
| **Full Stack**| Multilayer headers | `curl -s -H 'X-Environment: dev' -H 'X-Theme: dark' http://acme.semitexa.test/uk/` |

---

## 5. Proposed Improvements

### 5.1. CLI Commands
- `bin/semitexa local-domain:add`: Register a local `.test` domain.
- `bin/semitexa local-domain:list`: Inspect registered local domains.
- `bin/semitexa local-domain:remove`: Remove a registered local domain.
- `bin/semitexa local-domain:mode`: Inspect or switch the shared local-domain backend.

### 5.2. Diagnostic Endpoint
- `GET /~tenant/debug`: Available in `APP_ENV=dev`. Returns JSON with the full resolved context (org, locale, env, theme) and local-domain status.

### 5.3. Custom Domain Support
Map a full domain directly to a tenant ID:
```env
TENANT_ACME_DOMAIN=acme-shop.test
```
The system will prioritize `DomainStrategy` before falling back to subdomains.
