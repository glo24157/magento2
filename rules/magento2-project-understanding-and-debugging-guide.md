# Magento 2 — Project Understanding & Debugging Guide

> **Purpose of this file.** This is a reusable, project-agnostic playbook for rapidly building a *deep, accurate* working model of any Magento 2 codebase (Open Source, Adobe Commerce, or a merchant project) and then using that model to find the **root cause** of issues and fix them **without introducing regressions**.
>
> Use it in two ways:
> 1. **Onboarding a project fast** — follow "Part A: Rapid Onboarding" to reconstruct in minutes the understanding that otherwise takes a full scan.
> 2. **Debugging an issue** — follow "Part C: Root-Cause Debugging Method" and "Part D: Regression-Safety Checklist" every time.
>
> **Golden rule:** Never reason from generic Magento assumptions. Always confirm against the *actual* code in *this* project — customizations, third-party modules, and DI overrides change core behavior. Explicitly label statements as **"confirmed"** vs **"hypothesis to verify."**

---

## Part A — Rapid Onboarding (do this first on any project)

Run/read these to reconstruct the project model quickly. Prefer reading config over guessing.

### A.1 Identify the platform (5 checks)
| What | Where / how |
|---|---|
| Edition & version | `composer.json` → `name` (`magento/magento2ce` = Open Source, `magento/magento2ee`/`project-community-edition` vs `project-enterprise-edition`), `magento/product-community-edition` / `product-enterprise-edition` version constraint; or `bin/magento --version` on an installed instance |
| PHP version | `composer.json` → `require.php` |
| Is it installed or source-only? | Presence of `app/etc/config.php` + `app/etc/env.php` = installed instance; absence = pure source checkout |
| Deploy mode | `bin/magento deploy:mode:show` (developer / default / production) |
| Search / cache / queue backends | `app/etc/env.php` (`db`, `cache`, `session`, `queue`, `system/default/catalog/search/engine`); composer `require` for `elasticsearch`, `opensearch`, `predis`, `php-amqplib`, `stomp-php` |

### A.2 Enumerate modules & load order
- **Custom modules:** `app/code/<Vendor>/<Module>` — these are the project's own code. List them first; they hold the customizations.
- **Third-party modules:** `vendor/<vendor>/module-*` with `registration.php` — installed extensions.
- **Core modules:** `vendor/magento/module-*` (installed) or `app/code/Magento/*` (in the core dev repo).
- **Enabled/disabled + order:** `app/etc/config.php` (`modules` array; `1`=enabled). DI and events load in dependency order defined by each module's `etc/module.xml` `<sequence>`.
- **Fast inventory command (installed):** `bin/magento module:status`.

> On a **merchant project**, the 20% that matters is under `app/code` + third-party `vendor` modules. Read those `module.xml`, `composer.json`, and `di.xml` first. On the **core dev repo**, everything under `app/code/Magento` *is* core.

### A.3 Find where core behavior is modified (the highest-value scan)
Search across `app/code` and third-party vendor modules for these override points — this is where bugs and conflicts live:

| Override type | File / pattern | Why it matters |
|---|---|---|
| **Preferences** | `di.xml` → `<preference for="Interface|Class" type="Impl"/>` | Silently swaps an implementation for the whole app. Two modules preferring the same interface = **conflict**; last one loaded (by sequence) wins. |
| **Plugins (interceptors)** | `di.xml` → `<type name="X"><plugin .../></type>` | before/after/around wrap a method. Multiple plugins on the same method run by `sortOrder`; an `around` that doesn't call `$proceed()` short-circuits later plugins. |
| **Observers** | `events.xml` → `<event name="..."><observer .../></event>` | Multiple observers on one event; order matters; a throwing observer can break the dispatcher. |
| **Virtual types** | `di.xml` → `<virtualType>` | Named, pre-configured variants of a class injected somewhere specific. |
| **DI arguments** | `di.xml` → `<type><arguments>` | Changes constructor injection (e.g., swapping a pool member, adding a processor). |
| **Layout overrides** | `view/*/layout/*.xml` (esp. `referenceBlock`, `move`, `remove`) & theme overrides | Frontend/admin structure changes. |
| **Template overrides** | theme `Vendor_Module/templates/*.phtml` | Overrides module `.phtml`. |
| **JS mixins** | `requirejs-config.js` → `config.mixins` | Non-invasive JS behavior overrides. |

Suggested searches (ripgrep):
```
rg -l "<preference"  app/code vendor/<vendor>
rg -n  "<plugin "    app/code
rg -n  "<observer "  app/code
rg -l  "config.mixins" app/code app/design
```

