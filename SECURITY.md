# Security policy

This package holds a login to your Supplydrive portal account: the username, the password and the
access token it gets for them. A way to leak one of them, to send one to another host than the
configured ones, or to get the password or the token into a URL, a log line, an exception message
or command output counts as a security issue.

## Supported versions

Only the latest minor release of 1.x receives security fixes. Upgrade before reporting.

## Reporting a vulnerability

Please do **not** open a public issue. Report it privately instead:

- via [GitHub private vulnerability reporting](https://github.com/ArvidDeJong/supplydrive-api/security/advisories/new), or
- by email to info@arvid.nl.

Include the package version, the Laravel version and the steps that show the problem. Leave your
password, the token and invoice data of a real customer out.

You will get a reply within a week. Once a fix is released, the advisory is published and you are
credited, unless you prefer not to be.

## Out of scope

- The response body of a failed call is part of the exception message, with the password and the
  token replaced by `[redacted]`. The rest of that body, and the messages `php artisan
  supplydrive:status` prints, can name your customers and invoice numbers. Who can read your logs
  and who can run artisan is up to the host application.
- The package sends the account to the URLs in `base_url` and `token_url`. Whoever can change the
  config or the environment of the application can point them elsewhere; protecting those is up to
  the host application.
- The package returns what Supplydrive answers. Which user of your application may see the status of
  which invoice is up to the host application.
- Calls that fail or are refused because you make too many of them. The package does not retry and
  does not rate limit; put the calls behind your own authorisation, queue and limits.
- The access token is stored in the cache encrypted with the `APP_KEY` of the application. Whoever
  has that key and can read the cache store can read the token; protecting both is up to the host
  application. Set `SUPPLYDRIVE_TOKEN_CACHE=false` to keep the token in memory only.
