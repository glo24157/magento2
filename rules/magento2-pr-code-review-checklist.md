# Magento 2 — Pull Request Code Review Rules & Checklist

> **Purpose of this file.** This is the standard operating procedure for reviewing any pull request against a Magento 2 project. Follow it top to bottom so no part of the change is missed. The output must be **direct, specific, and actionable**: cite `file:line`, explain *why* something is wrong, and give corrected code where relevant.
>
> **Companion file:** `magento2-project-understanding-and-debugging-guide.md` (architecture + regression rules). Use it whenever a check below needs project context.
>
> **Reviewer mindset:** Assume nothing works until the diff proves it. If you cannot verify a claim from the diff or the repo, say so and ask for the specific file — never invent behavior.

---

## Part 0 — Read the ENTIRE PR before commenting (mandatory)

### 0.0 Get the diff from the local `pr-diffs/` folder FIRST (do not rely on remote fetch)

Fetching a PR diff over the network is unreliable — it can be summarized (not verbatim), truncated, redirected, or have file headers/license blocks stripped. That produces wrong line numbers and false findings. So **always read the diff from a local file first**:

1. The user saves each PR diff at the repo root under **`pr-diffs/`**, named by PR number:
   - **Primary (always try this first):** `pr-diffs/<PR-NUMBER>.diff`
   - **Fallback:** `pr-diffs/<PR-NUMBER>.diff.txt`
   - Example: PR `https://github.com/magento/magento2/pull/40457` → read `pr-diffs/40457.diff` (then `pr-diffs/40457.diff.txt` if the first is missing).
2. **Read that local file with the Read tool** and treat it as the authoritative diff for the whole review (hunks, `+`/`-` lines, and line numbers).
3. **Only if neither local file exists**, tell the user it's missing and ask them to save it (or, as a last resort, fetch remotely — and clearly label any remotely-fetched content as *unverified* until confirmed against a raw file).
4. Line numbers, exact code, and file headers cited in the review must come from the **local diff and/or the raw repo files**, never from a network summary.

### 0.1 Then gather the rest of the context

Do not review a diff in isolation. First gather full context:

1. **PR description** — what problem it claims to solve; linked issue/Jira; acceptance criteria.
2. **All commits** — read each; watch for "fix review comment" commits that reveal history, and for unrelated changes sneaked in.
3. **The full diff** — every file, not just the "interesting" ones (config, XML, tests, and `db_schema_whitelist.json` matter).
4. **Every prior review comment and reply** — build a list of what was raised. In Part 6 you will verify each was *actually* addressed, not just replied to.
5. **The linked issue thread** — confirm the PR actually fixes the reported problem and its real root cause (not just the symptom).
6. **What's NOT in the diff** — missing tests, missing schema whitelist entry, missing ACL, missing `@api` consideration, un-reverted debug code.

Then state, in one or two sentences: **what the PR is trying to achieve and whether the code actually achieves it.**

---

## Part 1 — Correctness & root cause
- [ ] Does the change fix the **root cause**, or only mask a symptom? (Cross-check against the issue.)
- [ ] Are there **edge cases** unhandled: null/empty, zero/negative qty or price, guest vs logged-in customer, multi-store/website, multi-currency, disabled module, first-run/empty-state?
- [ ] Off-by-one, wrong operator, wrong default, inverted condition.
- [ ] Does it behave correctly across all relevant **areas**: `frontend`, `adminhtml`, `webapi_rest`/`soap`, `graphql`, CLI/cron, and async consumers?
- [ ] Does it behave correctly in **developer vs production** mode (static content, DI compilation, error handling)?
- [ ] Exceptions: caught at the right level, not swallowed silently; correct exception types (`LocalizedException`, `NoSuchEntityException`, `InputException`, `CouldNotSaveException`, etc.); messages translatable (`__()`).

## Part 2 — Dependency Injection & object construction
- [ ] **No `ObjectManager` misuse.** `ObjectManager::getInstance()` / injected `ObjectManagerInterface` used in application code is a **blocking** smell. Allowed only in factories, `Test`, static/entry contexts, and BC-preserving optional constructor deps.
- [ ] Dependencies injected via **constructor**; no `new` for classes that should be DI-managed (interceptable classes, services, repositories).
- [ ] **Factories** used for non-shared/transient objects; **Proxies** for heavy deps only needed sometimes.
- [ ] **Constructor signature backward compatibility:** new required args on an `@api`/widely-used class break child classes. New deps should be **optional** (nullable, defaulted via `ObjectManager::getInstance()` fallback) when BC is required.
- [ ] `di.xml` correctness: `<preference>` not colliding with another module's; `<plugin>` `sortOrder`/`disabled` sensible; `<virtualType>` used instead of subclassing where appropriate; area-specific `di.xml` used when the change is area-specific.

