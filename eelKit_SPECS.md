# eelKit Upstream Specifications

These are proposed upstream eelKit changes discovered while separating Swallowtail-owned code from eelKit-owned PHP files.

Each spec should be reviewed and accepted in eelKit before the downstream Swallowtail project depends on it as framework behavior. Until accepted, Swallowtail should keep any required project behavior in `web_root/classes/swallowtail/...` or at the application edge.

## Framework Helpers and Card Utilities

Feature name: `framework_table_pagination_sort_and_typed_input_helpers`.

### Downstream Need

Downstream applications need reusable helpers for common card and table workflows:

- Stable pagination state for card-local and table-local lists.
- Table sort state that can survive AJAX card refreshes and form submissions.
- Small display helpers for compact text, labels, dates, JSON summaries, HTTP header labels, and safe escaping.
- Typed value validation/rendering for app-defined configuration rows and metadata rows.

These needs are not Swallowtail-specific. They appear in any eelKit app that renders dynamic cards with sortable/paginated tables or stores user-editable typed values.

### Current Limitation

Without a framework API, downstream projects either duplicate helper logic or edit eelKit classes directly. That makes app code hard to upgrade and causes framework-owned files such as `CardBaseFramework.php`, `HelperFramework.php`, and `FieldValidationFramework.php` to collect app-motivated utilities.

### Proposed API

Add reusable framework helpers:

- `CardBaseFramework::applyPaginationContext(RequestFramework $request, array $pageContext, ?string $scope = null): array`
- `CardBaseFramework::paginationPage(array $context, ?string $scope = null): int`
- `CardBaseFramework::paginationControls(array $context, array $pagination, ?string $scope = null, array $hiddenFields = [], array $formAttributes = []): string`
- `CardBaseFramework::applyTableSortContext(RequestFramework $request, array $pageContext, string $tableKey): array`
- `CardBaseFramework::configureTableSorting(TableFramework $table, array $context, array $hiddenFields = []): TableFramework`
- `CardBaseFramework::tableSortHiddenFields(array $context, string $tableKey): array`
- `HelperFramework::compactText(string $value, int $maxLength = 96): string`
- `HelperFramework::paginateArray(array $items, int $page = 1, int $pageSize = 25): array`
- `HelperFramework::paginationItemsLabel(array $pagination, string $itemLabel = 'Items'): string`
- `HelperFramework::paginationFormButton(...)`
- `HelperFramework::labelFromKey(?string $value, string $separator = '_', string $fallback = ''): string`
- `HelperFramework::titleCase(?string $value, string $fallback = ''): string`
- `HelperFramework::httpHeaderLabelFromServerKey(string $serverKey): string`
- `HelperFramework::parseDateTimeValue(?string $value): ?DateTimeImmutable`
- `HelperFramework::normaliseDate(?string $value): ?string`
- `HelperFramework::normaliseUtcDateTime(?string $value): ?string`
- `FieldValidationFramework::normaliseType(string $type): ?string`
- `FieldValidationFramework::validateTypedValue(mixed $value, string $type): array`
- `FieldValidationFramework::renderTypedValueControl(string $name, mixed $value, string $type, array $options = []): string`

Validation types should initially be:

- `boolean`
- `int`
- `float`
- `ascii`
- `string`
- `null`

Return values from typed validation should be structured arrays with a boolean validity flag, the normalized value, the normalized type, and an error message when invalid.

### Compatibility Concerns

These are additive APIs, but naming matters because `CardBaseFramework` protected methods become downstream extension points. Sort and pagination field names must be deterministic and collision-resistant for pages that render multiple tables or multiple copies of a card.

Browser validation must remain advisory only. Server-side validation remains required.

### Temporary Downstream Workaround

Swallowtail can keep app-specific table state and typed validation helpers in `Swallowtail\Service` or card classes. If eelKit accepts this feature, Swallowtail should remove duplicate downstream helpers and use the framework methods.

## Database Portability and SQL Preparation

Feature name: `pdo_database_portability_and_safe_sql_preparation`.

### Downstream Need

Downstream projects need to run eelKit tests and small deployments on SQLite while still targeting MySQL or ODBC-backed databases in production-like environments. They also need safer SQL preparation helpers that work with named placeholders, duplicate placeholders, and extra parameter arrays without forcing every repository to implement its own filtering.

### Current Limitation

Raw PDO behavior differs between drivers. SQLite schema initialization needs translation from the canonical eelKit schema, missing PDO drivers produce unclear failures, and repeated named placeholders can behave differently depending on the driver/emulation mode. Repositories can accidentally pass unused parameters or rely on driver-specific placeholder behavior.

