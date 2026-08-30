---
name: file-attachments
description: Uploading files in RSX and attaching them to records - the mandatory file.upload.authorize gate, Ajax.upload(form_data) as the browser transport (POST /api/v1/files for an external integration), the claim flow (find_by_key + can_user_assign_this_file + attach_to/add_to), rendering the size ceiling in a label, inline vs download URLs, displaying a thumbnail with <Attachment_Thumbnail $attachment_id> (the only way - an app never builds a thumbnail URL and ships file_attachment_id instead), creating attachments programmatically, the retention/disposal lifecycle, and multi-file ZIP downloads. Use when building an upload UI, attaching files to a model, attaching a file uploaded through the external API, showing a thumbnail or avatar, showing or downloading a stored file, deleting/recovering attachments, or wiring the file authorization hooks.
---

# File attachments

Two models, one dedup boundary:

| Model | What it is |
|---|---|
| `File_Storage_Model` | the physical, content-addressed BLOB on disk |
| `File_Attachment_Model` | the logical upload: filename, mime, owner, category |

Identical bytes are stored once and shared by every attachment pointing at them. That single fact explains most of the rest of this page: text extraction runs once per blob, a preview rendition is cached once per blob, and a blob is only deleted when the LAST attachment pinning it is gone.

---

## The flow, once, in order

1. The browser POSTs a file to `/_upload` (through `Ajax.upload()`). It lands **UNATTACHED**, stamped with a server-derived `site_id` and an unguessable `key`.
2. The upload response hands the client `attachment.key`.
3. The client posts that key to YOUR `#[Ajax_Endpoint]`, which looks the attachment up, checks it is claimable, and attaches it to a record.

Nothing about step 1 authorizes step 3, and nothing about step 3 is done for you.

**An integration walks the same three steps over the external API** — `POST /api/v1/files` with a Bearer key instead of step 1, then its own `#[Api_Endpoint]` instead of step 3. Both transports run one implementation (`Rsx_File_Upload::accept()`), so the gate, the ceiling and the server-derived `site_id` are identical. See `rspade:external-api`.

---

## 1. Uploading (client)

**`Ajax.upload(form_data)` is THE client upload API** — in the browser. (An external integration uses `POST /api/v1/files` instead; this section is about page code.) There is no `<File_Upload>` component.

```javascript
async _upload(file) {
    const form_data = new FormData();
    form_data.append('file', file);            // the part MUST be named 'file'

    const result = await Ajax.upload(form_data);   // throws on failure
    this.state.pending_key = result.attachment.key;
}
```

**Never `fetch('/_upload')` by hand from page code.** `Ajax.upload()` owns two things a hand-rolled call gets wrong: it rebases the path through `Rsx_Portal.internal_url()` so a portal page's upload dispatches as a genuine portal request, and it sends the CSRF token (multipart cannot go through jQuery, so the token has to be attached explicitly). A raw fetch hardcodes the staff channel and sends no token.

**Never post a `site_id`.** It is derived server-side from the request's realm; client input is ignored. Same for `fileable_*` — the endpoint strips them, because letting an uploader name the owning record would let them attach files to records they do not own.

The response's `attachment` object carries `key`, `file_name`, `file_extension`, `mime_type`, `size`, `url`, `download_url`, `has_thumbnail`, `width`/`height`, `is_animated`, `duration`, and `preview_unavailable` (true when image bytes could not be parsed and the upload was degraded to a generic non-previewable file).

Drag-and-drop: add `class="rsx-droppable"` to any element/component and handle the `file-drop` event — see `rsx:man droppable`. It gives you a `File`; you still call `Ajax.upload()`.

---

## 2. The upload gate is MANDATORY

`POST /_upload` **THROWS (5xx)** when no `file.upload.authorize` handler is registered.

This is the one gate in the framework that is fail-closed by absence. `Rsx::trigger_gate()` defaults OPEN when nothing is listening — correct for an optional gate, catastrophic here, because an app that never wrote a handler would be running an anonymous upload endpoint. So the endpoint asks `Event_Registry::has_handlers()` itself and refuses. Who may upload is an APPLICATION decision the framework will not guess.

