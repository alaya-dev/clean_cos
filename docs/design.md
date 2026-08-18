# ToutDispo — Design System and UI Specification

**Document version:** 1.0  
**Status:** Production design source of truth  
**Documentation language:** English  
**Application interface language:** French only  
**Platforms:** Public storefront and private back office  
**Primary target:** Mobile-first Tunisian cosmetics e-commerce  
**Related documents:** `prd.md`, `roles-authorization-matrix.md`, `system-design.md`, `api-contracts.md`, `security.md`, `privacy.md`, `quality-rules.md`

---

# 1. Purpose

This document defines the complete visual, interaction, responsive, accessibility, and component system for ToutDispo.

It covers:

- Brand direction
- Color, typography, spacing, shapes, borders, shadows, and imagery
- Front-office navigation and page layouts
- Back-office design system and information architecture
- Forms, tables, filters, dialogs, drawers, and notifications
- Loading, empty, success, error, and conflict states
- Motion and transition rules
- Responsive behavior
- Accessibility
- SEO-safe rendering
- Performance-conscious UI implementation
- French interface copy conventions
- Codex implementation rules

This file translates the supplied visual prototype direction into a production design compatible with the approved Laravel, Blade, Vue, security, performance, and privacy architecture.

---

# 2. Production Adjustments to the Original Prototype Direction

The original concept included prototype-only behavior. The production application must use the following corrected rules.

## 2.1 Rendering and navigation

Original prototype assumption:

```text
All navigation is client-side and front-end only.
```

Production rule:

- Public storefront pages are server-rendered by Laravel Blade.
- Vue is used only for interactive islands.
- Normal links use real URLs and progressive navigation.
- The private back office is a Vue SPA.
- The storefront must remain useful when optional JavaScript fails, except for interactions that inherently require JavaScript such as the live cart drawer or Ritual Finder.

## 2.2 Cart and checkout

- Cart may persist in `localStorage` for seven days.
- The browser cart is never authoritative for prices, discounts, shipping, or stock.
- Checkout uses the Laravel API.
- Successful checkout clears the local cart only after the server confirms order creation.
- Checkout success is a dedicated signed confirmation page.

## 2.3 Cookie and privacy preferences

- Privacy preferences may be stored in browser storage.
- The application must also retain the consent state required for order-linked Meta attribution.
- Meta Pixel and CAPI remain blocked until the applicable consent condition is satisfied.
- The privacy preference interface must provide Accept, Refuse, and Manage actions.

## 2.4 Reviews and ratings

The approved product scope contains no product review system.

Therefore:

- Do not display a star rating.
- Do not show fake review counts.
- Do not show placeholder social proof.
- The product-detail hierarchy should use product category, name, size, price, description, variant selection, reassurance, and purchase action instead.

## 2.5 Newsletter

Newsletter collection is not included in the approved functional scope.

Therefore:

- The footer may reserve a visual content area for future newsletter functionality.
- The production launch must not collect an email address unless newsletter consent, storage, API behavior, privacy notice, and business rules are approved.
- Until approved, replace the newsletter form with a quiet brand statement, contact prompt, or social links.

## 2.6 Delivery and return promises

Text such as:

```text
Livraison offerte dès 199 DT
Retours sous 14 jours
```

must not be hard-coded.

Rules:

- Delivery fee and free-delivery threshold come from store settings.
- Reassurance messages must reflect the approved policy.
- Return-period text appears only when the final legal/business policy explicitly supports it.
- Recommended safe initial reassurance:
  - `Paiement à la livraison`
  - `Commande confirmée par téléphone`
  - `Livraison partout en Tunisie`
- These labels remain editable by the Super Admin where content management supports them.

## 2.7 Product categories

The system supports flat, owner-managed categories.

Names such as:

- Visage
- Corps
- Maison
- Soins capillaires
- Huiles & hydrolats

are examples, not hard-coded taxonomy.

## 2.8 Imagery

The original direction is preserved:

- No women’s or men’s faces as the primary visual language.
- No before-and-after skin imagery.
- No misleading medical imagery.
- Use product, packaging, ingredients, botanical still life, candles, textures, towels, water, ceramics, flowers, and spa-like scenes.

---

# 3. Brand Experience

## 3.1 Brand personality

ToutDispo should feel:

- Elegant
- Warm
- Confident
- Calm
- Approachable
- Editorial
- Product-led
- Premium through restraint
- Modern without feeling cold
- Feminine enough for the category without becoming stereotypically pink

## 3.2 Emotional objective

The experience should suggest:

> A calm beauty boutique in daylight, with cream paper, refined packaging, soft blush accents, deep ink typography, and tactile product photography.

The interface should never feel:

- Loud
- Cheap
- Over-decorated
- Childlike
- Trend-dependent
- Like a generic dropshipping template
- Like a medical pharmacy system
- Like a pink-only beauty blog

## 3.3 Design principles

1. **Product first**  
   Product photography and price clarity lead the shopping experience.

2. **Editorial restraint**  
   Typography and composition create premium character without decorative overload.

3. **Warm contrast**  
   Warm neutral surfaces are balanced by deep ink and selective pink.

4. **Clear commerce**  
   Purchase actions are obvious, fast, and predictable.

5. **Mobile confidence**  
   The mobile experience is not a reduced desktop design; it is the primary interaction model.

6. **Motion with purpose**  
   Motion explains state changes and hierarchy, not decoration.

7. **Accessible elegance**  
   Premium styling must not reduce contrast, readability, keyboard access, or touch usability.

8. **Operational simplicity**  
   The back office should feel calm and efficient, not like a consumer landing page.

---

# 4. Design Tokens

Design tokens must be defined centrally and shared across Blade, Vue storefront islands, and the admin SPA.

Recommended implementation:

```text
resources/css/tokens.css
```

or an equivalent Tailwind theme.

---

# 5. Color System

## 5.1 Core brand colors

```css
:root {
  --color-ink: #1e1719;
  --color-paper: #fffdfc;
  --color-cream: #f4efed;
  --color-pink: #f7d9e3;
  --color-accent-pink: #ed9cbb;
  --color-line: #e4dbd8;
  --color-muted: #756a69;
  --color-accent-dark: #ad5272;
}
```

## 5.2 Extended neutral tokens

```css
:root {
  --color-white: #ffffff;
  --color-surface-raised: #ffffff;
  --color-surface-subtle: #faf7f5;
  --color-ink-soft: #3a3033;
  --color-muted-light: #9a8f8d;
  --color-line-strong: #cfc3bf;
  --color-overlay: rgba(30, 23, 25, 0.48);
}
```

## 5.3 Semantic colors

Semantic states must be distinguishable from the brand pink palette.

```css
:root {
  --color-success: #26734d;
  --color-success-bg: #e8f4ed;
  --color-warning: #8a5a13;
  --color-warning-bg: #fbf1dc;
  --color-danger: #a33a3a;
  --color-danger-bg: #fbe9e8;
  --color-info: #315d7c;
  --color-info-bg: #e8f0f5;
}
```

Semantic colors must always be paired with:

- Text
- Icon
- Label
- Shape or border

Color alone must never communicate a status.

## 5.4 Color usage rules

### Paper

Use `--color-paper` for:

- Main storefront background
- Header
- Product-detail main surface
- Forms
- Admin page background
- Primary cards

### Cream

Use `--color-cream` for:

- Alternating storefront sections
- Secondary panels
- Empty states
- Admin filter bars
- Subtle grouped content

### Ink

Use `--color-ink` for:

- Main headings
- Primary text
- Primary buttons
- Footer
- Important icons
- Strong borders where needed

### Pale pink

Use `--color-pink` for:

- Selected filters
- Checkout summary
- Selected Ritual Finder choice
- Soft promotional panel
- Highlighted admin helper panel
- Success illustration accents

### Accent pink

Use `--color-accent-pink` sparingly for:

- Announcement bar
- Hero stamp
- Small editorial blocks
- Active navigation mark
- Promotional badge
- Focused accent illustration

### Accent dark

Use `--color-accent-dark` for:

- Eyebrows
- Small links
- Active text accents
- Secondary emphasis

## 5.5 Prohibited color usage

Do not:

- Make every section pink.
- Use pink as the default admin status color.
- Use low-contrast pale pink text.
- Use green or olive as the brand foundation.
- Use glossy rainbow gradients.
- Use neon colors.
- Use pure black `#000000` for large surfaces unless a later visual review approves it.
- Create random feature colors without tokens.

