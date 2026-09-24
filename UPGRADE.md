# Upgrade guide

## From 1.x to 2.0

### `Bridge\Bridge` is now the facade

`use Bridge\Bridge;` followed by `Bridge::render(...)` used to fail with "Non-static method Bridge\Bridge::render() cannot be called statically", because `Bridge\Bridge` was the service class and the facade lived at `Bridge\Facades\Bridge`. The layout now puts the facade in front of a separately named service:

| 1.x                                      | 2.0                                                               |
| ---------------------------------------- | ----------------------------------------------------------------- |
| `Bridge\Bridge` (service)                | `Bridge\BridgeManager`                                            |
| `Bridge\Facades\Bridge` (facade)         | `Bridge\Bridge` (`Bridge\Facades\Bridge` still works, deprecated) |
| global alias `Bridge` → `Facades\Bridge` | global alias `Bridge` → `Bridge\Bridge`                           |

The service is still a singleton, also bound as `bridge` in the container.

**Likely impact: low.** Code that imports `Bridge\Facades\Bridge` or uses the global `Bridge` alias keeps working unchanged.

**What breaks:** anything that referenced the 1.x service class `Bridge\Bridge` by name:

- constructor or method injection (`public function __construct(Bridge\Bridge $bridge)`),
- `app(Bridge\Bridge::class)`, `$this->app->make(Bridge\Bridge::class)`, `instanceof Bridge\Bridge`,
- container bindings or extensions of `Bridge\Bridge::class`.

Replace `Bridge\Bridge` with `Bridge\BridgeManager` in those places:

```php
// 1.x
use Bridge\Bridge;

public function __construct(private Bridge $bridge) {}

// 2.0
use Bridge\BridgeManager;

public function __construct(private BridgeManager $bridge) {}
```

There is no compatibility shim for injecting `Bridge\Bridge`. The name now belongs to the facade, and a facade instance cannot stand in for the service: the container would build the facade and the first method call would fail with "Call to undefined method Bridge\Bridge::render()". Making facade instances forward calls would keep an injected facade working quietly, which is the pattern this change removes.

Then switch imports to the new facade (optional, `Bridge\Facades\Bridge` will be removed in a future major):

```diff
- use Bridge\Facades\Bridge;
+ use Bridge\Bridge;
```

### npm packages

`@swarakaka/bridge-*` share one version with the Laravel package, so they move to 2.0.0 as well. Their APIs and the protocol are unchanged.
