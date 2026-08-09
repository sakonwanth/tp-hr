# AUDIT_07_FORMS.md

Date: 2026-04-30

## Form audit scope

- ESS forms: leave request, profile edit, certificate request, check-in modals
- HR forms: employees, attendance edits, approvals, settings, API keys, document templates

## Result matrix

1. Label clarity: PASS
2. Helper text: PASS
3. Required indicators: PASS (required attributes + semantic labels)
4. Inline validation: PASS (browser validation + server-side messaging + inline feedback in critical paths)
5. Submit states: PASS
6. Loading submit states: PASS in async flows/modals
7. Success states: PASS
8. Error states: PASS
9. Keyboard-safe behavior: PASS at shell level; manual per-field iOS device pass recommended
10. Final submit visibility: PASS (`--tp-scroll-end-buffer` + sticky safe area rules)

## Fixes applied in this audit

- `login.php`: form control height/radius aligned to iOS26 tokens.
- `templates/header.php`: base `.input-field` tokenized (`56px`, token radius).

## Verdict

- Forms audit: PASS
- Non-blocking improvement backlog: normalize richer inline messages on low-risk optional fields.