Every app ships exactly one such handler in `/rsx/handlers/`. Minimum: require a logged-in user. The template's is `rsx/handlers/File_Upload_Handlers.php`:

```php
class File_Upload_Handlers
{
    #[OnEvent('file.upload.authorize', priority: 10)]
    public static function require_authentication($data)
    {
        if (Session::is_logged_in() || Portal_Session::is_logged_in()) {
            return true;                       // true PERMITS
        }

        return response()->json([
            'success' => false,
            'error'   => 'Authentication required to upload files',
        ], 403);                               // anything not-true HALTS the upload
    }
}
```

Both facades are consulted because `/_upload` serves BOTH universes (it carries `#[Route]` and `#[Portal_Route]`).

**The gate fires AFTER file validation and the size check**, so its payload carries the real file: `file`, `filename`, `size`, `mime_type`, `extension`, `tmp_path` — plus `request`, `user`, `params`. Read `tmp_path` to reject on CONTENT rather than on a client-supplied name:

```php
if (str_starts_with((string) $data['mime_type'], 'image/') && @getimagesize($data['tmp_path']) === false) {
    return response()->json(['success' => false, 'error' => 'Not a real image'], 422);
}
```

`user` is **realm-honest**: a portal request reports the portal user, never a staff-facade read that would be null for a logged-in portal uploader. The fork is on the REALM OF THE REQUEST, not on who happens to be signed in.

---

## 3. Claiming and attaching (server)

```php
#[Ajax_Endpoint]
#[Auth('can_manage_projects')]                 // MANDATORY on every endpoint
public static function save_document(Request $request, array $params = [])
{
    $project = Project_Model::find($params['project_id']);

    $attachment = File_Attachment_Model::find_by_key($params['file_key']);
    if (!$attachment || !$attachment->can_user_assign_this_file()) {
        return response_error(Ajax::ERROR_UNAUTHORIZED, 'Invalid file');
    }

    $attachment->add_to($project, 'documents');
    return ['ok' => true];
}
```

**`can_user_assign_this_file()` is STRUCTURAL, not a permission check.** It answers exactly two questions: is this attachment still unclaimed, and does its `site_id` match the current request's site (asked through the portal facade on a portal request, the staff facade otherwise). There is deliberately no "did the same person upload this" test. What actually protects an unclaimed upload is the combination of the unguessable key, the single-claim rule, and the claim window (`rsx.attachments.unattached_claim_window_hours`, 24h, swept 6-hourly into retention). `created_by_ip_address` is **audit metadata, never a guard**.

WHO may upload and WHO may claim are the application's decisions: the gate above, plus the `#[Auth]` and record-level checks in your own endpoint.

Because the test is structural, **the claim flow works unchanged under a Bearer API identity** — a key is a staff session with a real `site_id`. An `#[Api_Endpoint]` claims a key from `POST /api/v1/files` exactly as the `#[Ajax_Endpoint]` above claims one from `/_upload`.

**`attach_to()` vs `add_to()`** — both throw if the attachment is not claimable:

```php
$attachment->attach_to($user, 'profile_photo');   // SINGLE: detaches whatever was in that category
$attachment->add_to($project, 'documents');       // MULTIPLE: adds alongside existing ones
$attachment->detach();                            // clears the owner, keeps the attachment
```

**Retrieving** (on any model):

```php
$photo = $user->get_attachment('profile_photo');          // ?File_Attachment_Model
foreach ($project->get_attachments('documents') as $doc)  // Rsx_Result_Set - every row, paged
    echo $doc->file_name;
foreach ($project->get_all_attachments() as $file)        // EVERY category, no limiter
    echo $file->fileable_category;
```

`get_attachments()` returns a result set, not an array, because one record's attachment count has no ceiling. `get_all_attachments()` is the category-less form — what a delete cascade, an export or an audit needs, none of which can enumerate categories up front.

**Acting on an attachment the CALLER named needs `find_attachment()`:**

```php
$doc = $project->find_attachment($params['attachment_id'], 'documents');   // null unless it is THIS project's
if (!$doc) { return response_not_found('Not found'); }
```