### Proposed API

Add driver-aware PDO helpers in `PdoDB`:

- `PdoDB::connectionForInterfaceDB(): PDO`
- `PdoDB::preparePlanOn(PDO $pdo, string $sql, array $options = []): array`
- `PdoDB::prepareExecuteOn(PDO $pdo, string $sql, array $params = []): PDOStatement`
- `PdoDB::filterParamsForSql(string $sql, array $params = []): array`
- `PdoDB::prepareOn(PDO $pdo, string $sql, array $options = []): PDOStatement|false`
- `PdoDB::queryOn(PDO $pdo, string $sql, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false`
- `PdoDB::logSql(string $sql, ?array $params = null): void`

The implementation should:

- Detect missing PDO drivers and return an actionable error, including ODBC setup guidance for `odbc:` DSNs.
- Optionally initialize an in-memory SQLite database from the configured eelKit schema for tests.
- Translate common schema definitions to SQLite where the canonical schema uses MySQL-ish features.
- Rewrite duplicate named placeholders only when the active driver or options require it.
- Filter parameter arrays to placeholders used by the SQL before execute.
- Keep `connectionForInterfaceDB()` guarded so app code does not bypass `InterfaceDB` directly.
- Provide optional SQL logging that is disabled unless configured.

### Compatibility Concerns

The public database facade should remain `InterfaceDB`; `PdoDB` helpers are framework internals or advanced test support. SQL rewriting must not change literal strings, comments, JSON payloads, or casts. Schema translation should be conservative and covered by tests for representative DDL.

Logging must avoid leaking secrets where possible and must not create directories or make requests slow when disabled.

### Temporary Downstream Workaround

Swallowtail can keep repositories on `InterfaceDB` and only rely on existing downstream-tested behavior. If database portability is not accepted upstream, Swallowtail should not keep patching `PdoDB`; instead it should use project-local test bootstrap code or project-specific repository adapters.

## Security and Reverse Proxy Trust

Feature name: `security_file_permissions_and_trusted_proxy_request_context`.

### Downstream Need

Downstream apps need secure storage for API keys and generated security facts, plus consistent client request metadata when eelKit is deployed behind a reverse proxy. This is needed for audit logs, upload APIs, lockout behavior, absolute URL generation, and security-sensitive request handling.

### Current Limitation

Security key files can be created or updated without consistently enforcing private file permissions. Reverse-proxy headers are dangerous unless they are only trusted from configured proxy IPs, but downstream code should not have to parse `Forwarded`, `X-Forwarded-For`, `X-Forwarded-Host`, and `X-Forwarded-Proto` itself.

### Proposed API

Add or standardize `SecurityStore` behavior:

- `SecurityStore::apiKeysPath(?string $overridePath = null): string`
- `SecurityStore::securityKeysPath(?string $overridePath = null): string`
- `SecurityStore::credentialCatalog(?string $keysPath = null): array`
- `SecurityStore::loadCredential(...)`
- `SecurityStore::loadFact(string $key, ?string $keysPath = null): ?string`
- `SecurityStore::ensureFact(string $key, ?string $keysPath = null): string`

Security files written by eelKit should be created with private permissions, currently `0600`, when the platform supports `chmod`.

Add or standardize `ReverseProxyService` behavior:

- `ReverseProxyService::clientIpAddress(RequestFramework $request): string`
- `ReverseProxyService::trustedProxyIps(): array`
- `ReverseProxyService::isTrustedProxyRequest(RequestFramework $request): bool`
- `ReverseProxyService::forwardedHost(RequestFramework $request): string`
- `ReverseProxyService::forwardedScheme(RequestFramework $request): string`
- `ReverseProxyService::clientIpHeaders(): array`

Configuration should live under generic eelKit config:

```php
'reverse_proxy' => [
    'trusted_proxy_ips' => [],
    'client_ip_headers' => ['X-Forwarded-For', 'X-Real-IP'],
],
```

Proxy-derived client IP, host, and scheme values must only be used when the immediate remote address is trusted.

### Compatibility Concerns

File permission enforcement must be best-effort on platforms that do not support POSIX permissions, especially Windows. eelKit should not reject a readable existing file solely because chmod cannot be applied on an unsupported platform.

Reverse proxy behavior can change audit/security metadata for deployments that previously trusted forwarded headers unconditionally. Defaults must be secure: no proxy headers are trusted unless the remote proxy IP is configured.

### Temporary Downstream Workaround

Swallowtail can call `ReverseProxyService` where available and otherwise fall back to `RequestFramework::remoteAddress()`. For project-specific key material, Swallowtail should use its own namespaced store rather than extending `SecurityStore` with Swallowtail-only concerns.

