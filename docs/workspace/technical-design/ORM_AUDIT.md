# Code Review: PlatformUserRepository and ORM Usage Patterns

**Scope:** Analysis of the current implementation of `PlatformUserRepository` and its alignment with Semitexa ORM capabilities.

---

## 1. Identified Architectural Deviations

| Issue | Description | Impact | Recommendation |
|:---|:---|:---|:---|
| **P1** | Ignoring `#[Filterable]` | `PlatformUserResource` does not declare filterable fields, despite ORM support via `FilterableResourceInterface`. | Add `#[Filterable]` to fields like `email`, `name`, and `is_active`. |
| **P2** | `fetchOneAsResource()` Usage | Repositories return resource models (e.g., `PlatformUserResource`) instead of domain entities (e.g., `User`), bypassing `DomainMappable`. | Use `fetchOne()` which automatically utilizes `toDomain()` mapping. |
| **P3** | Method Duplication | `findById()` and `findAll()` are manually re-implemented in `PlatformUserRepository` despite existing in `AbstractRepository`. | Remove manual implementations; inherit from `AbstractRepository`. |
| **P4** | Raw SQL in `search()` | The `search()` method uses raw SQL instead of the available `whereLike()` Query Builder method. | Refactor `search()` to use the ORM Query Builder. |
| **P5** | Static `OrmManager::run()` | Services use static `OrmManager::run()` calls instead of Dependency Injection for the database adapter. | Inject `DatabaseAdapterInterface` via DI; eliminate static "bridge" services. |
| **P6** | Manual Hydration | Repositories manually loop and hydrate results instead of using `fetchAll()` internals. | Leverage `AbstractRepository::fetchAll()` which handles hydration automatically. |

---

## 2. Proposed Root Solution

To align with the "One Way" architectural strategy, the `DatabaseAdapterInterface` must be injected directly into repositories via the DI container.

### Key Changes:
1. **Eliminate Boilerplate:** Static wrapper services (e.g., `PlatformUserService`) become redundant and should be removed.
2. **Contract Alignment:** `#[SatisfiesRepositoryContract]` should be applied directly to the repository implementation.
3. **Domain-Centricity:** Repository interfaces in `Domain/Repository/` must return Domain Models (`User`, `Role`), not Resource Models.
4. **Metadata usage:** Resources must implement `FilterableResourceInterface` and declare `#[Filterable]` fields.

### Refactored Repository Example

```php
#[SatisfiesRepositoryContract(of: UserRepositoryInterface::class)]
class PlatformUserRepository extends AbstractRepository implements UserRepositoryInterface
{
    protected function getResourceClass(): string
    {
        return PlatformUserResource::class;
    }

    // findById() and findAll() are inherited from AbstractRepository
    // and automatically return domain objects.

    public function findByEmail(string $email): ?User
    {
        return $this->select()
            ->where('email', '=', $email)
            ->fetchOne();
    }

    public function search(string $term, int $limit = 50): array
    {
        return $this->select()
            ->whereLike('name', "%{$term}%")
            ->orWhere('email', 'LIKE', "%{$term}%")
            ->limit($limit)
            ->fetchAll();
    }
}
```

### Refactored Repository Interface

```php
interface UserRepositoryInterface
{
    public function findById(string $id): ?User;
    public function findByEmail(string $email): ?User;
    public function findAll(int $limit = 100): array;
    public function search(string $term, int $limit = 50): array;
    public function save(object $entity): void;
    public function delete(object $entity): void;
}
```

---

## 3. Implementation Requirements

- **DI Availability:** Ensure `DatabaseAdapterInterface` is available in the DI container for the readonly graph.
- **ORM Core Update:** Verify `AbstractRepository` correctly handles domain model returns via `fetchOne()` and `fetchAll()`.
- **Cleanup:** Remove redundant service layers once repositories are fully DI-compatible.