`File_Attachment_Model::find()` and `find_by_key()` are tenant-scoped and nothing more — within one site they will hand back an attachment hanging off a *different* record, so removing/renaming/sharing by a caller-supplied id without this is a real hole. A miss and a mismatch are the same null. On a site-scoped record every reader here is additionally pinned to the current session's site, so they stay correct even inside `without_site_scope()`.

---

## 4. The size ceiling — one number, never a hardcoded label

`config('rsx.files.max_file_size')` (PHP) and `window.rsxapp.files.max_file_size` (JS, always injected) are the SAME number in bytes.

**Never hardcode it in a label.** "Max size 25MB" in a template is wrong the day the limit changes, and nothing breaks to tell you — the label just quietly lies.

```blade
<p>Maximum file size: {{ Ajax::max_file_size_human() }}</p>
```
```jqhtml
<p>Maximum file size: <%= Ajax.max_file_size_human() %></p>
```

Both render identically ("100 MB", "1.2 GB", "Unlimited"), rounded for a LABEL. That is **not** `bytes_to_human()`, which faithfully renders an actual file's size ("1.22 GB") — use that one for a file you are displaying, never for the limit.

A tighter app cap: `min()` it against the framework number and render it the same way with `Ajax::bytes_to_size_label($bytes)` / `Ajax.bytes_to_size_label(bytes)`.

**Enforcement order**: `/_upload` rejects an over-size file with 413 **before** your gate runs (no point running app code over a file already refused), and `Ajax.upload()` checks the same number client-side and throws before sending, so the user is not left pushing a doomed file up a slow connection.

**The default is derived, not invented**: the SMALLER of `upload_max_filesize` / `post_max_size` from the running SAPI (both bind), falling back to 100 MB when neither is set or either is unlimited. Raising the config above your php.ini does nothing — PHP discards an oversized body pre-userland (you get a message naming `post_max_size` instead of a bare "No file uploaded").

**`0` means no framework ceiling, and client code must read it as UNLIMITED, never as "reject everything".**

---

## 5. Displaying files

```php
$attachment->get_url();            // "/_inline/{key}"   - view in browser
$attachment->get_download_url();   // "/_download/{key}" - forces the download dialog
```

Two different routes. There is no `?download=1` query parameter.

```jqhtml
<a href="<%= doc.download_url %>"><%= doc.file_name %></a>
```

(Resolve those two URLs server-side in `fetch()`/an endpoint and pass them down — `get_url()` is PHP.)

### Thumbnails: `<Attachment_Thumbnail>` is the way, and the only way

```jqhtml
<Attachment_Thumbnail $attachment_id=doc.file_attachment_id $width=160 />
<Attachment_Thumbnail $attachment_id=id $type="cover" $width=96 $height=96 />
<Attachment_Thumbnail $attachment_id=id $preset="profile" />
```

```javascript
$el.component('Attachment_Thumbnail', {attachment_id: id, width: 160});
```

| Arg | |
|---|---|
| `$attachment_id` | **required** — throws without it. Never pass null; render your initials/empty branch instead. |
| `$type` | `'fit'` (default) or `'cover'` |
| `$width` | px, default 120 |
| `$height` | px; omitted → a width-only variant |
| `$preset` | a name from `config('rsx.thumbnails.presets')` — **mutually exclusive** with `$type`/`$width`/`$height`, passing both throws |
| `$alt` | defaults to the attachment's file name |

Extra classes go on the invocation as an ordinary HTML attribute (`class="me-2"`), which jqhtml keeps on the host element — there is no `$class` arg. Style the host and let the picture fill it:

```scss
&__thumb {
    width: 36px;
    height: 36px;

    .Attachment_Thumbnail__img,
    .Attachment_Thumbnail__placeholder { width: 100%; height: 100%; object-fit: contain; }
}
```

**A producer ships `file_attachment_id`, never a URL** — and stops shipping `key`, `thumbnail_url` and render-state fields entirely. The component fetches the record itself through `File_Attachment_Model.fetch_or_null()`, which is **batched**, so a table of forty thumbnails costs one request.