### A.4 Map the data layer
- **Declarative schema:** `etc/db_schema.xml` per module (+ `db_schema_whitelist.json`). This is the source of truth for tables/columns/indexes/FKs.
- **Patches:** `Setup/Patch/Data/*` (`DataPatchInterface`) and `Setup/Patch/Schema/*` (`SchemaPatchInterface`); dependencies via `DependentPatchInterface`. Applied by `PatchApplier`, tracked in `patch_list`.
- **EAV attributes:** created in data patches (`EavSetup::addAttribute`) or install scripts. Entities with EAV: product, category, customer, customer address. Values live in `<entity>_entity_{varchar,int,decimal,text,datetime}`.
- **Legacy vs modern persistence:** `Model\AbstractModel` + `ResourceModel\Db\AbstractDb` + Collections (fires `_load/_save/_delete_before/after` events) vs `EntityManager` (MetadataPool/Hydrator/Operations, used for EAV + newer entities).

### A.5 Map the extension surfaces you'll touch most
- **Service contracts:** `Api/*Interface.php` (operations) + `Api/Data/*Interface.php` (DTOs). These are the stable, `@api` layer — prefer them over direct model use.
- **Web API:** `etc/webapi.xml` (REST/SOAP routes → service method + ACL `<resources>`).
- **GraphQL:** `*GraphQl` modules, `etc/schema.graphqls` (`@resolver`, `@typeResolver`, `@cache`), resolvers implement `Framework\GraphQl\Query\ResolverInterface`.
- **Indexers:** `etc/indexer.xml` + `etc/mview.xml` (changelog triggers `*_cl`, scheduled vs on-save mode).
- **Cron:** `etc/crontab.xml` (jobs) + `etc/cron_groups.xml` (timing, separate process).
- **Cache types:** `etc/cache.xml`; FPC via built-in or Varnish; tag-based invalidation.
- **Message queues:** `communication.xml`, `queue_topology.xml`, `queue_publisher.xml`, `queue_consumer.xml`, `queue.xml`.
- **Admin config / ACL / menu:** `etc/adminhtml/system.xml`, `etc/acl.xml`, `etc/adminhtml/menu.xml`, `etc/adminhtml/routes.xml`.

### A.6 Produce a one-page project summary
After A.1–A.5, write down: edition+version+PHP; list of custom & key third-party modules with one-line purpose; every `<preference>` and notable plugins/observers (the override map); custom tables & EAV attributes; customizations to checkout/cart/catalog/pricing/indexing/cron; theme & JS override points; integrations/webapi/GraphQl/queues; cache & indexer modes. Confirm this summary with the team before deep work.

---

## Part B — Architecture Reference (mental model of the platform)

### B.1 Bootstrap & Dependency Injection
- Flow: `index.php` → `App\Bootstrap` → `ObjectManagerFactory` → merge global `app/etc/di.xml` + every enabled module's `etc/di.xml` **in `<sequence>` order** → build interception config → ObjectManager ready.
- **Three DI mechanisms** (all declarative in `di.xml`): `<preference>` (interface→impl), `<type>/<virtualType>` (constructor args & named variants), `<plugin>` (interception).
- **Never** use `ObjectManager` directly in application code — inject dependencies via the constructor. `ObjectManager::getInstance()` is allowed only in factories, static/entry contexts, and tests.
- **Code generation** (`generated/code/`): `Interceptor` (plugin dispatch), `Factory`, `Proxy` (lazy load). Stale generated code causes ghost bugs — regenerate with `setup:di:compile` (prod) or clear `generated/` (dev).

### B.2 Interception (plugins) — the rules that cause real bugs
- Method must be **public** and **non-final**; the object must be created by the ObjectManager (not `new`). Constructors, static, final, and private methods **cannot** be plugged.
- Execution order for one target method: all `before` (by ascending `sortOrder`) → nested `around` (outer = lower sortOrder) → all `after`.
- `around` plugins must call `$proceed(...args)`; forgetting to (or altering args/return carelessly) breaks other plugins and core logic. Prefer `before`/`after` unless you genuinely must wrap.
- Plugins across modules on the **same method** are the #1 source of order-dependent regressions — always enumerate them.