---

# 6. Typography

## 6.1 Font families

```css
:root {
  --font-display: "Playfair Display", Georgia, serif;
  --font-interface: "Manrope", Arial, sans-serif;
  --font-mono: "DM Mono", "SFMono-Regular", Consolas, monospace;
}
```

## 6.2 Font responsibilities

### Playfair Display

Use only for:

- Editorial italic phrase inside a hero heading
- Selected section-title phrase
- Thank-you phrase
- Large brand statement
- Rare pull quote

Do not use Playfair for:

- Form labels
- Buttons
- Tables
- Prices
- Navigation
- Long body text

### Manrope

Use for:

- Headings
- Navigation
- Product names
- Buttons
- Forms
- Body copy
- Tables
- Admin interface
- Prices

### DM Mono

Use for:

- Eyebrows
- Product metadata
- Order references
- Small system labels
- Status metadata
- Timestamps
- Section identifiers

Do not use DM Mono for long paragraphs.

## 6.3 Font loading

- Self-host approved font files where licensing permits.
- Preload only the required initial weights.
- Use `font-display: swap`.
- Keep initial font files within the performance budget.
- Avoid loading every available weight.
- Recommended initial weights:
  - Manrope 400
  - Manrope 500
  - Manrope 600
  - Manrope 700
  - Playfair Display 500 italic
  - DM Mono 400

## 6.4 Type scale

Use fluid responsive sizing through `clamp()`.

```css
:root {
  --text-xs: clamp(0.6875rem, 0.66rem + 0.10vw, 0.75rem);
  --text-sm: clamp(0.75rem, 0.72rem + 0.12vw, 0.875rem);
  --text-base: clamp(0.875rem, 0.82rem + 0.18vw, 1rem);
  --text-lg: clamp(1rem, 0.92rem + 0.30vw, 1.25rem);
  --text-xl: clamp(1.25rem, 1.10rem + 0.55vw, 1.625rem);
  --text-2xl: clamp(1.625rem, 1.35rem + 0.95vw, 2.25rem);
  --text-3xl: clamp(2rem, 1.55rem + 1.60vw, 3.25rem);
  --text-hero: clamp(2.5rem, 1.65rem + 3.00vw, 5.5rem);
}
```

## 6.5 Heading rules

### Hero heading

- Manrope 700
- Tight letter spacing
- Line height 0.96–1.05
- One optional italic Playfair phrase
- Maximum comfortable line length
- Avoid more than four lines on mobile

### Section heading

- Manrope 650–700
- Negative tracking
- Line height 1.05–1.15

### Product name

- Manrope 600–700
- Compact
- Uppercase only where visual testing shows readability
- Never use uppercase for long names

### Body copy

- Manrope 400
- Desktop generally 14–16 px
- Mobile generally 13–15 px
- Line height 1.55–1.7
- Do not use 11 px body copy for important text

The original prototype suggested 11–13 px mobile body text. Production accessibility requires important body text to remain at least approximately 13–14 px, with 16 px for form inputs to prevent mobile zoom behavior.

## 6.6 Eyebrows

- DM Mono
- Uppercase
- Wide tracking
- 11–12 px
- Accent dark or muted
- Maximum one short line

## 6.7 Price typography

- Manrope 600–700
- Use tabular numbers
- Keep currency readable
- Sale price leads visually
- Old price is muted and struck through
- Never rely on red alone to communicate a promotion

## 6.8 Text width

Recommended body-copy maximum:

```text
60–72 characters
```

Editorial text blocks may be narrower.

---

# 7. Spacing System

Use a consistent 4 px base.

```css
:root {
  --space-0: 0;
  --space-1: 0.25rem;
  --space-2: 0.5rem;
  --space-3: 0.75rem;
  --space-4: 1rem;
  --space-5: 1.25rem;
  --space-6: 1.5rem;
  --space-8: 2rem;
  --space-10: 2.5rem;
  --space-12: 3rem;
  --space-16: 4rem;
  --space-20: 5rem;
  --space-24: 6rem;
  --space-32: 8rem;
}
```

## 7.1 Storefront section spacing

Desktop:

```text
80–128 px vertical
```

Mobile:

```text
48–72 px vertical
```

## 7.2 Admin spacing

Admin pages are denser:

- Page vertical gap: 24–32 px
- Card padding: 20–24 px desktop
- Card padding: 16 px mobile
- Form field gap: 16–20 px
- Table row vertical padding: 12–16 px

## 7.3 Compactness rules

Do not:

- Reduce touch targets to make more content fit.
- Use excessive blank space inside operational tables.
- Make storefront sections feel like admin panels.
- Make admin pages use oversized marketing typography.

---

# 8. Layout and Grid

## 8.1 Content container

```css
:root {
  --container-max: 1440px;
  --container-reading: 760px;
  --container-form: 720px;
}
```

Horizontal padding:

- Mobile: 16 px
- Large mobile: 20 px
- Tablet: 28 px
- Desktop: 40–64 px

## 8.2 Breakpoints

Recommended:

```text
sm: 480 px
md: 768 px
lg: 1024 px
xl: 1280 px
2xl: 1536 px
```

Use content-driven responsive behavior rather than device-specific assumptions.

## 8.3 Product grid

Mobile:

```text
2 columns
12–16 px gap
```

Tablet:

```text
3 columns
20 px gap
```

Desktop:

```text
4 columns
24–32 px gap
```

Very large screens may remain four columns to preserve premium image size.

## 8.4 Admin grid

Dashboard:

- Mobile: one column
- Tablet: two columns
- Desktop: 12-column grid

Forms:

- Mobile: one column
- Desktop: two columns only for short related fields
- Long text, rich text, uploads, and security settings remain full width

---

# 9. Shapes, Borders, and Radius

## 9.1 Shape philosophy

The system should feel soft but not bubbly.

Use:

- Crisp editorial image frames
- Moderately rounded controls
- Selectively rounded panels
- Circular icon buttons
- Pill shapes only for compact status, filters, and variants

Avoid:

- Every section inside a rounded card
- Large pill-shaped containers around normal paragraphs
- Nested rounded cards
- Excessive floating bubbles

## 9.2 Radius tokens

```css
:root {
  --radius-xs: 4px;
  --radius-sm: 8px;
  --radius-md: 12px;
  --radius-lg: 18px;
  --radius-xl: 28px;
  --radius-pill: 999px;
  --radius-circle: 50%;
}
```

Recommended usage:

| Element | Radius |
|---|---|
| Text input | 8–12 px |
| Primary button | 999 px or 12 px, selected consistently |
| Product image | 0–12 px |
| Admin card | 12 px |
| Checkout summary | 18 px |
| Hero image | Mostly square with selected clipped edge |
| Status badge | Pill |
| Icon button | Circle |
| Drawer | 0 on outer edge; 18–24 px optional on inner edge |

## 9.3 Border tokens

```css
:root {
  --border-default: 1px solid var(--color-line);
  --border-strong: 1px solid var(--color-line-strong);
  --border-focus: 2px solid var(--color-accent-dark);
}
```

## 9.4 Dividers

Use dividers to structure:

- Basket items
- Product metadata
- Admin sections
- Tables
- Settings groups

Prefer dividers over placing every item in a card.

---

# 10. Shadows and Elevation

## 10.1 Shadow tokens

```css
:root {
  --shadow-xs: 0 1px 2px rgba(30, 23, 25, 0.05);
  --shadow-sm: 0 6px 20px rgba(30, 23, 25, 0.07);
  --shadow-md: 0 16px 48px rgba(30, 23, 25, 0.10);
  --shadow-drawer: -18px 0 50px rgba(30, 23, 25, 0.14);
}
```

## 10.2 Usage

- Product cards generally use no shadow.
- Hover may introduce a very subtle image/frame elevation.
- Drawers and dialogs use shadow.
- Admin cards use border plus optional `shadow-xs`.
- Avoid heavy floating-card shadows.
- Never use neon glow.

---

# 11. Iconography

## 11.1 Style

Use one icon family with:

- Clean line icons
- Rounded or refined stroke ends
- Consistent stroke width
- Minimal detail

Recommended types:

- Search
- Shopping bag
- Menu
- Close
- Arrow
- Plus
- Minus
- Trash
- Edit
- Filter
- Sort
- Upload
- Check
- Warning
- Info
- Eye
- More
- External link
- Chevron

## 11.2 Icon sizes