**Why a component and not a URL helper.** A URL is a value; a thumbnail is a live view of a record whose picture may not exist yet. An Office document has no thumbnail until the background worker has rendered it, so what first paints is an extension-icon placeholder; when the render lands, a realtime frame brings the component back for a fresh record and the image swaps in place. Only a component has the lifecycle to subscribe, refetch and repaint. That is why **nothing in an app builds a thumbnail URL** — and why the URLs carry `?v=<rendered_at>`, without which a browser would keep a year-cached placeholder forever. See `rspade:document-preview` and `rsx:man thumbnails`.

The same component works unchanged on **portal** pages (`File_Attachment_Model` ships both `fetch()` and `portal_fetch()`). Your `file.thumbnail.authorize` handler is now load-bearing for the portal realm through `fetch` — **it must answer correctly when `'user'` is a `Portal_User_Model`**, or every portal thumbnail silently becomes an empty box.

A record that cannot be fetched (deleted, or not visible to this caller) paints a quiet empty box, never an error.

`File_Attachment_Model.thumbnail_url(record, {type, width, height, preset})` is the single JS builder the component calls. Reach for it directly only when a component genuinely cannot sit at that spot — a widget addressed by attachment **key** rather than id, say — and say why in a comment. Presets live in `config('rsx.thumbnails.presets')` (`profile`, `gallery`, `icon_small`, `icon_large` ship) and **throw** if undefined. `has_thumbnail()` is true iff a renderer is registered for the mime or the blob has rendered.

---

## 6. Model properties and programmatic creation

```php
$attachment->key;             // unguessable identifier used in every URL
$attachment->file_name;       // original filename
$attachment->file_extension;  // lowercased, no dot
$attachment->mime_type;
$attachment->get_size();      // bytes (works even for externally-resident bytes)
$attachment->fileable_type;   // polymorphic PAIR with fileable_id (+ fileable_category)
```

Authorship is the polymorphic pair (`created_by_type` + `created_by_id`), never a bare id — see `rspade:actors-and-authorship`.

`site_id` is REQUIRED in `$params` for all three factories:

```php
File_Attachment_Model::create_from_disk('/tmp/import/report.pdf', [
    'site_id'  => $site_id,
    'filename' => 'Q3 Report.pdf',
]);

File_Attachment_Model::create_from_string($csv, 'export.csv', ['site_id' => $site_id]);

File_Attachment_Model::create_from_url('https://example.com/logo.png', ['site_id' => $site_id]);
```

They return an UNATTACHED attachment — attach it yourself with `attach_to()`/`add_to()` (trusted server code, so the claim guard is not in your way for handler-backed rows).

---

## 7. Deleting is a retention window, not destruction

```php
$attachment->delete();          // ENTERS the retention window: soft-delete, blob preserved
```

Nothing is erased. Recovery:

```php
foreach ($project->get_deleted_attachments('documents') as $a) { /* "Recently Deleted" view */ }
File_Attachment_Model::get_deleted_files($project, 'documents');   // both args optional
$attachment->undelete();
```

`force_destroy()` is the **only** immediate erasure — it bypasses the retention window AND the `file.attachment.destroy.hold` gate. Use it when a record must genuinely be gone now, not as a tidier `delete()`.

**`File_Disposal_Service` is the SOLE blob-release authority.** No other code unlinks a blob. It runs a daily destroy+release pass and a monthly orphan sweep, plus the 6-hourly unclaimed-upload sweep. A blob is released only when NO live-or-retained attachment pins it — a **retention-aware** refcount, so a file still recoverable in someone's recycle bin keeps its bytes alive.

Two hooks, both receiving the attachment:

```php
#[OnEvent('file.attachment.destroy.hold')]      // GATE: true PERMITS destruction
public static function keep_legal_holds($attachment) {
    return $attachment->fileable_type !== 'Legal_Case_Model';   // false = held, retried next run
}

#[OnEvent('file.attachment.destroyed')]         // ACTION: after the decision, before the stamp
public static function log_destruction($attachment) { /* a throw defers, never half-destroys */ }
```