### B.3 Events / Observers
- Dispatched via `EventManager::dispatch('name', [...])`. Observers declared in `events.xml` (global, or area-specific `frontend`/`adminhtml`/`webapi_rest` etc.).
- Observers on the same event have no guaranteed order unless controlled; keep them idempotent and fast. A throwing observer can abort the whole dispatch.
- Model lifecycle events: `<eventPrefix>_load_after`, `_save_before/after`, `_save_commit_after`, `_delete_before/after`. `*_commit_after` fires after the DB transaction commits (use for things that must not run inside the txn, e.g. reindex/cache).

### B.4 Domain spine (dependency direction)
```
Eav → Catalog → {CatalogInventory, CatalogRule, CatalogSearch, CatalogUrlRewrite}
Catalog → Quote → Checkout → Sales → {SalesSequence, Payment, SalesRule}
                  Checkout → Shipping
Customer → Eav ; Customer → Directory
```
- **Quote = cart** (`quote`, `quote_item`, `quote_address`). Totals use the **Total Collector** pattern (`Quote\Model\Quote\Address\Total\Collector` running `AbstractTotal` subclasses: subtotal → shipping → tax → discount → grand). SalesRule & Tax hook here.
- **Quote → Order** conversion: `Quote\Model\Quote\{Address\ToOrder, Item\ToOrderItem, Payment\ToOrderPayment}`. Order is an immutable snapshot.
- **Pricing has two distinct layers — never conflate:** **CatalogRule** = catalog-level, indexed in `catalogrule_product_price`, applied at query/index time; **SalesRule** = cart-level coupons/discounts, applied in the quote total collector. Storefront price is served from `catalog_product_index_price`.
- **Fulfillment:** Invoice/Shipment/Creditmemo with `qty_invoiced/qty_shipped/qty_refunded` tracking to prevent double-processing.

### B.5 Infrastructure
- **Indexing:** `indexer.xml` + `mview.xml`. Modes: **on-save** (immediate) vs **scheduled** (DB triggers → `*_cl` changelog → processed by `index` cron). Respect indexer **dependencies** (e.g. `catalog_product_price` depends on `catalogrule_rule`; `catalogsearch_fulltext` depends on stock/price/category_product). A change that alters indexed data must consider reindex.
- **Cron:** `crontab.xml` jobs grouped; `cron_groups.xml` timing; rows in `cron_schedule` (statuses: pending/running/success/missed/error).
- **Cache:** `cache.xml` types (`full_page`, `block_html`, `config`, `layout`, `collections`, `eav`, `reflection`, `config_webservice`, GraphQL resolver cache, …). Backends File/Redis. FPC via built-in or Varnish with **ESI** holes. **Tag-based invalidation** tied to reindex via `CacheContext` + Indexer cache cleaners.
- **Message Queue:** backends AMQP (RabbitMQ), STOMP (ActiveMQ/Artemis), MySQL fallback. `AsynchronousOperations` + `WebapiAsync` for bulk/async ops; consumers via CLI or cron.

### B.6 Presentation
- **Static content:** In **developer/default** mode, static assets are materialized on-demand when a browser requests the static URL (`Framework\App\StaticResource` → `Publisher::publish`). In **production**, assets must be pre-deployed via `setup:static-content:deploy` (SCD) or 404. Server-side code that reads a file from `pub/static` directly (instead of `Asset\File::getSourceFile()`, which resolves the source via fallback) breaks when the asset hasn't been published yet.
- **Layout XML:** handles keyed by full action name (e.g. `catalog_product_view`); compose `<block>`/`<container>` with `referenceBlock`/`move`/`remove`. Blocks + `.phtml`.
- **UI Components** (`Magento_Ui`): `ui_component/*.xml` (listing/form) + `DataProvider` (SearchCriteria) + KnockoutJS `.html` templates.
- **RequireJS/JS:** `requirejs-config.js`, mixins for non-invasive overrides.

---

## Part C — Root-Cause Debugging Method (run every time)

1. **Restate the issue precisely.** Expected vs actual behavior, exact version/edition/mode, and the exact error (message, file:line, stack trace). Note preconditions (payment/shipping method enabled, dev vs prod, static deployed or not, cache/index state).
2. **Reproduce first, or state you cannot.** Nail the minimal reproduction. If QA "can't reproduce," suspect environment state (cache warm, static already deployed, index valid, different config scope). Document the exact state needed to trigger it.
3. **Trace the *actual* code path in *this* project** — controller/resolver → service/model → resource model → DB, following the real DI wiring. At each hop, check for the override points from A.3 (preference swap? plugin wrapping? observer on the event?).
4. **Separate symptom from cause.** The crash line is usually the symptom. Ask "why did the input reach this state?" and walk backward until you find the earliest decision that is actually wrong.
5. **State confidence.** Label the finding **"confirmed root cause"** (you can point to the exact code and explain the mechanism) vs **"hypothesis"** (needs a specific file/log to verify). If a file you need isn't in the repo (e.g., a third-party module), ask for it rather than guessing.
6. **Propose the fix as concrete code** at the correct layer (see C.7).
7. **Run Part D regression checklist** before declaring done.
8. **Give reproduction + verification steps** (commands, config, expected result after fix).