- Inline: 16 px
- Standard control: 20 px
- Prominent action: 24 px
- Decorative confirmation: 40–64 px

## 11.3 Accessibility

- Decorative icons: `aria-hidden="true"`
- Icon-only buttons: accessible name
- Do not use an icon without text for unfamiliar admin actions.

---

# 12. Imagery Direction

## 12.1 Approved imagery

- Cosmetic packaging
- Product groupings
- Botanical ingredients
- Oils
- Flowers
- Stone
- Ceramic
- Linen
- Water
- Candles
- Towels
- Cream and texture close-ups
- Calm bathroom or vanity still life
- Hands only when neutral and product-focused, subject to later approval

## 12.2 Avoid

- Prominent human faces
- Before/after claims
- Medical treatment imagery
- Generic stock portraits
- Neon cosmetics imagery
- Overly glossy 3D beauty renders
- Images with embedded text
- Misleading product scale
- AI imagery that changes actual packaging appearance

## 12.3 Product images

- Neutral or softly styled background
- Consistent aspect ratio
- Accurate packaging color
- No clipping of essential product information
- Variant image matches the selected variant
- Store width/height to prevent CLS

## 12.4 Aspect ratios

Recommended:

- Product card: 4:5
- Product detail main: 1:1 or 4:5
- Hero desktop: 4:5 or editorial crop
- Category card: 4:5
- Banner desktop: 16:7 or 16:8
- Banner mobile: 4:5 or 3:4

Content management should request separate desktop and mobile banner images.

---

# 13. Motion System

## 13.1 Principles

Motion must be:

- Short
- Smooth
- Purposeful
- Interruptible
- Based on opacity and transform
- Reduced or removed for `prefers-reduced-motion`

Do not animate:

- Width
- Height
- Top
- Left
- Large shadows
- Filter blur on large areas
- Layout-critical properties

## 13.2 Duration tokens

```css
:root {
  --duration-instant: 100ms;
  --duration-fast: 180ms;
  --duration-base: 260ms;
  --duration-slow: 420ms;
  --duration-hero: 700ms;
}
```

## 13.3 Easing tokens

```css
:root {
  --ease-standard: cubic-bezier(0.2, 0, 0, 1);
  --ease-enter: cubic-bezier(0.16, 1, 0.3, 1);
  --ease-exit: cubic-bezier(0.4, 0, 1, 1);
}
```

## 13.4 Key motion specifications

### Hero

- Copy: opacity 0 → 1
- Translate Y: 16 px → 0
- Duration: 600–800 ms
- Image may appear with a shorter opacity transition
- Do not delay primary CTA excessively

### Product cards

- Optional viewport reveal
- Translate Y: 10 px → 0
- Duration: 300–420 ms
- Stagger: maximum 60 ms
- Disable staggering for long grids or reduced motion

### Product image hover

- Scale: 1 → 1.05
- Duration: 350–500 ms
- Overflow hidden

### Category card hover

- Translate Y: 0 → -4 px
- Image scale: 1 → 1.03
- Duration: 260 ms

### Buttons

- Translate Y: 0 → -2 px on hover
- Return immediately on active press
- Never move so much that layout appears unstable

### Mobile drawer

- Backdrop fade
- Drawer translate X: 100% → 0
- Duration: 300–350 ms
- Use focus trap
- Close on Escape
- Restore focus to trigger

### Ritual Finder

- Current recommendation fades out quickly
- New recommendation translates 8 px and fades in
- Total transition: 240–320 ms
- Avoid height jumps by reserving reasonable content space

### Confirmation icon

- Scale: 0.7 → 1
- Opacity: 0 → 1
- Duration: 380–450 ms
- No bounce

### Toast

- Translate Y: -8 px → 0
- Fade in
- Duration: 180–240 ms
- Pause auto-dismiss on hover/focus

## 13.5 Reduced motion

Under `prefers-reduced-motion: reduce`:

- Remove reveal translations
- Remove stagger
- Remove smooth scrolling
- Use near-instant opacity changes
- Preserve state feedback

---

# 14. Focus and Interaction States

Every interactive element must define:

- Default
- Hover
- Focus-visible
- Active
- Disabled
- Loading
- Error where relevant
- Selected where relevant

## 14.1 Focus ring

Recommended:

```css
outline: 3px solid rgba(173, 82, 114, 0.35);
outline-offset: 3px;
```

Focus must be visible on paper, cream, pink, and ink surfaces.

## 14.2 Disabled

Disabled controls:

- Lower contrast
- Preserve readable text
- Use `cursor: not-allowed`
- Explain why when not obvious
- Never rely only on opacity below readable contrast

## 14.3 Loading buttons

- Keep button width stable
- Show spinner plus current action text when possible
- Disable repeat submit
- Use `aria-busy`
- Do not replace text with an unlabeled spinner

---

# 15. Core Components

## 15.1 Buttons

### Primary dark

- Ink background
- Paper text
- High contrast
- Main commerce action

Examples:

- `Ajouter au panier`
- `Commander`
- `Enregistrer`
- `Confirmer`

### Primary pink

Use sparingly for:

- Promotional CTA
- Editorial CTA
- Optional subscription action later

### Secondary outline

- Transparent
- Ink border
- Ink text

### Tertiary text

- Text plus arrow
- No large background

### Danger

- Danger text/border
- Filled danger only in final destructive confirmation

### Sizes

| Size | Minimum height |
|---|---:|
| Small | 36 px |
| Standard | 44 px |
| Large | 50–54 px |

Mobile primary commerce buttons should normally be at least 48 px high.

## 15.2 Links

- Clear hover and focus
- Underline for inline body links
- Navigation links may use underline/indicator on active
- Do not use muted text for essential links

## 15.3 Badges

Use for:

- Promotion percentage
- Product tag
- Status
- Low stock
- Meta event state

Badges are compact, not dominant.

## 15.4 Chips and pills

Use for:

- Variant values
- Filter selection
- Status filters
- Date-range shortcuts

Selected:

- Ink text
- Pale pink surface or ink surface depending on context
- Visible border/check indicator

## 15.5 Tooltips

Use only for supplementary help.

- Keyboard accessible
- Short
- Not required to complete a task
- Do not hide essential form instructions only in a tooltip

## 15.6 Toasts

Use for:

- Item added
- Save succeeded
- Export queued
- Retry scheduled

Do not use a toast as the only confirmation for:

- Order creation
- Password reset
- Meta configuration activation
- Destructive action

Those require persistent page or dialog feedback.

---

# 16. Form Design System

## 16.1 General layout

Each form field contains:

1. Label
2. Optional marker when applicable
3. Control
4. Helper text
5. Error text

Do not use placeholder as a label.

## 16.2 Input style

- Paper or white background
- Ink text
- Line border
- Radius 8–12 px
- Height 44–48 px
- Horizontal padding 14–16 px
- Font size 16 px on mobile
- Focus ring plus stronger border

## 16.3 Textarea

- Minimum 120 px height
- Resize vertical
- Character count for long limited content
- Preserve line breaks

## 16.4 Select

- Native select acceptable for simple mobile reliability
- Custom select must support keyboard, screen readers, search where needed, and correct focus
- Do not create a custom select only for appearance

## 16.5 Checkbox and radio

- Minimum visual control 20 px
- Clickable label area
- Clear selected state
- Error state around group
- Keyboard accessible

## 16.6 Toggle

Use for binary settings such as:

- Active/inactive
- Show promo-code field
- Enable free-delivery threshold
- Enable Meta tracking

A toggle must include:

- Text label
- Current state text where consequence is important
- Confirmation for critical settings

## 16.7 Currency field

- Input millime-converted display safely
- Show `DT` suffix
- Accept localized decimal input at UI boundary
- Convert to integer millimes before API submission
- Never use floating-point values as authority
- Display validation immediately and server-side

## 16.8 Phone field

- Use `inputmode="tel"`
- No restrictive visual mask that prevents valid Tunisian formats
- Explain expected format
- Server remains authoritative

## 16.9 Search

- Search icon
- Clear button
- Visible label for screen reader
- Debounced suggestions
- Escape closes suggestions
- Arrow keys navigate results

## 16.10 File upload

Use:

- Drop zone plus normal file button
- File type and size instruction
- Preview
- Remove/replace
- Processing state
- Error state
- Keyboard support

Do not make drag-and-drop the only option.

## 16.11 Rich-text editor

Admin product and static-page content editor:

- Minimal toolbar
- Approved tags only
- Heading, paragraph, bold, italic, lists, link
- No arbitrary color/font controls
- No iframe/embed
- Preview sanitized result
- Clear paste formatting

## 16.12 Form actions

Desktop:

- Primary action right
- Cancel/secondary left or adjacent

Mobile:

- Full-width primary
- Secondary below or above depending on risk
- Sticky bottom action only where it improves long forms and does not cover content

## 16.13 Error summary

Long forms should provide a top error summary:

> Veuillez corriger les champs indiqués.

Selecting an item moves focus to the field.

---

# 17. Tables and Data-Dense Components

## 17.1 Table principles

Back-office tables must be:

- Scannable
- Keyboard accessible
- Responsive
- Filterable
- Paginated
- Stable in layout

## 17.2 Table style

- Paper surface
- Subtle horizontal dividers
- Muted header background or no background
- DM Mono for references/timestamps
- Manrope for content
- Row hover only as a subtle cream highlight
- No zebra stripes unless later testing shows a clear advantage

## 17.3 Column priority

On smaller screens:

- Hide low-priority columns
- Provide a row details panel
- Do not create unreadable horizontal compression
- Horizontal scrolling may be used for complex operational tables, with sticky primary column where practical

## 17.4 Row actions

Use:

- One visible primary action where clear
- More menu for secondary actions
- Text labels inside menu
- Destructive action separated

## 17.5 Bulk actions

Only implement when the business rule supports safe bulk behavior.

Do not add bulk order status changes in v1 unless explicitly approved because stock and Meta rules require per-order integrity.

## 17.6 Sorting

- Visible indicator
- Keyboard accessible
- Active sort announced
- Only approved sort fields

---

# 18. Dialogs, Drawers, and Panels

## 18.1 Dialog

Use for:

- Final destructive confirmation
- Critical Meta setting confirmation
- Password confirmation
- Small focused edit

Do not put large product forms in a modal.

## 18.2 Drawer

Use for:

- Mobile navigation
- Mobile cart
- Responsive filter panel
- Optional admin row details on tablet/mobile

## 18.3 Critical dialog

Must contain:

- Clear title
- Consequence
- Affected resource
- Old and new value when relevant
- Password confirmation status
- Typed phrase field when required
- Safe cancel as default focus
- Danger-styled final action

## 18.4 Unsaved changes

Long admin forms should warn before navigation when changes are unsaved.

Do not show the warning after a successful save.

---

# 19. Loading, Empty, Error, and Success States

## 19.1 Skeletons

Use skeletons for:

- Product grid
- Product detail gallery
- Admin table
- Dashboard metric cards

Skeletons should resemble final layout and avoid excessive shimmer.

Respect reduced motion.

## 19.2 Empty states

Empty state contains:

- Simple icon or illustration
- Clear title
- Short explanation
- One useful action

Examples:

### No products

> Aucun produit pour le moment.

### No orders

> Aucune commande ne correspond à ces filtres.

### No complaints

> Aucune réclamation récente.

## 19.3 Errors

Use safe, actionable French messages.

Examples:

- `Impossible de charger les produits. Réessayez.`
- `Cette commande a été modifiée par un autre utilisateur. Rechargez les informations.`
- `La quantité demandée n’est plus disponible.`

## 19.4 Success

Use persistent confirmation for high-value actions.

Examples:

- Order confirmed page
- Meta configuration activated
- Password reset complete

## 19.5 Offline/network state

- Do not claim save success without server response.
- Preserve unsaved form data where safe.
- Provide retry.
- Do not automatically repeat non-idempotent requests without an idempotency mechanism.

---

# 20. Public Storefront Information Architecture

Recommended primary navigation:

- `Accueil`
- `Boutique`
- Dynamic category or editorial link such as `Nos rituels`

Utility:

- Search
- Cart
- Privacy preferences through footer
- Complaint page through footer or support navigation

Do not overload the main header with every static page.

---

# 21. Announcement Bar

## 21.1 Visual

- Accent pink background
- Ink text
- DM Mono
- 11–12 px
- Height approximately 28–34 px
- Optional small sparkle icon
- Centered or horizontally scrolling only if content cannot fit; avoid marquee by default

## 21.2 Content

Content is managed by the Super Admin.

Examples:

- Dynamic free-delivery message
- Brand promise
- Temporary promotion

Only one primary message should be shown at a time on mobile.

## 21.3 Dynamic shipping text

When free shipping is enabled, content may use:

```text
Livraison offerte dès {configured_threshold}
```

When disabled, use another approved statement.

---

# 22. Storefront Header

## 22.1 Desktop

Structure:

- Brand logo
- Primary navigation
- Search
- Cart with quantity badge

Optional:

- Sticky after initial scroll
- Thin bottom border when sticky

## 22.2 Logo

- Compact bordered wordmark lockup
- Ink on paper
- Do not shrink below readable size
- Links to homepage

## 22.3 Active navigation

- Underline or small accent line
- Do not use a large pink pill for every active item

## 22.4 Mobile

Structure:

- Menu button
- Centered or left brand logo
- Search shortcut
- Cart

Minimum target:

```text
44 × 44 px
```

---

# 23. Mobile Navigation Drawer

Contains:

- Logo
- Close
- Search field
- Main navigation
- Active categories
- Complaint/help link
- Short brand statement
- Privacy preferences link

Behavior:

- Slides from right
- Backdrop
- Locks background scroll
- Focus trap
- Escape closes
- Click backdrop closes
- Restores trigger focus
- Close animation completes before unmount where practical

---

# 24. Homepage

## 24.1 Page sequence

Recommended:

1. Announcement bar
2. Header
3. Hero
4. Category explorer
5. New products
6. Ritual Finder
7. Editorial feature
8. Custom product sections
9. All products or catalogue CTA
10. Footer

The Super Admin may reorder managed content sections, but the design should preserve a clear narrative.

## 24.2 Hero

### Desktop

Two columns:

- Copy approximately 45%
- Image approximately 55%

Copy:

- Eyebrow
- Large mixed-font statement
- Supporting paragraph
- Primary CTA

Image:

- Face-free still life
- Angled or clipped left edge
- Circular accent-pink stamp
- Preserve LCP performance

### Mobile

Recommended order:

1. Image
2. Stamp positioned without covering product
3. Copy
4. Full-width or near-full-width CTA

### Hero content example

Eyebrow:

```text
RITUELS DE SAISON
```

Heading:

```text
Le soin devient
un moment pour soi.
```

CTA:

```text
Découvrir la collection
```

## 24.3 Hero stamp

- Circular
- Accent pink
- Ink text
- DM Mono or compact Manrope
- May contain `Soin de soi`
- Decorative rotation maximum 8–12 degrees
- Must not cover essential product details

---

# 25. Category Explorer

## 25.1 Card

Contains:

- Category image
- Title
- Optional short description
- Arrow

## 25.2 Desktop

- Three or four columns depending on configured categories
- Large image-led cards
- Minimal outer chrome

## 25.3 Mobile

- Horizontal scroller
- Card width approximately 72–82vw
- Snap points
- Visible portion of next card to signal scroll
- Accessible scroll controls optional

## 25.4 Hover

- 4 px lift
- 1.03 image scale
- Arrow shift 2–4 px
- No heavy shadow

---

# 26. Product Card

## 26.1 Content

- Product image
- Optional promotion badge
- Optional product tag
- Quick add or add icon
- Product name
- Optional size/volume metadata
- Current price
- Old price when promotional
- Optional out-of-stock label

## 26.2 Image area

- 4:5
- Cream or product-specific background
- Overflow hidden
- Variant-specific preview is not required on card
- Responsive image
- Width/height attributes

## 26.3 Quick add

### Product without variants

Circular add button may add directly.

### Product with variants

Quick add must not silently choose a variant.

It should:

- Open a compact variant-selection drawer/dialog, or
- Navigate to product detail

Recommended v1:

```text
Navigate to product detail for multi-variant products.
```

## 26.4 Card layout

Avoid a fully bordered rounded card.

Use:

- Image frame
- Text below
- Comfortable vertical rhythm

## 26.5 Promotion badge

Example:

```text
-20%
```

- Accent pink or ink
- High contrast
- Compact
- Calculated by server

---

# 27. Ritual Finder — “Le moment Passion”

## 27.1 Purpose

A premium editorial discovery feature, not a medical or diagnostic quiz.

## 27.2 Prompt

```text
Comment voulez-vous vous sentir aujourd’hui ?
```

