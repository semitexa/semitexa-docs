# Audit: semitexa-testing Package + CoroutineIsolationStrategy Proposal

**Date:** 2026-03-10  
**Package Version:** v1.0.2  
**Scope:** Architecture, implementation, code quality, and a new strategy for detecting data leaks between Swoole coroutines/workers.

---

## 1. Audit of Current Implementation

### 1.1. Architectural Assessment

**Verdict: Well-designed.** The package follows SOLID principles, maintains clear separation of concerns, and features a robust extension system.

**Strengths:**
- **Metadata Separation:** Distinction between production access attributes (`#[AsPublicPayload]` / `#[AsProtectedPayload]` / `#[AsServicePayload]`) and test metadata (`#[TestablePayload]`) — test dependencies never enter the production container.
- **Strategy Pattern:** Implementation of `canRun()` and `skipReason()` allows elegant handling of `markTestSkipped()` semantics.
- **Profile Expansion:** Recursive expansion with de-duplication prevents redundant execution.
- **Transport Abstraction:** Supports `InProcessTransport` for speed and `HttpTransport` for full integration testing.
- **Immutable Models:** Read-only DTO models ensure data integrity for all data objects.
- **Context Dictionary Pattern:** Strategies read only the keys they require, ignoring unknown configurations.

**Architectural Decisions to Retain:**
- **Stateless Strategies:** Strategies are instantiated fresh via `new $strategyClass()` for each run.
- **Safe Defaults:** Fail-safe by default, with optional fail-fast behavior.
- **Cached Metadata:** `PayloadMetadataFactory` utilizes a class-name-based cache.

---

### 1.2. Identified Issues

| ID | Severity | Component | Issue | Recommendation |
|:---|:---|:---|:---|:---|
| **P1** | MEDIUM | `PayloadMetadataFactory` | Static cache is not cleared between test classes. | Call `PayloadMetadataFactory::clearCache()` in `PhpUnitExtension::bootstrap()` or before each run. |
| **P2** | LOW | `TestingStrategyInterface` | `TypeEnforcementStrategy` holds state in `$cachedValidToken`. | Document that strategies MUST be stateless or safe for single-use instantiation. |
| **P3** | MEDIUM | `MemoryLeakStrategy` | Missing auth headers for `#[RequiresAuth]` endpoints; results in 401s during measurement. | Inject auth headers via `token_provider`. Skip if auth is required but no provider is present. |
| **P4** | LOW | `MemoryLeakStrategy` | Aggressive 1KB leak threshold (for 20 requests) may cause false positives. | Increase default threshold to 4KB or make it configurable via `context`. Use linear regression for trend analysis. |
| **P5** | LOW | `HttpTransport` | Duplicated `Content-Type` header in cURL calls. | Check if `Content-Type` is already set in `$case->headers` before adding the default. |
| **P6** | **HIGH** | `RequestDtoHydrator` | `strictTypes` is a worker-global static flag. | Risk of contamination during coroutine switches. Move strict mode flag to `RequestContext` (per-request). |
| **P7** | LOW | `FailureReporter` | Potential filename collisions during parallel test execution. | Add a random suffix or incremental counter to failure report filenames. |
| **P8** | MEDIUM | `PhpUnitExtension` | Fallback `new Application()` instantiation lacks error context. | Wrap in try/catch to provide a descriptive error message if the container fails to boot. |
| **P9** | LOW | `MonkeyTestingStrategy` | Payload size description (10MB) does not match actual implementation (~1MB). | Update the description or increase the payload multiplier. |
| **P10** | MEDIUM | `TestsPayloads` | `assertPayloadContract()` stops execution at the first failure. | Aggregate all failures into a single failure message for better visibility. |

---

## 2. CoroutineIsolationStrategy Proposal

### 2.1. The Problem: State Leaks in Swoole
Swoole processes requests within coroutines inside worker processes. Each worker maintains:
- **Readonly Container:** Shared across all requests.
- **Mutable Prototypes:** Cloned per request.
- **RequestScopedContainer:** Cache cleared after each request.
- **Static Singletons:** Context stores (`Auth`, `Coroutine`, `Locale`) with dual-mode support.

**Potential Leak Vectors:**
- **Static Properties:** Readonly services modifying `static $cache`.
- **Mutable State in Readonly Instances:** Storing request-specific data in instance properties.
- **Cleanup Failures:** `RequestScopedContainer::reset()` or `Coroutine::getContext()` cleanup bypassed due to exceptions.
- **Dirty Connections:** Database transactions left open after an error.

### 2.2. Strategy Goals
The `CoroutineIsolationStrategy` verifies three categories of isolation:

#### Category A: Cross-Request State Leak
Sends two sequential requests (A and B) with unique markers to the same endpoint. It verifies that Response B does **not** contain data from Request A.

#### Category B: Auth Context Isolation
Sends requests from two different users (User A and User B). Verifies that User B cannot see identity-specific data belonging to User A.

#### Category C: Concurrent Request Isolation
*Available only via `HttpTransport`.* Sends N parallel requests with unique markers and verifies that each response contains only its own marker.

### 2.3. Technical Design

#### Key Symbols
- `src/Strategy/CoroutineIsolationStrategy.php`
- `src/Data/IsolationMarker.php`

#### IsolationMarker (DTO)
Generates unique IDs (e.g., `ISO_a1b2c3d4`) to be injected into string fields of the payload.

#### Strategy Configuration (`context`)
- `isolation_marker_field`: Field for marker injection (default: first string property).
- `isolation_pairs`: Number of sequential A/B pairs to test (default: 3).
- `isolation_concurrent`: Parallel request count for Category C (default: 0).
- `isolation_identity_field`: Response field identifying the authenticated user.

### 2.4. Limitations and Constraints
- **InProcessTransport:** Only Categories A and B are supported (sequential execution).
- **Validation Errors:** If the endpoint rejects synthetic markers (422), the isolation check is skipped if no body is returned.
- **Data persistence:** The strategy does not clear the database between requests; markers are prefixed with `ISO_` for easy cleanup.

---

## 3. Implementation Roadmap

### 3.1. Immediate Fixes (Minor)
- Aggregate failures in `TestsPayloads` (P10).
- Add auth headers and configurable thresholds to `MemoryLeakStrategy` (P3, P4).
- Fix header duplication in `HttpTransport` (P5).
- Correct body size descriptions (P9) and add random suffixes to report filenames (P7).

### 3.2. Structural Improvements (Patch/Docs)
- Clear `PayloadMetadataFactory` cache per run (P1).
- Improve error handling in `PhpUnitExtension` (P8).
- Document worker-global nature of `RequestDtoHydrator::$strictTypes` (P6).

### 3.3. New Features
- Implement `CoroutineIsolationStrategy`.
- Add `test:init` CLI command for quick setup.
- Introduce `getDefaultContext()` for `TestingProfileInterface` to allow profiles to define default strategy settings.
