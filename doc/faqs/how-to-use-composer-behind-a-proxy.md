# How to use Composer behind a proxy

Composer, like many other tools, uses environment variables to control the use of a proxy server and
supports:

- `http_proxy` - the proxy to use for HTTP requests
- `https_proxy` - the proxy to use for HTTPS requests
- `CGI_HTTP_PROXY` - the proxy to use for HTTP requests in a non-CLI context
- `no_proxy` - domains that do not require a proxy

These named variables are a convention, rather than an official standard, and their evolution and
usage across different operating systems and tools is complex. Composer prefers the use of lowercase
names, but accepts uppercase names where appropriate.

## Usage

Composer requires specific environment variables for HTTP and HTTPS requests. For example:

```
http_proxy=http://proxy.com:80
https_proxy=http://proxy.com:80
```

Uppercase names can also be used, but HTTP_PROXY will only be accepted in a CLI context.

### Non-CLI usage

Composer does not look for `http_Proxy` or `HTTP_PROXY` in a non-CLI context. If you are running it
this way (i.e. integration into a CMS or similar use case) you must use `CGI_HTTP_PROXY` for HTTP
requests:

```
CGI_HTTP_PROXY=http://proxy.com:80
https_proxy=http://proxy.com:80

# cgi_http_proxy can also be used
```

> **Note:** CGI_HTTP_PROXY was introduced by Perl in 2001 to prevent request header manipulation and
was popularized in 2016 when this vulnerability was widely reported: https://httpoxy.org

## Syntax

Use `scheme://host:port` as in the examples above. Although a missing scheme defaults to http and a
missing port defaults to 80/443 for http/https schemes, other tools might require these values.

The host can be specified as an IP address using dotted quad notation for IPv4, or enclosed in
square brackets for IPv6.

### Authorization

Composer supports Basic authorization, using the `scheme://user:pass@host:port` syntax. Reserved url
characters in either the user name or password must be percent-encoded. For example:

```
user:  me@company
pass:  p@ssw$rd
proxy: http://proxy.com:80

# percent-encoded authorization
me%40company:p%40ssw%24rd

scheme://me%40company:p%40ssw%24rd@proxy.com:80
```

> **Note:** The user name and password components must be percent-encoded individually and then
combined with the colon separator. The user name cannot contain a colon (even if percent-encoded),
because the proxy will split the components on the first colon it finds.

## HTTPS proxy servers

Composer supports HTTPS proxy servers, where HTTPS is the scheme used to connect to the proxy, but
only from PHP 7.3 with cUrl version 7.52.0 and above.

```
http_proxy=https://proxy.com:443
https_proxy=https://proxy.com:443
```

## Bypassing the proxy for specific domains

Use the `no_proxy` (or `NO_PROXY`) environment variable to set a comma-separated list of domains
that the proxy should **not** be used for.

```
no_proxy=example.com
# Bypasses the proxy for example.com and its sub-domains

no_proxy=www.example.com
# Bypasses the proxy for www.example.com and its sub-domains, but not for example.com
```

A domain can be restricted to a particular port (e.g. `:80`) and can also be specified as an IP
address or an IP address block in CIDR notation.

IPv6 addresses do not need to be enclosed in square brackets, like they are for
http_proxy/https_proxy values, although this format is accepted.

Setting the value to `*` will bypass the proxy for all requests.

> **Note:** A leading dot in the domain name has no significance and is removed prior to processing.

## Deprecated environment variables

Composer originally provided `HTTP_PROXY_REQUEST_FULLURI` and `HTTPS_PROXY_REQUEST_FULLURI` to help
mitigate issues with misbehaving proxies. These are no longer required or used.

## Requirement changes

Composer always used `http_proxy` for both HTTP and HTTPS requests if `https_proxy` was not set, but
this has changed to requiring [scheme-specific](#usage) environment variables.

The reason for this is to align Composer with current practice across other popular tools. To help
with the transition, the original behaviour remains but a warning message is shown instructing
the user to add an `https_proxy` environment variable.

To prevent the original behaviour during the transition period, set an empty environment variable
(`https_proxy=`).
