# Contributing

Contributions are welcome: bug reports, fixes, documentation and ideas.

## Before you start

- **Bugs:** open an [issue](https://github.com/ArvidDeJong/supplydrive-api/issues/new/choose) with the steps to reproduce and the exception message. Leave your password, the token and invoice data of a real customer out.
- **Features:** open an issue first. This package stays small on purpose, so let's agree a feature fits before you build it.
- **Security issues:** don't open an issue; see [SECURITY.md](SECURITY.md).

## Development

```bash
git clone https://github.com/ArvidDeJong/supplydrive-api.git
cd supplydrive-api
composer install

composer test      # Pest
composer lint      # Pint, check only (composer format fixes)
composer analyse   # Larastan, level 8
```

CI runs the tests on PHP 8.2 to 8.4 with Laravel 11, 12 and 13, on the lowest and the latest dependencies.

## Pull requests

- Add or update tests for every change in behaviour. Never call Supplydrive from a test: the test case blocks every request that is not faked.
- Keep the public API compatible within 1.x:
  - `Darvis\SupplydriveApi\SupplydriveClient`: its public methods (`forgetToken()` included), the protected methods and properties (the class is not final), the `FILTERS` and `STATUS_PATH` constants, and that a failed call throws a `Darvis\SupplydriveApi\Exceptions\SupplydriveException`, which stays a `RuntimeException`, has `status(): int`, has the HTTP status (or `0` without a response) as its code, and keeps the texts `Supplydrive API call failed. HTTP status: …`, `Failed to retrieve access_token from Supplydrive. …`, `Supplydrive token response does not contain access_token.` and `Supplydrive API config is incomplete (username, password).`;
  - that a call without a usable filter throws an `InvalidArgumentException` before anything is sent, and that a 404 returns an empty list;
  - `Message`: its constructor, its properties, `fromArray()`, `knownStatus()`, `description()`, `isDelivered()`, `isFailed()`, `isPending()` and the keys of `toArray()`;
  - `MessageStatus`: its cases and their values (the strings the API returns), `key()`, `description()`, `isDelivered()`, `isFailed()` and `isPending()`;
  - the translation keys under `supplydrive-api::status`;
  - the container binding and the `supplydrive` alias, the `supplydrive:status` command, its options and its exit codes, the `supplydrive-api-config` and `supplydrive-api-lang` publish tags, the config keys (`base_url`, `client_id`, `connect_timeout`, `password`, `timeout`, `token_cache.enabled`, `token_cache.store`, `token_url`, `username`) and the environment variables (`SUPPLYDRIVE_BASE_URL`, `SUPPLYDRIVE_CLIENT_ID`, `SUPPLYDRIVE_CONNECT_TIMEOUT`, `SUPPLYDRIVE_PASSWORD`, `SUPPLYDRIVE_TIMEOUT`, `SUPPLYDRIVE_TOKEN_CACHE`, `SUPPLYDRIVE_TOKEN_CACHE_STORE`, `SUPPLYDRIVE_TOKEN_URL`, `SUPPLYDRIVE_USERNAME`);
  - the defaults a site owner relies on: the token cache is on, 30 seconds and 10 to connect, one repeat after a 401 on a token the client already had, no retry for anything else;
  - the methods of `Support\SupplydriveConfig`.
- The client throws `SupplydriveException` only, never a plain `RuntimeException`, and never wraps `Illuminate\Http\Client\ConnectionException`: host apps catch that class.
- The password and the token never go into a URL, and every message built from a response goes through `redactSecrets()`. The token only goes into the cache encrypted, under a key that holds a hash of the account, never the account itself.
- Read settings through `Support\SupplydriveConfig`, never with `config('supplydrive.…')`.
- Write code, comments and messages in English. The filter names and the status values are Dutch because the API is; keep them as they are.
- Update `docs/`, `CHANGELOG.md` (under `Unreleased`) and `resources/boost/` when users will notice the change.
- The documentation in `docs/` is also the website. Don't write `{{ }}` or `{% %}` there outside a raw block; Jekyll would render it.

## Code of conduct

This project follows the [Contributor Covenant](https://github.com/ArvidDeJong/.github/blob/main/CODE_OF_CONDUCT.md).
