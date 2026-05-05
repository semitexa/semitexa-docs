# Technical Design: Automated Testing based on PayloadDTO (`semitexa-testing`)

**Revision:** v2.0  
**Status:** Architecture Locked  
**Core Package:** `semitexa-testing`

---

## 1. LLM Context & Summary
This document defines the technical design for automated testing of `PayloadDTO` classes in the Semitexa framework. It leverages the **Single Source of Truth (SSOT)** nature of DTOs (annotated with one of the access attributes — `#[AsPublicPayload]`, `#[AsProtectedPayload]`, or `#[AsServicePayload]`) to auto-generate test cases via Reflection. It separates production metadata from test metadata using the `#[TestablePayload]` attribute.

---

## 2. Core Concept
`PayloadDTO` defines the request shape. `semitexa-testing` reads these rules at test runtime to generate a matrix of test cases, covering:
- Access control (`#[RequiresAuth]`).
- HTTP methods (405 validation).
- Type enforcement (422 validation).
- Boundary/Monkey testing (malformed data, large payloads).

---

## 3. Declarative Metadata

### 3.1. `#[TestablePayload]`
Used to configure testing strategies and context for a specific payload.
```php
#[AsServicePayload(path: '/api/v1/payments', methods: ['POST'], responseWith: PaymentResource::class)]
#[TestablePayload(
    strategies: [ParanoidProfileStrategy::class],
    context: [
        'auth_header'    => 'Authorization',
        'auth_scheme'    => 'Bearer',
        'token_provider' => TestTokenProvider::class,
    ]
)]
class PaymentPayload implements PayloadInterface {}
```

### 3.2. `#[TestablePayloadPart]`
Used on traits or nested objects to carry strategy metadata that merges into the parent payload.

---

## 4. Transport Layer
Strategies use a `TransportInterface` abstraction to send requests.

| Transport | Mode | Description |
|:---|:---|:---|
| **In-Process** | Default | Direct call to `Application::handleRequest()`. Fast, no network overhead. |
| **HTTP** | Optional | Real HTTP requests to a running Swoole server. Tests headers/framing. |

---

## 5. Built-in Testing Strategies

| Strategy | Goal | Expected Result |
|:---|:---|:---|
| `SecurityStrategy` | Verify auth requirements. | 401 for missing/invalid tokens. |
| `HttpMethodStrategy` | Test disallowed methods. | 405 Method Not Allowed. |
| `TypeEnforcementStrategy`| Test hydration rejection. | 422 for type mismatches (requires strict mode). |
| `MonkeyTestingStrategy` | Stress test with chaotic data. | Any 4xx is OK; 5xx is a FAILURE. |
| `MemoryLeakStrategy` | Measure memory usage over N requests. | Failure if memory growth exceeds threshold. |
| `CoroutineIsolationStrategy`| Detect cross-request leaks. | Failure if data from Request A appears in Request B. |

---

## 6. Testing Profiles
Profiles aggregate multiple strategies for convenience:
- `StandardProfileStrategy`: Security + Method + Basic Type Omission.
- `StrictProfileStrategy`: Standard + Full Type Mutation.
- `ParanoidProfileStrategy`: Strict + Monkey + Memory + Isolation.

---

## 7. Execution & Orchestration
- **Trait:** `TestsPayloads` provides `$this->assertPayloadContract(PayloadClass::class)`.
- **Orchestrator:** `PayloadContractTester` resolves strategies, generates cases, and executes them via transport.
- **Deduplication:** Identical strategies across multiple profiles run only once.

---

## 8. Failure Reporting (AI-Native)
Failures generate structured JSON artifacts in `var/test-reports/`. These artifacts include:
- `payload`: FQCN of the failed DTO.
- `strategy`: FQCN of the failed strategy.
- `failed_field`: The specific field that caused the failure.
- `handler_file`/`line`: Location of the business logic handler for direct navigation.

---

## 9. Core Requirements
1. **Strict Hydration:** `semitexa-core` must support a `strictTypes` flag in `RequestDtoHydrator`.
2. **Metadata Discovery:** `PayloadMetadataFactory` uses Reflection to build the `PayloadMetadata` DTO used by strategies.