Choices:

- `Je veux ralentir`
- `Je veux rayonner`
- `Je veux m’évader`

The final choices should be managed as design content or static approved configuration.

## 27.3 Layout

Desktop:

- Ink panel
- Choice column
- Recommendation image
- Recommendation copy

Mobile:

- Choices as horizontal or wrapped pills
- Image
- Title
- Description
- CTA

## 27.4 Recommendation

Contains:

- Face-free image
- Ritual title
- Short description
- CTA `Voir le rituel`

## 27.5 Functional rule

This feature should link to:

- A custom product section
- A category
- A product
- A curated landing page

Do not make unsupported product claims.

## 27.6 Accessibility

- Choices use buttons or radio group
- Current selection announced
- Recommendation update uses polite live region
- Focus does not jump unexpectedly

---

# 28. Editorial Feature Block

Two-column section:

- Image
- Editorial copy
- Outline CTA

Surface:

- Pale pink or cream
- Do not use a gradient
- May reverse image/copy order on alternating sections

Mobile:

- Image first
- Copy second
- Full-width CTA when appropriate

---

# 29. Product Listing and Category Page

## 29.1 Structure

- Breadcrumb
- Eyebrow
- Category heading
- Optional description
- Optional wide category image
- Product count
- Sort
- Filters
- Product grid
- Pagination

## 29.2 Filters

Initial filters:

- Category where relevant
- Price range
- Promotional products only

Mobile:

- Filter button opens bottom sheet or side drawer
- Active-filter count
- `Appliquer`
- `Réinitialiser`

Desktop:

- Inline top filter bar or restrained sidebar
- Prefer top bar for the small initial filter set

## 29.3 Sort

Options:

- Nouveautés
- Prix croissant
- Prix décroissant
- Nom

## 29.4 Empty result

> Aucun produit ne correspond à vos critères.

Actions:

- Reset filters
- Return to shop

## 29.5 Pagination

Use real crawlable URLs.

Do not implement infinite scroll as the only navigation.

A `Charger plus` enhancement may be added only if URL/history and accessibility remain correct.

---

# 30. Global Search

## 30.1 Desktop

Search opens:

- Expanded field in header, or
- Search overlay below header

Shows:

- Product suggestions
- Category suggestions

## 30.2 Mobile

Search is available in:

- Header shortcut
- Menu drawer

## 30.3 Suggestion item

Product:

- Thumbnail
- Name
- Price

Category:

- Category icon/image optional
- Name
- Label `Catégorie`

## 30.4 Behavior

- Minimum two characters
- Debounce
- Loading state
- Empty state
- Keyboard navigation
- Escape closes
- Enter opens full results

---

# 31. Product Detail Page

## 31.1 Desktop

Two-column layout:

- Left: gallery 55–60%
- Right: purchase information 40–45%
- Purchase column may become sticky within safe bounds

## 31.2 Mobile

Order:

1. Breadcrumb
2. Gallery
3. Category eyebrow
4. Product name
5. Size/metadata
6. Price
7. Short description
8. Variant selection
9. Quantity
10. Add to cart
11. Reassurance
12. Full description
13. Related products

## 31.3 Gallery

Desktop:

- Vertical thumbnails
- Large main image
- Optional zoom
- Variant image becomes main image

Mobile:

- Horizontal swipe gallery
- Dots or thumbnail strip
- Pinch zoom only if implemented accessibly and without excessive complexity

## 31.4 Product information

Contains:

- Category eyebrow
- Product name
- Optional size
- Price
- Old price
- Promotion percentage
- Short description
- Stock state
- Variant groups
- Quantity
- CTA

No star rating.

## 31.5 Variants

Use pill buttons or swatches depending on data.

Text values:

- Clear label
- Selected indicator
- Disabled unavailable values
- Group label

Color swatches may be used only if a reliable color value is stored; text label remains available.

## 31.6 Quantity

- Minus
- Current quantity
- Plus
- Minimum 1
- Maximum based on available stock and business cap
- 44 px controls

## 31.7 Add-to-cart CTA

- Full width
- Ink
- At least 50 px high
- Clear loading state
- Disabled until all required variants selected
- Error message near variants when selection missing

## 31.8 Reassurance row

Use approved dynamic statements.

Example initial set:

- `Paiement à la livraison`
- `Commande confirmée par téléphone`
- `Livraison partout en Tunisie`

Icons remain simple.

## 31.9 Description

Use editorial typography but preserve readability.

Sanitized HTML only.

## 31.10 Related products

- Maximum four desktop
- Horizontal scroller mobile
- Same product-card component

---

# 32. Cart Experience

## 32.1 Cart access

Support:

- Cart page
- Optional cart drawer after add

The cart page remains the authoritative complete view.

## 32.2 Cart item

Contains:

- Thumbnail
- Product name
- Variant label
- Price
- Quantity stepper
- Line total
- Remove

## 32.3 Unavailable item

Show persistent message:

> Ce produit n’est plus disponible et a été retiré de votre panier.

## 32.4 Stock adjustment

Example:

> La quantité demandée n’est plus disponible. Votre panier a été mis à jour avec le stock disponible.

## 32.5 Desktop

Two columns:

- Items
- Sticky summary panel

## 32.6 Mobile

- Items
- Summary below
- Sticky checkout bar may be used if it does not duplicate or obscure the summary

## 32.7 Summary

Pale pink panel:

- Subtotal
- Product discount
- Promo-code discount
- Delivery
- Total
- Checkout CTA
- COD note
- Free-delivery progress when enabled

## 32.8 Free-delivery progress

Optional:

> Plus que 24 DT pour profiter de la livraison offerte.

Calculated by server quote.

Do not pressure the user with flashing or aggressive styling.

---

# 33. Checkout Page

## 33.1 Structure

Desktop:

- Form approximately 60%
- Order summary approximately 40%

Mobile:

1. Contact and delivery form
2. Optional custom fields
3. Promo code if enabled
4. Order summary
5. Final submit

## 33.2 Heading

```text
Finaliser ma commande
```

Supporting text:

```text
Paiement à la livraison.
```

## 33.3 Default fields

- Nom et prénom
- Téléphone
- Ville
- Adresse

City remains free text.

## 33.4 Custom fields

Rendered according to configured type and order.

Required state must be visible.

## 33.5 Promo code

Only shown when enabled.

Use:

- Input
- `Appliquer`
- Applied state
- Remove action
- Generic invalid message

## 33.6 Summary

Display:

- Products
- Variants
- Quantities
- Subtotal
- Product discounts
- Promo-code discount
- Delivery
- Total
- COD

## 33.7 Submit button

Recommended:

```text
Confirmer la commande
```

During submission:

```text
Confirmation en cours…
```

## 33.8 Duplicate prevention

- Disable while submitting
- Preserve idempotency key
- Do not clear form before success
- On uncertain network failure, safely retry using same key

## 33.9 Privacy

Near submit:

- Concise personal-data notice
- Link to privacy policy
- Required acknowledgement only where legally appropriate
- Consent controls for Meta remain separate from order necessity

## 33.10 Errors

- Field errors inline
- Summary at top
- Stock or price change shown in persistent alert
- Updated total clearly highlighted before resubmission if needed

---

# 34. Order Confirmation Page

## 34.1 Structure

- Pink circular check icon
- Eyebrow `COMMANDE CONFIRMÉE`
- Thank-you heading with optional italic phrase
- Confirmation message
- Order reference
- Delivery information
- Products
- Pricing
- Payment method
- Contact expectation
- Primary return-home CTA
- Secondary continue-shopping CTA where desired

## 34.2 Recommended copy

Heading:

```text
Merci,
votre rituel est en route.
```

Body:

```text
Votre commande a bien été enregistrée. Notre équipe pourra vous contacter pour la confirmer.
```

## 34.3 Security

- Signed expiring URL
- `noindex`
- No internal IDs
- Do not expose attribution data
- Refresh does not create another order or Meta logical event

---

# 35. Complaint Page

## 35.1 Tone

Calm, respectful, and practical.

Do not style it like a marketing section.

## 35.2 Structure

- Breadcrumb
- Heading
- Short explanation
- Form
- Contact alternative
- Privacy note

## 35.3 Fields

- Nom et prénom
- Téléphone
- Référence de commande optional
- Sujet
- Description
- Optional image
- Consent acknowledgement

## 35.4 Success

Dedicated inline success panel or page:

> Votre réclamation a été envoyée. Notre équipe l’examinera dans les meilleurs délais.

Do not promise an unsupported exact response time.

---

# 36. Static Pages

Pages:

- À propos
- Contact
- Conditions générales
- Confidentialité
- Livraison
- Retours et réclamations
- FAQ

Use:

- Narrow reading container
- Strong heading hierarchy
- Table of contents for long legal pages where helpful
- Last updated date
- No decorative overload
- Accessible links

---

# 37. Footer

## 37.1 Visual

- Ink background
- Paper text
- Muted-light supporting text
- Generous spacing
- Fine divider
- Quiet premium ending

## 37.2 Content

Recommended columns:

- Brand statement
- Boutique/categories
- Aide and policies
- Contact/social

Bottom row:

- Copyright
- Privacy preferences
- Legal links

## 37.3 Newsletter placeholder

Until newsletter is approved, use:

Eyebrow:

```text
L’UNIVERS PASSION
```

Heading:

```text
Des soins choisis pour ralentir, rayonner et s’évader.
```

Supporting content:

- Instagram link
- WhatsApp link
- Contact

Do not render a non-functional email form.

---

# 38. Back-Office Design Direction

## 38.1 Personality

The back office should feel:

- Professional
- Calm
- Efficient
- Consistent with the brand
- Less editorial than the storefront
- More compact
- High contrast
- Easy to scan for long sessions

Use the same:

- Manrope
- DM Mono
- Ink
- Paper
- Cream
- Accent pink

Use Playfair very rarely, mainly on login or an empty welcome state.

## 38.2 Back-office color behavior

- Paper main surface
- Cream application background
- Ink sidebar
- Pink accent for active state
- Semantic colors for statuses
- Avoid promotional pink panels in operational tables

## 38.3 Layout

Desktop:

- Left sidebar
- Top bar
- Main content
- Optional right detail panel only where useful

Tablet:

- Collapsible sidebar
- Main content

Mobile:

- Drawer navigation
- Stacked content
- Responsive tables/cards

---

# 39. Back-Office Navigation

## 39.1 Super Admin sections

Recommended order:

1. Tableau de bord
2. Commandes
3. Produits
4. Catégories
5. Stock
6. Réclamations
7. Promotions
8. Contenu du site
9. Champs de commande
10. Livraison
11. Suivi Meta
12. Utilisateurs
13. Journal d’audit
14. Paramètres

## 39.2 Admin sections

1. Tableau de bord
2. Commandes
3. Produits
4. Catégories
5. Stock
6. Réclamations

Admin must not see inaccessible sections.

## 39.3 Sidebar

- Ink background
- Paper text
- Active item with pale pink or accent-pink marker
- Icons plus labels
- Clear section groups
- Bottom area for user/profile/logout
- Avoid excessive nested menus
- Collapsed desktop state optional

## 39.4 Top bar

Contains:

- Mobile/sidebar trigger
- Page title or breadcrumb
- Search where contextually useful
- Notifications only if implemented
- Current user menu

Do not create a notification bell without a real notification system.

---

# 40. Admin Login

## 40.1 Layout

Desktop:

- Split screen optional
- Calm still-life image or ink editorial panel
- Compact login form

Mobile:

- Form first
- No heavy image that delays login

## 40.2 Content

- Logo
- Heading `Connexion à l’administration`
- Email
- Password
- Show/hide password
- Submit
- Security/support note

Do not show public registration.

## 40.3 Error

Use generic:

> Identifiants incorrects.

Do not reveal account existence.

---

# 41. Admin Dashboard

## 41.1 Header

- Page title
- Date filter
- Optional refresh
- No decorative hero

## 41.2 Metric cards

Display:

- Nouvelles
- Confirmées
- Livrées
- Annulées
- Échec de livraison
- Retournées
- Chiffre d’affaires livré

Card design:

- Paper
- Border
- Minimal shadow
- Status icon
- Large number
- Small label
- Optional comparison only when correctly calculated

## 41.3 Secondary sections

- Best-selling products
- Low-stock items
- Recent complaints
- Meta summary
- Recent orders

## 41.4 Charts

Charts are optional.

When used:

- Keep simple
- Use accessible labels
- Do not rely on color alone
- Avoid 3D, gradients, and decorative animation
- Provide table or summary alternative

---

# 42. Admin Product List

## 42.1 Header

- Title
- Product count
- `Ajouter un produit`

## 42.2 Filters

- Search
- Category
- Active status
- Variant status
- Stock state
- Promotion
- Sort

## 42.3 Table columns

Recommended:

- Image
- Product
- Category
- Price
- Stock
- Status
- Updated
- Actions

## 42.4 Stock display

- Exact quantity in admin
- Low-stock badge
- Out-of-stock danger state
- Variant product may show aggregate plus `Voir les variantes`

## 42.5 Mobile

Use product row cards or responsive table with:

- Image
- Name
- Status
- Price
- Stock
- Main action

---

# 43. Product Create/Edit

## 43.1 Page structure

Use sections, not one giant unstructured form:

1. Informations générales
2. Prix
3. Images
4. Variantes
5. Stock
6. Référencement
7. Publication

Desktop may use:

- Main form
- Right sticky publication panel

## 43.2 General information

- Name
- Category
- Slug
- Short description
- Full description

## 43.3 Price

- Regular price
- Promotional price
- Calculated discount preview

## 43.4 Images

- Gallery upload
- Reorder
- Primary image
- Alt text
- Variant assignment

## 43.5 Variants

- Enable variants protected flow
- Groups
- Values
- Generated/accepted combinations
- SKU optional
- Stock
- Low-stock threshold
- Active state
- Image assignment

The combination editor must remain understandable and avoid a dense spreadsheet on mobile.

## 43.6 SEO

- SEO title
- SEO description
- Search preview
- Character guidance
- Fallback explanation

## 43.7 Save behavior

Actions:

- Enregistrer
- Enregistrer et activer
- Désactiver where applicable
- Cancel

Do not auto-publish a new incomplete product.

---

# 44. Category Management

## 44.1 List

- Reorder handle
- Name
- Product count
- Active state
- SEO status
- Actions

## 44.2 Edit

- Name
- Slug
- Status
- Sort order
- SEO fields

## 44.3 Delete conflict

When category is in use, its destructive confirmation states that the category and its catalog products will be removed together, while past order history remains intact. Offer a direct link to the related products before confirmation.

---

# 45. Order List

## 45.1 Priority

Orders are the primary operational screen.

## 45.2 Filters

- Search
- Status
- Date
- Total range
- Promo code
- Complaint association
- Meta state for Super Admin

## 45.3 Columns

- Reference
- Customer
- Phone
- City
- Total
- Status
- Date
- Action

## 45.4 Status colors

Use semantic mapping:

- Nouvelle: info
- Confirmée: accent dark or neutral positive
- Livrée: success
- Annulée: muted/danger
- Échec de livraison: warning/danger
- Retournée: warning

Always include text.

## 45.5 Row action

Primary:

```text
Voir la commande
```

---

# 46. Order Detail

## 46.1 Header

- Order reference in DM Mono
- Status
- Created date
- Allowed actions

## 46.2 Sections

1. Customer
2. Delivery
3. Products
4. Pricing
5. Status history
6. Internal notes
7. Complaints
8. Meta summary for Super Admin

## 46.3 Editing

When editable:

- `Modifier la commande`
- Opens page section or dedicated edit route
- Shows stock and total recalculation warning
- Requires lock-version conflict handling

## 46.4 Transition actions

Show only allowed transitions.

Examples:

- Confirmer
- Annuler
- Marquer comme livrée
- Échec de livraison
- Enregistrer un retour

Each exception transition requests a reason.

Return asks:

```text
Remettre les articles en stock ?
```

## 46.5 Timeline

Vertical timeline:

- Status
- Date/time
- Actor
- Reason

## 46.6 Conflict

When another user changed order:

> Cette commande a été modifiée. Rechargez les informations avant de continuer.

---

# 47. Complaints Back Office

## 47.1 List

- Reference
- Customer
- Order reference
- Subject
- Status
- Date
- Attachment indicator

## 47.2 Detail

- Customer details
- Complaint text
- Private image preview/download
- Linked order
- Internal notes
- Timeline
- Status actions

Complaint text must be escaped.

---

# 48. Promotions

Super Admin only.

## 48.1 List

- Code
- Percentage
- Usage
- Limit
- Status
- Date range
- Minimum subtotal

## 48.2 Create/edit