## Part 3 — Choice of extension mechanism (plugin vs observer vs preference vs core edit)
- [ ] **Right tool chosen** (see decision table in the debugging guide):
  - Wrapping a public method you don't own → **plugin** (prefer `before`/`after`; `around` only when truly wrapping).
  - Reacting to a domain event → **observer**.
  - Replacing an implementation → **preference** (last resort; flag conflict risk).
- [ ] **Plugin validity:** target method is **public & non-final**, class is DI-instantiated (not constructors/static/final/private). Otherwise the plugin silently never runs.
- [ ] **`around` plugins call `$proceed(...)`** and pass args through correctly; don't needlessly convert `before`/`after` logic into `around`.
- [ ] **No editing of core/vendor files in place.**
- [ ] Observer is **idempotent, fast, and non-throwing** (won't abort the dispatch); doesn't do heavy work synchronously on hot events (`*_save_after`, `sales_order_place_after`).

## Part 4 — Performance
- [ ] **No N+1 queries** — no repository/collection call or `->load()` inside a loop. Batch with `SearchCriteria` `IN`, or `addFieldToFilter(..., ['in' => $ids])`.
- [ ] **No full collection load** where a filtered/paginated query or `getSize()`/`count()` would do; no `->load()` of a whole collection just to count.
- [ ] **No `save()`/`delete()` per row in a loop** — use bulk operations / batching / `ResourceModel` bulk methods.
- [ ] No unbounded loops over catalog/customer/order data without pagination or message-queue offloading.
- [ ] Heavy logic not placed inside **FPC-cached blocks** without ESI/private-data consideration; not run on every request when it could be cached/indexed.
- [ ] Added DB indexes for new query filter columns; queries are sargable.
- [ ] No repeated config reads / repeated service calls that should be memoized.

## Part 5 — Security
- [ ] **SQL injection:** no string-concatenated SQL; use bound params (`$connection->quoteInto`, prepared statements, `addFieldToFilter`). Raw `query()` with interpolated input is **blocking**.
- [ ] **XSS / output escaping:** in `.phtml`, all dynamic output escaped via `$escaper->escapeHtml/escapeHtmlAttr/escapeUrl/escapeJs`; no raw `echo $var`. In UI/JS, no `html` binding of untrusted data. `/* @noEscape */` justified only for known-safe HTML.
- [ ] **ACL enforced:** admin controllers declare `ADMIN_RESOURCE` / `_isAllowed()`; `webapi.xml` routes have correct `<resources>`; new admin menu/config guarded by ACL in `acl.xml`.
- [ ] **CSRF:** admin/frontend POST controllers use form keys / implement `CsrfAwareActionInterface` correctly (no blanket CSRF bypass).
- [ ] **Input validation:** request data validated & type-cast; file uploads restricted (type/size/path); no path traversal; no unvalidated redirects.
- [ ] **Secrets & PII:** nothing sensitive logged or exposed in API/GraphQL responses; encryption via `Encryptor` for sensitive persisted data.
- [ ] **Deserialization/template injection:** no `unserialize()` of untrusted data (use `Serializer\Json`); no untrusted input into template/`create()` class names.
- [ ] **GraphQL/REST authorization:** resolver/service checks customer ownership (a customer can't read/modify another's cart/order/address).

## Part 6 — Backward compatibility & service contracts
- [ ] **`@api` compatibility:** no changed method signatures, no changed return types, no new exceptions on the happy path, no new abstract methods on `@api` classes/interfaces, no removed public methods/constants. (These break dependents and violate Magento BC policy.)
- [ ] New code prefers **service contracts** (`Api/` + `Api/Data/`) over direct model/resource-model use.
- [ ] Data DTOs use **extension attributes** (`extension_attributes.xml`) rather than adding fields to `@api` data interfaces.
- [ ] Deprecations done properly (`@deprecated` + `@see` replacement), not silent removal.
- [ ] **Prior review comments verified addressed** — go through your Part 0 list and confirm each was truly fixed in the latest code (not merely replied to or partially done). Flag any ignored/partial.

## Part 7 — Data layer & configuration
- [ ] **Declarative schema** used (`db_schema.xml`); **`db_schema_whitelist.json` updated** to match (missing whitelist entry = broken setup). No raw DDL in install/upgrade scripts.
- [ ] **Patches** correct: implement the right interface, idempotent, declare dependencies (`DependentPatchInterface`), reversible where feasible; no long-running data migration without batching.
- [ ] EAV attributes added via `EavSetup` in a data patch with correct scope/type/backend.
- [ ] Config: `system.xml` fields have correct `type`, scope flags (`showInDefault/Website/Store`), `backend_model`/`source_model`, ACL `resource`; sensitive fields use the obscure/encrypted backend. `config.xml` provides sane defaults.
- [ ] Correct **config scope** read in code (`ScopeConfigInterface` with `ScopeInterface::SCOPE_STORE` where applicable).

## Part 8 — Frontend / UI / i18n
- [ ] Layout XML: uses `referenceBlock`/`referenceContainer` correctly; no fragile `move`/`remove` that breaks other themes; area-correct.
- [ ] Templates: minimal logic, escaping enforced (Part 5), no direct model instantiation, uses ViewModels/blocks for data.
- [ ] JS: AMD/RequireJS modules, mixins for overrides (not copy-paste of core widgets), KnockoutJS bindings safe.
- [ ] All user-facing strings translatable (`__()` in PHP, `i18n` in JS/templates); `i18n/en_US.csv` updated if needed.
- [ ] GraphQL schema changes: fields typed, documented (`@doc`), cache identity (`@cache`) set for cacheable queries, resolvers implement `ResolverInterface` and handle batching where relevant.

## Part 9 — Indexing, cache, cron, queues
- [ ] Data changes that feed an indexer trigger the right **reindex / mview** handling; indexer dependencies respected.
- [ ] Output/entity changes invalidate the right **cache tags**; FPC correctness maintained.
- [ ] New cron jobs in the correct **group**, sensible schedule, idempotent, won't overlap destructively.
- [ ] New async work declares the full queue config set (`communication/topology/publisher/consumer/queue.xml`); consumers are idempotent and handle poison messages.

## Part 10 — Coding standards, tests, and hygiene
- [ ] Follows **Magento coding standard** (PSR-12 + Magento rules): strict types where used in the module, typed properties/returns, PHPDoc where types are insufficient, class/namespace/file naming, no unused imports, no `var_dump`/`print_r`/`error_log`/debug leftovers, no commented-out code.
- [ ] `declare(strict_types=1);` consistent with module convention.
- [ ] **Tests present and meaningful:** unit tests for logic, integration tests for DB/DI behavior, MFTF for user-facing flows. Tests actually assert the fixed behavior and would fail without the fix. No assertion-free or tautological tests.
- [ ] No hardcoded values that belong in config; no hardcoded store/website IDs; no hardcoded paths.
- [ ] Commit hygiene: scoped changes, no unrelated churn, no secrets/credentials, no generated/`vendor` files committed.
- [ ] Static analysis clean (`phpcs` with Magento standard, `phpstan`), `setup:di:compile` passes.
- [ ] **Verify file-level items (license header, class docblock, `declare(strict_types=1)`, imports) against the local diff or raw file — never a fetch summary.** Summaries strip headers and cause false positives. Also don't overstate severity: missing `strict_types` and inline FQCNs are Magento *conventions*, not hard `phpcs` failures — confirm with an actual `phpcs --standard=Magento2 <file>` run before flagging, and default such items to 🟡 Nice-to-have.

---

## Part 11 — Regression impact (always, for every PR)
Run the **Regression-Safety Checklist** from the debugging guide against every changed method/event/class:
other plugins on the same method (sortOrder / `around` short-circuit), other observers on the same event, competing preferences, callers of changed contracts, index/cache implications, scope/area/mode differences, multi-store, async paths, and `@api` BC. Explicitly state what this change **could break elsewhere** and how to verify it.

---

## Part 12 — Output format (how to deliver the review)

Group findings in this exact order, each with **`file:line` + why + suggested fix**:

1. **🔴 Blocking** — must fix before merge (bugs, security, BC breaks, `ObjectManager` misuse, missing whitelist/ACL, broken plugin, N+1 on hot path, missing critical tests).
2. **🟠 Should fix** — real problems that aren't strictly merge-blockers (edge cases, performance on cold paths, weak tests, standards violations with impact).
3. **🟡 Nice to have** — improvements, readability, minor refactors, naming.
4. **🟢 Positive notes** — what was done well (reinforce good patterns; keep the review balanced and fair).

Then add:
- **Prior comments status:** list each earlier review comment and mark ✅ addressed / ⚠️ partially / ❌ ignored.
- **Regression risks:** bullet list of what could break elsewhere + how to verify.
- **Verdict:** Approve / Approve with nits / Request changes — with a one-line justification.

Be direct. If something is wrong, say so plainly and explain why. If you need a specific file to be certain, ask for it rather than guessing.
