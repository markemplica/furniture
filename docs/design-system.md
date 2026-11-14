# FurniCraft design system

FurniCraft should feel like a calm, well-run local furniture showroom. The shared foundation and page styling live in `public/css/furnicraft.css`; they add no font download, framework, or new build step.

## Foundations

| Purpose | CSS token | Value |
| --- | --- | --- |
| Background | `--color-bg` | `#f7f4ee` |
| Surface / soft surface | `--color-surface`, `--color-surface-muted` | `#fcfaf6`, `#eee8df` |
| Text / secondary text | `--color-text`, `--color-text-muted` | `#28231f`, `#62594f` |
| Hairline | `--color-border` | `#d8cfc4` |
| Single accent / hover | `--color-accent`, `--color-accent-hover` | `#4b3326`, `#38251c` |
| Semantic feedback | `--color-success`, `--color-warning`, `--color-error` | `#2e583f`, `#6f4d1b`, `#8a3434` |

The matching `--color-*-bg` tokens supply soft alert surfaces. WCAG AA contrast checks: text/background 14.17:1, secondary text/background 6.25:1, accent/surface 11.19:1, and semantic text on its alert surface 6.92:1 or higher. Use the semantic colors only for status and errors.

Headings use `--font-heading` (Iowan Old Style, Palatino, Georgia fallbacks); body and controls use `--font-body` (Aptos, Segoe UI, system sans fallbacks). Text sizes run from `--text-xs` through `--text-4xl`; body text is 16px with 1.6 line-height. Keep long text near `--measure` (70 characters). Spacing uses 8px increments (`--space-1` through `--space-8`). The container is `--container-width` (75rem). `.fc-grid` offers 12 columns at desktop widths and one column on mobile.

## Shared components

- **Navigation and footer:** warm light surfaces, hairline borders, clear active links, and 44px minimum navigation targets.
- **Buttons:** `.btn-primary` for the walnut action, `.btn-outline-primary` for secondary actions, `.btn-outline-secondary` for quiet actions. All buttons have a 44px minimum height and 4px corners.
- **Forms:** `.form-label`, `.form-control`, `.form-select`, `.form-text`, `.is-invalid`, and `.invalid-feedback` share spacing, contrast, focus, and error colors.
- **Surfaces:** `.panel`, `.fc-card`, `.filter-panel`, `.metric`, and product/recommendation cards use restrained borders and 6px corners without heavy shadows.
- **Status and feedback:** `.status`, `.badge`, `.fc-alert` with semantic variants, `.empty-state`, `.spinner-border`, and `[aria-busy="true"]` cover status, empty, and loading states.
- **Data and overlays:** `.table-responsive`, `.table`, `.pagination`, `.reservation-dialog`, `.profile-action-dialog`, and `.modal-content` use the same border, radius, and focus treatment.

Keep catalog images in consistent 4:3 frames with `object-fit: contain`; avoid stretching or cropping furniture. The catalog uses a desktop filter sidebar, a native collapsible filter section on mobile, and an image-led product grid. Search stays visible at every width; the existing typeahead and pagination keep their hooks. Product details pair a large image with a hairline specification list and clearly separated reservation action. Motion is limited to 150–200ms color and border changes and is removed when reduced motion is requested. Preserve visible labels and keyboard focus.