### C.7 Choose the right fix mechanism
| Situation | Correct tool |
|---|---|
| Add behavior around a core public method you don't own | **Plugin** (before/after preferred; around only if wrapping is required) |
| React to a domain event | **Observer** on the event |
| Replace an implementation wholesale | **Preference** (last resort; breaks if others also prefer it) |
| Change injected dependencies/config | **di.xml `<type><arguments>`** / virtualType |
| Change frontend structure | **Layout XML** / template override / JS mixin |
| New persisted data | **db_schema.xml** (structure) + **data patch** (data/EAV) |
| Bug is in your own module's class | Fix the class directly |

Avoid: editing core/vendor files in place; using ObjectManager directly; `around` plugins that don't call `$proceed`; business logic in templates; new queries inside loops.

---

## Part D — Regression-Safety Checklist (before any fix ships)

For the method/event/class you are changing, verify:

- [ ] **Other plugins on the same method** — list them, check `sortOrder` and whether any `around` short-circuits. Will your change alter args/return they depend on?
- [ ] **Other observers on the same event** — order and side effects.
- [ ] **Competing `<preference>`** for the same interface/class anywhere (custom + third-party). Are you the effective winner? Does your change break others?
- [ ] **Callers of the changed method / service contract** — grep for usages. If it's `@api`, is the change **backward compatible** (no changed signatures, return types, thrown exceptions, or new abstract methods)?
- [ ] **Data/index impact** — does the change alter data that feeds an indexer? Which indexer, and does it need reindex? Respect indexer dependencies.
- [ ] **Cache impact** — does output need new cache tags/invalidation? Is it inside an FPC-cached block (needs ESI/private data)? Config/layout/block cache implications.
- [ ] **Scope correctness** — global vs website vs store-view config; is the value read at the right scope?
- [ ] **Areas** — does the change behave correctly in frontend, adminhtml, webapi_rest/soap, graphql, and CLI/cron contexts as applicable?
- [ ] **Mode differences** — developer vs production (static content, DI compilation, error visibility). Does the fix rely on dev-only behavior?
- [ ] **Performance** — no N+1 queries, no full collection loads in loops, no per-row `save()` in a loop; add pagination/batching where needed.
- [ ] **Security** — input validated/escaped; SQL via bound params / DDL-safe; output escaped in templates; ACL enforced; no secrets logged.
- [ ] **Multi-store / multi-website / multi-currency / locale** — still correct.
- [ ] **Async paths** — same logic reachable via queue consumers, bulk operations, or WebapiAsync?
- [ ] **Tests** — unit/integration/MFTF added or updated to cover the fix and guard the regression.
- [ ] **Upgrade/BC** — patch order, whitelist updated for schema, no breaking DB change on existing data.

### Verification commands (installed instance)
```
php bin/magento setup:upgrade
php bin/magento setup:di:compile          # catch DI/plugin errors
php bin/magento indexer:reindex           # or targeted indexer
php bin/magento cache:flush
php bin/magento deploy:mode:set developer # to see errors during repro
# reproduce the scenario, confirm expected result
```

---

## Part E — High-frequency pitfalls & tells
- **"Works on my machine / can't reproduce"** → environment state: warm cache, deployed static, valid indexes, different config scope, or dev-vs-prod. Reset to a clean state and reproduce.
- **Reading `pub/static` directly server-side** → breaks before SCD/materialization; use `Asset\File::getSourceFile()`.
- **Two modules preferring the same interface** → non-deterministic-looking behavior driven by `<sequence>` load order.
- **`around` plugin dropping `$proceed`** → silently disables downstream plugins and core logic.
- **Data changed but not reindexed / cache not invalidated** → stale storefront; check indexer mode & cache tags.
- **Config read at wrong scope** → correct in admin default, wrong per store view.
- **Direct model use instead of service contract** → brittle across upgrades; prefer `Api/` interfaces.
- **Stale `generated/` or `var/`** → phantom errors; clear and recompile.

---

*Keep this file current: when a project has notable customizations (its override map, custom tables, integration quirks), append a project-specific appendix at the bottom so future onboarding is instant.*