- Code
- Percentage
- Usage limit
- Minimum subtotal optional
- Start/end optional
- Active

Show:

```text
46 utilisations sur 200
```

Usage count is read-only.

## 48.3 Exhausted

Clear warning:

> Limite d’utilisation atteinte.

---

# 49. Checkout Fields Management

Super Admin only.

## 49.1 List/reorder

- Drag handle
- Label
- Type
- Required
- Active
- System/custom
- Actions

## 49.2 System fields

Default fields:

- Full name
- Phone
- City
- Address

Show lock icon for protected key/type behavior.

## 49.3 Custom field form

- Machine key
- French label
- Type
- Options
- Required
- Active
- Order

Provide live checkout preview.

---

# 50. Content Management

Super Admin only.

Sections:

- Homepage sections
- Banners
- Announcement
- Footer/contact
- Static pages
- Redirects

## 50.1 Homepage section builder

Use a list with:

- Type
- Title
- Active
- Filters enabled
- Product count
- Reorder
- Preview

Avoid a free-form page builder.

## 50.2 Banner manager

- Desktop image
- Mobile image
- Internal label
- Link
- Status
- Reorder

## 50.3 Preview

Preview should open a safe public preview route or new tab.

Do not render unsanitized content in admin.

---

# 51. Shipping Settings

Super Admin only.

Use a compact settings card:

- Fixed delivery fee
- Enable free-delivery threshold
- Threshold
- Dynamic preview of announcement and checkout calculation

Example preview:

```text
Livraison offerte dès 120 DT
```

Values come from form state but final server validation applies.

---

# 52. User Management

Super Admin only.

## 52.1 List

- Name
- Email
- Role
- Active
- Last login
- Actions

## 52.2 Create/edit

- Name
- Email
- Role
- Active
- Temporary password
- Force password change

## 52.3 Protected actions

Role/status changes may require recent password confirmation.

Final Super Admin protection errors must be clear.

## 52.4 Password reset

Critical dialog:

- Affected user
- New temporary password
- Confirmation
- Force change
- Warning that current sessions are revoked

Never show old password.

---

# 53. Meta Configuration

Super Admin only.

## 53.1 Tone

This page must feel technical and serious, not promotional.

## 53.2 Sections

1. Tracking status
2. Pixel ID
3. CAPI token
4. Test mode
5. Purchase trigger
6. Last test
7. Diagnostics summary

## 53.3 Token

Display:

```text
Configuré •••• A7F2
```

Never display full token.

## 53.4 Test flow

- Enter proposed values
- `Tester la connexion`
- Persistent result
- Activate only after successful test

## 53.5 Trigger change

Use a dedicated critical card.

Display:

- Current trigger
- New trigger
- Future-order-only explanation
- Warning
- Password confirmation
- Typed `CONFIRMER`
- Final dialog

## 53.6 Event diagnostics

Table:

- Event ID
- Order
- Status
- Attempts
- Created
- Last error
- Retry action where permitted

No raw customer payload.

---

# 54. Audit Log

Super Admin read-only.

## 54.1 Filters

- User
- Role
- Action
- Resource
- Date

## 54.2 Table

- Time
- Actor
- Action
- Resource
- Request ID
- Details

## 54.3 Detail

Show safe before/after diff.

Redact:

- Password
- Token
- Secret
- Unnecessary PII

No edit or delete controls.

---

# 55. Admin Settings Layout

Settings should use a clear index page with categorized cards:

- Boutique
- Livraison
- Commande
- Meta
- Utilisateurs
- Sécurité
- Contenu

Only show categories permitted by role.

Do not create one page with every system setting.

---

# 56. Back-Office Responsive Behavior

## 56.1 Desktop ≥ 1280 px

- Fixed sidebar 240–272 px
- Main content fluid
- Dense tables
- Side-by-side forms where appropriate

## 56.2 Tablet 768–1279 px

- Collapsible sidebar
- Fewer visible table columns
- Two-column dashboard
- Filter drawer when needed

## 56.3 Mobile < 768 px

- Navigation drawer
- One-column forms
- Full-width buttons
- Table-to-card adaptation
- Sticky page action bar only where safe
- No tiny icon-only action menus without labels
- Minimum 44 px controls

The back office must remain usable on mobile, but desktop is the primary environment for complex catalogue management.

---

# 57. Accessibility Requirements

## 57.1 Target

- WCAG 2.1 AA minimum
- WCAG 2.2 AA where applicable

## 57.2 Mandatory

- Keyboard navigation
- Visible focus
- Semantic landmarks
- Correct headings
- Accessible labels
- Alt text
- Error summary
- Screen-reader status messages
- Color contrast
- Touch targets
- Focus trap in dialogs/drawers
- Focus restoration
- Reduced motion
- No autoplay video
- No flashing content
- No drag-only functionality

## 57.3 Drag reorder fallback

Category, image, and section reorder controls must also support:

- Move up
- Move down

or keyboard-accessible drag behavior.

## 57.4 Contrast

Verify all token combinations.

Particular risk areas:

- Muted text on cream
- Accent pink with paper text
- Pale pink selected controls
- Disabled controls
- Footer links

---

# 58. French Copy Style

## 58.1 Tone

French interface copy should be:

- Clear
- Warm
- Direct
- Respectful
- Not overly poetic in operational flows
- Not overly technical for customers

## 58.2 Storefront

Editorial language may be emotional, but commerce actions remain concrete.

Good:

- `Ajouter au panier`
- `Finaliser ma commande`
- `Paiement à la livraison`
- `Voir le rituel`

Avoid:

- Vague CTA such as `Explorer` when the action is checkout
- Excessive English
- Artificial luxury clichés

## 58.3 Back office

Use operational language:

- `Enregistrer`
- `Confirmer`
- `Désactiver`
- `Réessayer`
- `Modifier la commande`

Do not use poetic labels for admin actions.

## 58.4 Capitalization

Use sentence case for:

- Buttons
- Page titles
- Form labels

DM Mono eyebrows may use uppercase.

---

# 59. Performance Rules

## 59.1 Storefront

- Blade SSR
- Minimal Vue hydration
- Responsive images
- No large animation library unless justified
- Prefer CSS transitions
- Hero image optimized as LCP
- Product images lazy-loaded below fold
- Avoid loading admin CSS/JS publicly
- Avoid loading Ritual Finder assets before near viewport if heavy

## 59.2 Fonts

- Self-host
- Subset if practical
- Preload only critical
- Avoid layout shift

## 59.3 Icon library

Tree-shake icons.

Do not ship the entire icon package.

## 59.4 Admin

- Route code splitting
- Virtualization only for genuinely large lists
- Server pagination
- Debounced filters
- Cancel stale requests

## 59.5 Motion

No JavaScript scroll animation library for basic reveal effects.

Intersection Observer plus CSS is sufficient.

---

# 60. SEO Design Rules

- Public text is rendered in HTML.
- Product/category heading hierarchy is semantic.
- Product cards are real links.
- Pagination is crawlable.
- Buttons are not used for navigation.
- Breadcrumb is visible and structured.
- Decorative imagery has empty alt.
- Product imagery has meaningful alt.
- Confirmation, cart, checkout, admin, and internal search are noindex according to SEO rules.
- Slug redirects preserve user experience.

---

# 61. Privacy and Security UI Rules

## 61.1 Consent banner

Must provide:

- `Tout accepter`
- `Tout refuser`
- `Personnaliser`

Do not use:

- Preselected advertising consent
- Misleading button hierarchy
- Hidden refusal
- Blocking the entire website unnecessarily

## 61.2 Sensitive fields

- Password reveal is user-controlled
- Meta token masked
- No customer PII in URLs
- Private file downloads require admin action
- Clipboard copy for token is not provided because full token is never displayed

## 61.3 Critical settings

Use explicit warning and confirmation.

Do not allow one-click trigger changes.

## 61.4 Session expiration

Admin session expiration dialog:

> Votre session a expiré. Reconnectez-vous pour continuer.

Preserve unsaved non-secret form data when safe.

---

# 62. Component Naming and Implementation

Recommended component names:

## Storefront

```text
AnnouncementBar
StoreHeader
MobileNavigationDrawer
GlobalSearch
HeroSection
CategoryExplorer
ProductCard
ProductGrid
RitualFinder
EditorialFeature
ProductGallery
VariantSelector
QuantityStepper
CartDrawer
CartItem
OrderSummary
CheckoutForm
ConsentManager
StoreFooter
```

