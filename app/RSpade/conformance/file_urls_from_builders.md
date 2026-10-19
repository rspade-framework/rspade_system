---
id: file_urls_from_builders
title: File URLs come from the model's builders, thumbnails from the component
severity: medium
when: launch, change
scope: rsx/**/*.php, rsx/**/*.js, rsx/**/*.jqhtml
related: rsx:man file_upload, rsx:man thumbnails, rsx:man external_api, skill rspade:file-attachments
---

## The contract

Every URL for an attachment's bytes comes from the model's builders at the moment it is used - PHP `$attachment->get_url()` / `get_download_url()` / `get_preview_url()`, JS `File_Attachment_Model.inline_url(record)` / `download_url(record)` - and every thumbnail on a page is rendered with `<Attachment_Thumbnail $attachment_id=...>`, which builds its own URL. A builder's URL ends in `?v=<content version>`, which moves when the attachment points at different bytes or a document render lands; the file routes give their year-long cache only to a current `v`. A URL concatenated from the key has no `v` and a stored one goes stale: both keep working and both are answered `Cache-Control: no-store`, so the file is downloaded again on every view. Builders also apply the realm - a portal URL lives under the portal's address - which a hand-built path gets wrong.

Read `php artisan rsx:man file_upload` (THE CONTENT VERSION, FILE ACCESS) and `rsx:man thumbnails` (THE ATTACHMENT_THUMBNAIL COMPONENT) first.

No rule catches this: `URL-HARDCODE-01` deliberately allows the `/_download/`, `/_inline/`, `/_thumbnail/` and `/_preview/` prefixes.

## Find every instance

    grep -rnE "/_(inline|download|download_zip|thumbnail|preview|icon_by_extension)/" rsx --include='*.php' --include='*.js' --include='*.jqhtml' --include='*.blade.php'
    grep -rnE "get_thumbnail_url|thumbnail_url\(" rsx --include='*.php' --include='*.js' --include='*.jqhtml'
    grep -rnE "(_url|url_[a-z]+)'? *(=>|=) *\\\$[a-z_]*attachment->get_" rsx --include='*.php'

Most hits of the first grep are comments, docblock examples and test assertions - read each and keep only code. Also look for a file URL persisted anywhere: a column, a JSON payload stored in a table (notification or activity payloads), a cache entry or a setting.

## What does not conform

- A URL concatenated from `key` or an id (`'/_download/' . $attachment->key`, a template literal over `/_inline/`).
- A file URL stored in a column, a stored payload, a cache or a setting - store the attachment id and build the URL when it is shown or sent.
- A server-built file URL shipped in a page payload that outlives the request - ship `file_attachment_id` (or the record) and let the component or the JS builder make it.
- A thumbnail URL built or shipped for a page - `get_thumbnail_url()`, `get_thumbnail_url_preset()` or `File_Attachment_Model.thumbnail_url()` feeding an `<img>` - instead of `<Attachment_Thumbnail $attachment_id=...>`.
- A file-type icon built from an extension map or `/_icon_by_extension/` by hand instead of `<File_Type_Icon $file_name=...>`.
- A URL handed to another system (email, external API response) that is not built at send time with the builder, or not absolute (`rsx_absolute_url()` around it).
- A JS builder handed a record carrying only a `key` where the fetched record is available (the version is then absent and the file is served uncacheable).

Not findings: an external API response building `urls{...}` with the builders, thumbnail included, at response time; a widget that holds only a key with no record to fetch, builds through the JS builder, and says so in a comment; a multi-file ZIP request built by the framework's own call.

## Report

`PASS - <one line: N URL sites read, all from builders or the component>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
