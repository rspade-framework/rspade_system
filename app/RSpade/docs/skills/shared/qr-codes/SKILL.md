---
name: qr-codes
description: "Generating a QR code with Rsx_Qr_Code::generate($text) - the returned {svg, size_px, size_in, modules}, embedding the inline SVG in a jqhtml or Blade template, and sizing it for a screen and for a printed sheet. Use when a page, label, ticket, badge, invoice or print sheet needs a QR code or a scannable link, when tempted to install a QR package or to call BaconQrCode directly, when asked for a QR image, PNG, data URI or <img> tag, or when a printed code will not scan or a code disappears in dark mode."
---

# QR codes

`Rsx_Qr_Code::generate($text)` is the one way to make a QR code. **Do not install a QR package and do not call `BaconQrCode` yourself** — the library is a framework-internal dependency, not part of the supported surface.

```php
use App\RSpade\Core\Qr\Rsx_Qr_Code;

$qr = Rsx_Qr_Code::generate(rsx_absolute_url(Rsx::Route('Tickets_View_Action', $ticket->id)));

// [
//     'svg'     => '<svg ...>...</svg>',   the inline SVG
//     'size_px' => 148,                    recommended side length on screen, CSS pixels
//     'size_in' => 1.0,                    recommended side length on paper, inches
//     'modules' => 37,                     modules per side, quiet zone included
// ]
```

It runs on the server and returns plain data: put it in the Ajax payload or view variables that already carry the page's data. **Encode an absolute URL** (`rsx_absolute_url(...)`) — a phone scanning a relative path has nowhere to go.

## What is already decided

Nothing is configurable, on purpose.

| | |
|---|---|
| Error correction | Level Q: reads with about a quarter of the code damaged. Chosen for paper. |
| Colours | Black on opaque white, always. A dark theme does not reach it. |
| Quiet zone | The required four-module white margin is **inside** the image. Leave no extra clearance. |
| Format | Inline SVG only. Vector, so sharp on screen and in print. No PNG, no `<img>`, no data URI. |
| Delivery | Generated where the content is known. No URL serves one; do not build an endpoint that encodes text on request. |

## On a screen

Interpolate the SVG raw. It is markup, and every character of it comes from the framework, none from your text.

```jqhtml
<div class="Ticket_Card__qr"><%!= this.data.qr.svg %></div>
```

Blade: `{!! $qr['svg'] !!}`

The SVG carries `size_px` as its own width and height, so untouched it is already the recommended size. To let it shrink on a narrow screen:

```scss
.Ticket_Card {
    &__qr svg { display: block; max-width: 100%; height: auto; }
}
```

## On paper

Size the element in inches from `size_in`; browsers honour physical units when printing.

```jqhtml
<div class="Ticket_Sheet__qr" style="width: <%= this.data.qr.size_in %>in">
    <%!= this.data.qr.svg %>
</div>
```

```scss
.Ticket_Sheet {
    &__qr svg { display: block; width: 100%; height: auto; }
}
```

Print the link as text beside the code as well: a sheet should still work for somebody without a camera.

## Size

The sizes are computed per code, because more text means more modules: 4 CSS pixels and 0.02 inch per module, never under one inch on paper. A short URL comes out near 150 px and 1.0 in. **Larger is always safe; smaller than `size_in` on paper is where scanning fails.** A code is square, so each size is one number.

## Gotchas

- **Keep the text short.** Encode a URL that leads to the content, not the content. Every extra character makes the code denser and its recommended size larger.
- **Empty text throws** `InvalidArgumentException`; text beyond a QR code's capacity (about 1,600 characters) throws from the encoder.
- **An email cannot carry one** — mail clients strip inline SVG. Link to a page that shows the code.
- **Never recolour or crop it in CSS.** `fill`, `filter: invert()` or a negative margin on the SVG removes the contrast or the quiet zone scanners depend on.
- **`<%= %>` prints the markup as text.** The SVG needs `<%!= %>`.

Details: `rsx:man qr_codes`. In use: the authenticator enrollment screen (`system/app/RSpade/Core/TwoFactor/Totp_Enrollment.jqhtml`).