## Request Handling

Feature name: `request_json_body_headers_and_cgi_authorization_fallback`.

### Downstream Need

API endpoints need a single request object that can read query, form, JSON body, files, cookies, server values, and headers. Upload and API-auth endpoints also need `Authorization` to work under CGI/FastCGI environments where it may arrive as `AUTHORIZATION` or `REDIRECT_HTTP_AUTHORIZATION` rather than `HTTP_AUTHORIZATION`.

### Current Limitation

Downstream endpoints otherwise repeat raw body parsing, JSON decoding, header normalization, card key extraction, and Authorization fallback logic. Some SAPIs do not expose `Authorization` through `getallheaders()`.

### Proposed API

Standardize `RequestFramework` behavior:

- Constructor accepts query, post, server, files, headers, optional raw body, and cookies.
- `RequestFramework::fromGlobals(): self` builds a request from PHP globals and `php://input`.
- `post()` and `input()` read JSON body values when the request content type is JSON.
- `postValues()` merges submitted form values and decoded JSON values.
- `files()`, `server()`, `cookie()`, `header()`, and `headers()` expose request data safely.
- `cardKeys()` normalizes submitted card lists from form or JSON requests.
- `withMergedPostValues()` and `replayWith()` support framework redispatch/AJAX workflows.
- Header normalization maps `HTTP_*`, `CONTENT_TYPE`, `CONTENT_LENGTH`, and `CONTENT_MD5` server keys to normal HTTP header labels.
- `AUTHORIZATION` and `REDIRECT_HTTP_AUTHORIZATION` populate `Authorization` only when no already-normalized Authorization header exists.

The upstream precedence rule should remain:

- When `getallheaders()` and server-derived headers both provide the same header, `getallheaders()` wins.
- The CGI Authorization fallback must not invert that broader precedence rule.

### Compatibility Concerns

Changing header precedence is observable and should be avoided. JSON parsing should be tolerant: invalid or empty JSON bodies should produce no JSON input rather than throwing during ordinary request construction.

Reading `php://input` should happen once in `fromGlobals()`. Tests may use a framework-controlled raw body override to avoid depending on the PHP input stream.

### Temporary Downstream Workaround

Swallowtail API endpoints can construct `RequestFramework` explicitly with headers supplied at the endpoint edge. If upstream rejects broader request changes, Swallowtail should keep endpoint-specific Authorization fallback in API scripts rather than changing framework precedence.

## Response Handling

Feature name: `response_objects_json_download_and_default_security_headers`.

### Downstream Need

API endpoints and card/action handlers need a consistent way to return JSON, HTML, and downloads without duplicating `header()` calls. Download endpoints also need safe filenames, content length, no-store cache headers, and a way for tests to inspect response metadata without sending output.

### Current Limitation

Without a response object, endpoints either send output directly or manually coordinate status code, content type, cache headers, JSON encoding, and body output. That makes API flows harder to test and increases the chance of inconsistent security headers.

### Proposed API

Standardize `ResponseFramework`:

- `ResponseFramework::html(string $html, int $statusCode = 200): self`
- `ResponseFramework::json(array $payload, int $statusCode = 200): self`
- `ResponseFramework::download(string $body, string $filename, string $contentType): self`
- `body(): string`
- `statusCode(): int`
- `contentType(): string`
- `headerValue(string $name): ?string`
- `send(): void`

Default response headers should include security-conscious defaults appropriate for eelKit HTML and API responses, such as:

- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: same-origin`
- `X-Frame-Options: DENY`

`send()` should:

- Remove legacy or undesirable headers such as `X-Powered-By` and `X-XSS-Protection` when headers have not already been sent.
- Set the HTTP response code.
- Emit `Content-Type` and all configured headers.
- Echo the response body.

`download()` should:

- Sanitize filenames to a conservative ASCII set.
- Set `Content-Disposition`.
- Set `Content-Length`.
- Set no-store/no-cache headers.

### Compatibility Concerns

Security headers can affect apps embedded in iframes. If eelKit has a legitimate embedding use case, `X-Frame-Options` should be configurable or overridable.

`json()` using `JSON_THROW_ON_ERROR` can throw where older direct `json_encode()` code returned `false`; callers should either pass arrays known to encode cleanly or catch `JsonException`.

`send()` should not call `header_remove()` after headers are already sent.

### Temporary Downstream Workaround

Swallowtail can return `ResponseFramework` from API services where available and keep direct endpoint responses small. If upstream does not accept this API, Swallowtail should move response helpers into `Swallowtail\Service` and keep endpoint scripts as thin adapters.