## Admin

```text
AdminShell
AdminSidebar
AdminTopbar
PageHeader
FilterBar
DataTable
StatusBadge
MetricCard
FormField
MoneyInput
ImageUploader
RichTextEditor
ConfirmDialog
CriticalActionDialog
EmptyState
ErrorState
LoadingSkeleton
OrderTimeline
AuditDiff
MetaEventStatus
```

Components must use tokens rather than local hard-coded values.

---

# 63. Design Token Implementation Rules

Tokens should cover:

- Colors
- Fonts
- Type sizes
- Spacing
- Radius
- Borders
- Shadows
- Durations
- Easings
- Container widths
- Z-index

Example z-index scale:

```css
:root {
  --z-base: 0;
  --z-sticky: 20;
  --z-header: 30;
  --z-dropdown: 50;
  --z-drawer: 70;
  --z-dialog: 90;
  --z-toast: 100;
}
```

Do not use arbitrary values such as `z-index: 999999`.

---

# 64. Browser and Device Validation

Test at:

```text
360 × 800
390 × 844
768 × 1024
1024 × 768
1280 × 800
1440 × 900
```

Test:

- Mobile Chrome
- Mobile Safari
- Desktop Chrome
- Desktop Firefox
- Desktop Safari where available
- Edge

Critical pages:

- Homepage
- Category
- Product detail
- Cart
- Checkout
- Confirmation
- Complaint
- Admin login
- Dashboard
- Product form
- Order detail
- Meta settings

---

# 65. Visual QA Checklist

## Brand

- [ ] Warm paper/cream foundation
- [ ] Ink contrast
- [ ] Pink used selectively
- [ ] No green/olive palette
- [ ] Face-free imagery
- [ ] Editorial, not decorative overload

## Typography

- [ ] Manrope interface
- [ ] Playfair only for editorial accent
- [ ] DM Mono only for metadata
- [ ] Mobile body text readable
- [ ] No missing font flash that harms layout

## Layout

- [ ] Mobile two-column product grid
- [ ] Desktop four-column product grid
- [ ] Horizontal mobile category scroller
- [ ] Product detail responsive
- [ ] Admin mobile usable
- [ ] No unintended overflow

## Interaction

- [ ] Focus visible
- [ ] Touch targets
- [ ] Loading states
- [ ] Empty states
- [ ] Error states
- [ ] Reduced motion
- [ ] Drawer focus trap
- [ ] Dialog focus trap

## Commerce

- [ ] Variant required before add
- [ ] Product with variants does not quick-add incorrectly
- [ ] Server totals visible
- [ ] Stock updates communicated
- [ ] Confirmation page complete
- [ ] Dynamic shipping text

## Back office

- [ ] Role-based navigation
- [ ] Dense but readable tables
- [ ] Critical actions protected
- [ ] Meta token masked
- [ ] Audit read-only
- [ ] Long forms structured into sections

---

# 66. Design Anti-Patterns

Do not:

- Use pink for every background
- Use human faces as the primary image system
- Use glossy gradients
- Use glassmorphism
- Use excessive rounded cards
- Put every block in a shadowed card
- Use green or olive as brand colors
- Add competing CTAs
- Use fake reviews
- Hard-code delivery promises
- Add a non-functional newsletter form
- Use bouncy animation
- Animate layout properties
- Hide labels in placeholders
- Use low-contrast muted text
- Create hover-only functionality
- Use icon-only destructive actions without labels
- Use client-side-only storefront routing
- Use a large frontend framework for static Blade content
- Create admin marketing-style hero sections
- Put sensitive configuration in casual toggles
- Make every admin status pink
- Add unsupported notifications or live features

---

# 67. Codex Design Instructions

Before implementing a page, Codex must:

1. Read this file.
2. Identify storefront or back-office context.
3. Reuse existing tokens and components.
4. Preserve SSR for public content.
5. Add Vue only when interaction requires it.
6. Implement all states.
7. Implement responsive behavior.
8. Implement keyboard behavior.
9. Respect motion rules.
10. Respect performance budgets.
11. Use French interface copy.
12. Avoid invented business features.
13. Add visual or browser tests for critical flows.
14. Report any conflict with security, privacy, or API contracts.

Codex must not:

- Copy a generic beauty template wholesale
- Hard-code settings that come from the backend
- Add ratings
- Add newsletter collection
- Add customer accounts
- Add online payment UI
- Add arbitrary animations
- Add colors outside the token system without approval
- Use `v-html` for unsanitized content
- Render public pages only through Vue
- Treat localStorage totals as authoritative
- Expose inaccessible admin sections
- Skip accessibility because the design is “premium”

---

# 68. Definition of Design Completion

A page or component is design-complete only when:

- Default state is implemented
- Responsive states are implemented
- Loading state exists
- Empty state exists where applicable
- Error state exists
- Focus and keyboard behavior work
- Reduced motion works
- French copy is final
- Tokens are used
- No prohibited pattern exists
- Performance budget is respected
- Security/privacy requirements are reflected
- Visual QA has been performed
- Browser screenshots or tests are attached to the pull request

---

# 69. Final Design Summary

ToutDispo combines:

- Warm white and cream surfaces
- Deep ink contrast
- Selective pale and accent pink
- Manrope-led interface typography
- Playfair editorial accents
- DM Mono metadata
- Product-first face-free imagery
- Restrained geometry
- Subtle transitions
- Mobile-first commerce
- Calm, practical back-office operations

The storefront should feel like a premium editorial beauty boutique.

The back office should feel like the same brand translated into a clear, secure, efficient operational tool.

---

# 70. Back-Office UI Conventions

These rules apply to every current and future admin screen.

## Navigation and layout

- Desktop uses a full-height, visually continuous sidebar with bottom-aligned account actions.
- Mobile uses a sticky two-row navigation bar. All four section labels remain visible; it must not require horizontal scrolling.
- Main content and side panels must never inherit sidebar layout styles. Scope sidebar CSS to the direct sidebar element only.
- Prevent horizontal page overflow at mobile widths.

## Filters and selects

- Use the shared styled native `SelectControl` for every dropdown. It keeps reliable browser keyboard and touch behavior while providing one calm closed-control treatment, including label, chevron, focus ring, and touch-safe height.
- Do not create custom listboxes, hover-only dropdowns, page-specific menus, or JavaScript option overlays for operational controls.
- Keep the native value synchronized with Vue bindings. Filter changes apply immediately, while free-text filters use a 280 ms debounce.
- Movement history supports product search, movement type, and inclusive start/end dates. Empty results say that no movement matches the current criteria.
- Product lists show only the everyday controls by default: search, category, visibility, active/archive view, and sort. Stock state, product type, and promotion live behind `Plus de filtres`; opening it must not reset current filters.

## Bulk operations and archives

- Tables use a leading checkbox, a clear selected-count bar, and only actions that are valid for the entire current selection.
- Products use a three-level lifecycle: `Publié` appears in the storefront, `Masqué` remains editable in the active catalogue but is absent from the storefront, and a direct catalogue removal moves the product to `Archivé` immediately. An administrator does not need to archive first. The Archives view offers `Restaurer`; restored products remain masked until deliberately published.
- The direct product removal is a soft deletion and must be confirmed explicitly. It preserves order-item snapshots, inventory movements, and audit history. Permanent product deletion remains available only from the Archives view and only where the selected products have no order-item or inventory-movement history.
- Orders retain guided next-step actions for normal operation. Owners may also use an explicitly labelled manual status correction to choose any order status, individually or in bulk. Manual corrections are audited and intentionally do not alter stock or create/update Navex shipments; operational stock and delivery corrections remain separate workflows.
- Order “deletion” is archival, never destructive deletion. Archived orders are hidden from the active list, remain available through the Archives filter, and remain viewable with their history intact.
- Archive actions require an explicit consequence-aware confirmation. Success and ordinary updates use toasts; validation and authorization failures remain near their cause or in a page alert.

## Status and feedback

- Use semantic status colors consistently. Low stock and stock-out alerts are red and include a non-color warning marker.
- Status changes must use task-oriented labels and state the operational consequence, not expose raw enum names.
- Toasts sit above the mobile safe area and never inherit sidebar styling.

## Print and motion

- Order print output contains order information only. Hide navigation, buttons, controls, edit forms, filters, dialogs, toasts, and quick actions.
- Use only opacity and transform for UI motion, normally 150–220ms, with reduced-motion support.
