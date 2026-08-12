---
id: routing/parameterized
section: routing
slug: parameterized
title: Parameterized Route
summary: Path parameters with regex constraints, how the hydrator injects them, and why a default does not make a segment optional.
order: 30
locale: en
status: canonical
keywords:
  - requirements
  - defaults
  - PayloadHydrator
  - setter injection
---
# Parameterized Route

A path parameter is declared inline in the route pattern and constrained by `requirements` on the same access attribute. Nothing else registers it — no separate attribute on the property, no route file.

```php
#[AsPublicPayload(
    path: '/demo/routing/parameterized/{slug}',
    methods: ['GET'],
    responseWith: DemoFeatureResource::class,
    produces: ['application/json', 'text/html'],
    requirements: ['slug' => '[a-z0-9-]+'],
    defaults: ['slug' => 'headphones'],
)]
class ParameterizedRoutePayload
{
    protected string $slug = '';

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }
}
```

That is `Semitexa\Demo\Application\Payload\Request\Routing\ParameterizedRoutePayload`, served live at `/demo/routing/parameterized/{slug}`.

## How the value reaches the payload

`PayloadHydrator` reads the route pattern and `requirements` from the payload's access attribute — any attribute extending `AbstractPayloadRoute`, so this works the same for public, protected and service payloads. It matches the incoming path, then merges the extracted parameters with the request body and query data.

Each value is then applied **by setter name**. The key is converted from snake_case to camelCase and prefixed with `set`, so `slug` looks for `setSlug()` and a query key `user_id` looks for `setUserId()`. A setter that does not exist, or that does not take exactly one required argument, is skipped silently — the property keeps its declared default. This is why the example declares `protected string $slug = ''` rather than leaving it uninitialised: a typo in the setter name costs you the value, not an error.

There is no property-level attribute involved. If you are looking for something to put above `$slug`, there isn't one.

## Requirements are enforced, not advisory

The regex decides whether the route matches at all. Against the route above:

| Request | Result |
|---|---|
| `/demo/routing/parameterized/wireless-mouse` | `200` |
| `/demo/routing/parameterized/NOT_VALID` | `404` — uppercase and `_` fail `[a-z0-9-]+` |

A value that fails the constraint never reaches the handler, so handler code can treat `$payload->getSlug()` as already validated against that pattern. It is a routing constraint, not a validation message: the client gets a 404, not a field error. When you need an explanation instead of a miss, validate in the payload and let [payload validation](../validation/payload-validation.md) produce the response.

## `defaults` does not make the segment optional

This is the part that surprises people. The route above declares `defaults: ['slug' => 'headphones']`, and yet:

| Request | Result |
|---|---|
| `/demo/routing/parameterized` | `404` |
| `/demo/routing/parameterized/` | `404` |

`defaults` supplies a value when the route is matched **without** that parameter — it does not make the `{slug}` segment optional in the pattern. If you want the bare path to work, declare it as its own route, or make the segment optional in the pattern itself.

## Checking a live route

`routes:show` prints the compiled pattern, the payload, and the resolved handler and resource for any route — see [routing debugging](debugging.md).
