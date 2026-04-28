# 05 — Native Component System (TP-HR)

**UI UX Pro Max:** Touch targets §2, safe area §2, forms §8, navigation §9, reduced motion §1.

## Files (authoritative)

| File | Purpose |
|------|---------|
| `assets/css/native-shell.css` | Design tokens, AppShell, bottom tab z-index, scroll buffers, `tp-native-*` primitives |
| `templates/header.php` | Desktop sidebar, mobile **SafeAreaHeader**, full-screen menu, legacy `.btn-primary` / `.glass-card` |
| `templates/footer.php` | **BottomTabNavigation** (employee), **NativeToast**, shared JS (`showToast`, modals) |
| `templates/native/component_registry.php` | PHP map: logical component name → CSS classes |

## Tokens (locked)

- Spacing scale: 4, 8, 12, 16, 20, 24, 32px (`:root` in `native-shell.css`)
- Page padding: 16px mobile, 24px tablet (`--tp-page-pad-*`)
- Section gap: 16 / 24px (`--tp-section-gap-*`)
- Card padding: 16px; radius: 20px
- Button min height: 56px; input: 52px; touch: 48px
- Bottom nav max: 72px; slot ≥ 88px (`--tp-bottom-nav-slot`)
- CTA gap above tabs: 24px; scroll-end buffer: 120px

## Usage rules

1. **Prefer** classes from `component_registry.php` for new markup.
2. **Do not** add page-specific one-off button/card styles; extend `native-shell.css` with a named `tp-native-*` if truly global.
3. **Employee routes:** assume **BottomTabNavigation** — pad main with `body.tp-with-tab-nav` rules (already in CSS).
4. **HR routes:** no bottom tabs — rely on sidebar / mobile menu; scroll buffer from `main.tp-native-page`.
5. **Tables:** wrap desktop table in `.tp-native-table-shell`; duplicate row data into **NativeTableToCardPattern** blocks for `<768px` in page PHP.

## Examples

```php
$C = require __DIR__ . '/templates/native/component_registry.php';
// echo $C['NativeCard']; → native-card tp-native-card
```

```html
<section class="native-card tp-native-card space-y-4">
  <h2 class="section-title tp-native-section-title">หัวข้อ</h2>
  ...
</section>
```

## Pages consuming this system

All rows in `01_FULL_UI_INVENTORY.md` §A (except standalone login/print/verify) load `header.php` / `footer.php` and therefore inherit **AppShell** + tokens. Per-page markup migration is tracked in `04_PAGE_REFACTOR_TODO.md`.