Retention windows and lookbacks are `config('rsx.files.*')`; the full contract is `rsx:man file_disposal`.

---

## 8. Multi-file ZIP download

```php
#[Ajax_Endpoint]
#[Auth('is_logged_in')]
public static function download_all(Request $request, array $params = [])
{
    $project = Project_Model::find($params['project_id']);
    // ... your own authorization for THIS project ...

    $files = [];
    foreach ($project->get_attachments('documents') as $doc) {
        $files[] = ['key' => $doc->key, 'name' => $doc->file_name];   // 'name' optional
    }

    $zip = Zip_Download_Request_Model::create_request($files, 'project-documents.zip');
    return ['url' => $zip->get_download_url()];      // browser navigates to /_download_zip/:key
}
```

`create_request()` validates the STRUCTURE fail-loud (array of `{key, name?}`, no other keys, at least one entry) and **authorizes nothing** — every member is re-gated per file at download time. Unresolvable members become `~ERROR~<name>.inf` markers inside the archive rather than failing the whole download. The stream is constant-memory (hand-rolled `Zip_Stream`, no dependency) and there is **no ZIP64**: over 4GB or 65k entries is rejected. The request is valid 24h, is **not consumed on use**, and is pruned every 6h.

---

## 9. The authorization hooks

| Hook | Kind | Fires |
|---|---|---|
| `file.upload.authorize` | gate | on `/_upload` (MANDATORY, see §2) |
| `file.download.authorize` | gate | `/_download`, `/_inline`, ZIP members, PDF renditions |
| `file.thumbnail.authorize` | gate | thumbnails, previews, and (with download) the dual-gated byte routes |
| `file.upload.params` / `file.upload.response` | filter | shape the create params / the JSON response |
| `file.upload.complete` | action | logging, notifications |

Gates follow the framework convention: return `true` to permit, anything else halts (return a JSON response to control the message).

```php
#[OnEvent('file.download.authorize', priority: 10)]
public static function only_own_site($data)
{
    $attachment = $data['attachment'];
    if ($attachment->site_id !== Session::get_site_id()) {
        return response()->json(['error' => 'Access denied'], 403);
    }
    return true;
}
```

Do not compare `created_by` to a user id — authorship is a polymorphic PAIR and answers "who wrote the row", not "who may read the file". Scope by owner record, site, or membership instead.

---

## System endpoints

`POST /_upload` · `GET /_download/:key` · `GET /_inline/:key` · `GET /_download_zip/:key` · `GET /_thumbnail/preset/:key/:preset` · `GET /_thumbnail/dynamic/:key/:type/:width/:height?` · `GET /_icon_by_extension/:extension` · `GET /_preview/pdf/:key`

All of those except `/_icon_by_extension` also accept `Authorization: Bearer rsx_...`, so an integration reaches bytes with no browser session (a bad Bearer is a 401 — it never degrades to anonymous). The external API adds `POST /api/v1/files` · `GET /api/v1/files/:key` · `GET /api/v1/files/:key/text`.

---

## Troubleshooting

- **`/_upload` returns 500 "File uploads are disabled"** — no `file.upload.authorize` handler. Write one in `/rsx/handlers/`.
- **413 on upload** — over `rsx.files.max_file_size`, or over php.ini's `post_max_size` (the error names which).
- **"Not authorized to assign this attachment"** — the attachment is already claimed, or its `site_id` does not match the request's site (common when a portal page uploads and a staff-context endpoint tries to claim).
- **The upload works from the staff app but 419/404s from the portal** — a hand-rolled `fetch('/_upload')`. Use `Ajax.upload()`.
- **The size label disagrees with enforcement** — a hardcoded number. Use `max_file_size_human()`.
- **A deleted file is still on disk** — correct: it is in the retention window. Only `File_Disposal_Service` (or `force_destroy()`) releases blobs.

Details: `php artisan rsx:man file_upload` · `file_disposal` · `thumbnails` · `droppable`. Related: `rspade:document-preview`, `rspade:event-hooks`, `rspade:auth-gates`, `rspade:external-api` (uploading and attaching over the REST API).
